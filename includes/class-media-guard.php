<?php
/**
 * Guards image conversions that run in web requests.
 *
 * A proxy or gateway (nginx, Cloudflare, a host's load balancer) can give up
 * on a request with HTTP 502/504 while PHP keeps converting the image. This
 * class makes that case safe and visible:
 *  - one conversion per image at a time (a lock in wp_options, released on
 *    shutdown and treated as stale after the longest a PHP request may run);
 *  - a check before each image: already converted, still running, stopped
 *    half way, or likely to take longer than the server allows;
 *  - timings of finished conversions and the waits that ended in a gateway
 *    error, so the estimate fits this server.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

class Media_Guard {

	const LOCK_PREFIX = 'wpcu_media_lock_';
	const TIMING      = 'wpcu_media_timing';
	const GATEWAY     = 'wpcu_media_gateway_limit';

	/** Seconds one web request may use when no gateway limit was observed. */
	const DEFAULT_BUDGET = 50;

	/** Seconds per megapixel of work before this server has any timings. */
	const FALLBACK_RATE = 1.5;

	/** @var array<int,bool> Locks held by this request. */
	private static $held = array();

	/** @var bool */
	private static $shutdown = false;

	/**
	 * Take the conversion lock for one image.
	 *
	 * @param int    $id        Attachment id.
	 * @param string $backup_id Backup set that receives the old files.
	 * @return bool False while another request converts this image.
	 */
	public static function lock( $id, $backup_id ) {
		global $wpdb;
		$id   = (int) $id;
		$info = self::lock_info( $id );
		if ( $info && ! $info['stale'] ) {
			return false;
		}
		if ( $info ) {
			self::unlock( $id );
		}
		$value = wp_json_encode( array( 'since' => time(), 'backup' => (string) $backup_id ) );
		// INSERT IGNORE on the unique option_name makes taking the lock atomic.
		$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_PREFIX . $id, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic lock; never cached.
		if ( 1 !== (int) $taken ) {
			return false;
		}
		self::$held[ $id ] = true;
		if ( ! self::$shutdown ) {
			self::$shutdown = true;
			// Runs after fatal errors and max_execution_time too; only a killed worker leaves a lock behind.
			register_shutdown_function( array( __CLASS__, 'release_all' ) );
		}
		return true;
	}

	/** @param int $id Attachment id. */
	public static function unlock( $id ) {
		global $wpdb;
		$id = (int) $id;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK_PREFIX . $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Lock row; never cached.
		unset( self::$held[ $id ] );
	}

	public static function release_all() {
		foreach ( array_keys( self::$held ) as $id ) {
			self::unlock( $id );
		}
	}

	/**
	 * @param int $id Attachment id.
	 * @return array{since:int,backup:string,age:int,stale:bool}|null
	 */
	public static function lock_info( $id ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_PREFIX . (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Must read the current row, not a cached option.
		if ( null === $raw ) {
			return null;
		}
		$data  = json_decode( (string) $raw, true );
		$since = is_array( $data ) && isset( $data['since'] ) ? (int) $data['since'] : 0;
		$age   = max( 0, time() - $since );
		return array(
			'since'  => $since,
			'backup' => is_array( $data ) && isset( $data['backup'] ) ? (string) $data['backup'] : '',
			'age'    => $age,
			'stale'  => $age > self::stale_after(),
		);
	}

	/** Seconds after which a lock can only belong to a request that was killed. */
	public static function stale_after() {
		$max = (int) ini_get( 'max_execution_time' );
		// Conversion requests raise the limit to 300 s; with no limit at all, allow 15 minutes.
		$run = $max > 0 ? max( $max, 300 ) : 900;
		return (int) apply_filters( 'wpcu_media_lock_stale_after', $run + 120 );
	}

	/**
	 * Megapixels of work for one image: decoding the source plus writing the
	 * resized outputs (the JPEG fallback roughly doubles them).
	 *
	 * @param int        $id  Attachment id.
	 * @param array|null $inv Inventory entry, when already loaded.
	 * @return array{width:int,height:int,bytes:int,work:float}
	 */
	public static function work( $id, $inv = null ) {
		$inv = $inv ? $inv : Media_Inventory::attachment( $id );
		if ( ! $inv ) {
			return array( 'width' => 0, 'height' => 0, 'bytes' => 0, 'work' => 0.0 );
		}
		$s    = Media_Policy::settings();
		$info = Media_Files::describe( Media_Converter::source( $id, $inv ) );
		$mp   = $info['width'] * $info['height'] / 1000000;
		$long = max( $info['width'], $info['height'] );
		$out  = $long > $s['full_max'] && $long > 0 ? $mp * pow( $s['full_max'] / $long, 2 ) : $mp;
		return array(
			'width'  => $info['width'],
			'height' => $info['height'],
			'bytes'  => $info['bytes'],
			'work'   => round( $mp + $out * ( $s['jpeg_fallback'] ? 2 : 1 ), 3 ),
		);
	}

	/**
	 * Remember how long a finished conversion took.
	 *
	 * @param float $seconds Duration.
	 * @param float $work    Megapixels of work (see work()).
	 */
	public static function record( $seconds, $work ) {
		if ( $work <= 0.01 || $seconds <= 0 ) {
			return;
		}
		$list   = self::timings();
		$list[] = array( round( (float) $seconds, 2 ), round( (float) $work, 3 ) );
		update_option( self::TIMING, array_slice( $list, -20 ), false );
		// A request ran longer than the remembered gateway limit, so that 504 had another cause.
		$gateway = (int) get_option( self::GATEWAY, 0 );
		if ( $gateway && $seconds >= $gateway ) {
			delete_option( self::GATEWAY );
		}
	}

	/** @return array<int,array{0:float,1:float}> */
	public static function timings() {
		$list = get_option( self::TIMING, array() );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * The browser waited this long before the server answered with a gateway
	 * error. Keep the shortest wait as this server's request limit.
	 *
	 * @param int $seconds Wait in seconds.
	 */
	public static function gateway_failed( $seconds ) {
		$seconds = (int) $seconds;
		if ( $seconds < 10 ) {
			return; // An immediate 502/504 is not a time limit.
		}
		$known = (int) get_option( self::GATEWAY, 0 );
		if ( ! $known || $seconds < $known ) {
			update_option( self::GATEWAY, $seconds, false );
		}
	}

	/** Seconds one image may take in a web request. */
	public static function budget() {
		$gateway = (int) get_option( self::GATEWAY, 0 );
		$budget  = $gateway ? (int) floor( $gateway * 0.7 ) : self::DEFAULT_BUDGET;
		return max( 5, (int) apply_filters( 'wpcu_media_request_budget', $budget, $gateway ) );
	}

	/**
	 * @param float $work Megapixels of work.
	 * @return array{seconds:int,measured:bool}
	 */
	public static function estimate( $work ) {
		$rates = array();
		foreach ( self::timings() as $t ) {
			if ( isset( $t[0], $t[1] ) && $t[1] > 0.01 ) {
				$rates[] = $t[0] / $t[1];
			}
		}
		sort( $rates );
		$rate = $rates ? $rates[ (int) floor( count( $rates ) / 2 ) ] : self::FALLBACK_RATE;
		return array(
			'seconds'  => (int) ceil( 1 + $rate * $work ),
			'measured' => (bool) $rates,
		);
	}

	/**
	 * Check one image before converting it in a web request.
	 *
	 * States: ready, done (already follows the policy), skip, busy (another
	 * request is converting it), interrupted (an earlier request was killed
	 * part way) and risky (likely to exceed the time or memory limit).
	 *
	 * @param int  $id            Attachment id.
	 * @param bool $allow_slow    Convert even when the estimate exceeds the budget.
	 * @param bool $after_timeout The last request for this image ended without an answer.
	 * @param int  $gap           Selected-image size gap in pixels.
	 * @return array
	 */
	public static function preflight( $id, $allow_slow = false, $after_timeout = false, $gap = 0 ) {
		$id  = (int) $id;
		$out = array(
			'id'       => $id,
			'state'    => 'ready',
			'message'  => '',
			'estimate' => 0,
			'budget'   => self::budget(),
			'measured' => false,
			'issues'   => array(),
		);

		$lock = self::lock_info( $id );
		if ( $lock && ! $lock['stale'] ) {
			$out['state']   = 'busy';
			$out['backup']  = $lock['backup'];
			/* translators: %d: seconds */
			$out['message'] = sprintf( __( 'Still being converted by an earlier request (started %d s ago).', 'wp-cleanup' ), $lock['age'] );
			return $out;
		}
		if ( $lock || $after_timeout ) {
			// A killed worker runs neither the rollback nor the shutdown handler; a fatal error skips the rollback.
			if ( $lock ) {
				self::unlock( $id );
			}
			$check = Media_Integrity::check( $id );
			if ( $check['issues'] ) {
				$out['state']   = 'interrupted';
				$out['backup']  = $lock ? $lock['backup'] : '';
				$out['issues']  = wp_list_pluck( $check['issues'], 'message' );
				$out['message'] = __( 'An earlier conversion was stopped by the server part way, and the image data no longer matches its files. Check the library and use Repair, or restore the image from its backup set on the Backups tab.', 'wp-cleanup' ) . ' ' . implode( ' ', $out['issues'] );
				return $out;
			}
		}

		$reason = Media_Policy::skip_reason( $id );
		if ( $reason ) {
			$out['state']   = 'skip';
			$out['message'] = $reason;
			return $out;
		}
		Media_Inventory::flush();
		$inv = Media_Inventory::attachment( $id );
		if ( ! $inv ) {
			$out['state']   = 'skip';
			$out['message'] = __( 'The file is missing.', 'wp-cleanup' );
			return $out;
		}
		if ( $inv['compliant'] ) {
			if ( Media_Converter::can_trim_small( $id, 8192, $inv )
				&& ! Media_Converter::can_trim_small( $id, $gap, $inv ) ) {
				$out['state'] = 'skip';
				$out['message'] = __( 'The full and small AVIFs differ by more than the selected gap; nothing changed.', 'wp-cleanup' );
				return $out;
			}
			if ( Media_Converter::can_trim_small( $id, $gap, $inv ) ) {
				$out['message'] = __( 'The selected size gap allows this small AVIF to be backed up while the full AVIF and any JPEG fallback stay.', 'wp-cleanup' );
				return $out;
			}
			$out['state']   = 'done';
			$out['message'] = __( 'Already follows the image policy.', 'wp-cleanup' );
			return $out;
		}

		$work             = self::work( $id, $inv );
		$guess            = self::estimate( $work['work'] );
		$out['estimate']  = $guess['seconds'];
		$out['measured']  = $guess['measured'];
		$out['width']     = $work['width'];
		$out['height']    = $work['height'];
		$out['bytes']     = $work['bytes'];

		$memory = self::memory_shortfall( $id, $work['width'], $work['height'] );
		if ( $memory && ! $allow_slow ) {
			$out['state']   = 'risky';
			$out['message'] = $memory;
			return $out;
		}
		if ( $guess['seconds'] > $out['budget'] && ! $allow_slow ) {
			$out['state']   = 'risky';
			$out['message'] = sprintf(
				/* translators: 1: width, 2: height, 3: estimated seconds, 4: allowed seconds, 5: WP-CLI command */
				__( 'Not converted: %1$d×%2$d px may take about %3$d s, longer than this server allows for one request (about %4$d s). Convert it with WP-CLI (%5$s) or tick "Also try images that may time out".', 'wp-cleanup' ),
				$work['width'],
				$work['height'],
				$guess['seconds'],
				$out['budget'],
				'wp cleanup images convert --ids=' . $id
			);
		}
		return $out;
	}

	/**
	 * GD decodes the whole image into PHP memory; Imagick uses its own.
	 *
	 * @return string Message when the image likely does not fit, else ''.
	 */
	private static function memory_shortfall( $id, $width, $height ) {
		if ( ! $width || ! $height || ! function_exists( '_wp_image_editor_choose' ) ) {
			return '';
		}
		$editor = _wp_image_editor_choose( array( 'path' => (string) get_attached_file( $id ), 'methods' => array( 'resize', 'save' ) ) );
		if ( 'WP_Image_Editor_GD' !== $editor ) {
			return '';
		}
		$limit = wp_convert_hr_to_bytes( (string) apply_filters( 'image_memory_limit', defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '256M' ) );
		if ( $limit <= 0 ) {
			return ''; // Unlimited.
		}
		// The decoded source plus a resized copy, at about 5 bytes per pixel each.
		$need  = (int) ( $width * $height * 5 * 1.6 );
		$avail = $limit - memory_get_usage( true );
		if ( $need <= $avail ) {
			return '';
		}
		return sprintf(
			/* translators: 1: width, 2: height, 3: needed memory, 4: available memory */
			__( 'Not converted: %1$d×%2$d px needs about %3$s of PHP memory with the GD image library, but only about %4$s is available. Raise WP_MAX_MEMORY_LIMIT or install Imagick.', 'wp-cleanup' ),
			$width,
			$height,
			size_format( $need, 0 ),
			size_format( max( 0, $avail ), 0 )
		);
	}
}

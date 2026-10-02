<?php
/**
 * Tools → WP Cleanup screen.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const SLUG       = 'wp-cleanup';
	const CAPABILITY = 'manage_options';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_wpcu_scan', array( $this, 'handle_scan' ) );
		add_action( 'admin_post_wpcu_clean', array( $this, 'handle_clean' ) );
		add_action( 'admin_post_wpcu_restore', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_wpcu_backup_download', array( $this, 'handle_backup_download' ) );
		add_action( 'admin_post_wpcu_delete_backup', array( $this, 'handle_delete_backup' ) );
		add_action( 'admin_post_wpcu_media_settings', array( $this, 'handle_media_settings' ) );
		add_action( 'admin_post_wpcu_media_scan', array( $this, 'handle_media_scan' ) );
		add_action( 'admin_post_wpcu_media_repair', array( $this, 'handle_media_repair' ) );
		add_action( 'admin_post_wpcu_media_rescan', array( $this, 'handle_media_rescan' ) );
		add_action( 'admin_post_wpcu_media_remove', array( $this, 'handle_media_remove' ) );
		add_action( 'admin_post_wpcu_media_merge', array( $this, 'handle_media_merge' ) );
		add_action( 'admin_post_wpcu_media_file_remove', array( $this, 'handle_media_file_remove' ) );
		add_action( 'wp_ajax_wpcu_media_batch', array( $this, 'ajax_media_batch' ) );
		add_action( 'wp_ajax_wpcu_media_check', array( $this, 'ajax_media_check' ) );
		add_action( 'wp_ajax_wpcu_media_remove_step', array( $this, 'ajax_media_remove_step' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPCU_FILE ), array( $this, 'action_links' ) );
	}

	public function menu() {
		$hook = add_management_page(
			__( 'WP Cleanup', 'wp-cleanup' ),
			__( 'WP Cleanup', 'wp-cleanup' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
		add_action( 'admin_print_styles-' . $hook, array( $this, 'assets' ) );
	}

	public function assets() {
		wp_enqueue_style( 'wp-cleanup', WPCU_URL . 'assets/admin.css', array(), WPCU_VERSION );
		wp_enqueue_script( 'wp-cleanup', WPCU_URL . 'assets/admin.js', array(), WPCU_VERSION, true );
		wp_localize_script(
			'wp-cleanup',
			'wpCleanup',
			array(
				'confirmDelete'  => __( "Delete %d selected item(s)?\n\nA restorable backup is written first, but take a full site backup before cleaning a live site.", 'wp-cleanup' ),
				'confirmRestore' => __( 'Restore everything deleted in this backup set?', 'wp-cleanup' ),
				'confirmPurge'   => __( 'Permanently delete this backup set? This cannot be undone.', 'wp-cleanup' ),
				'nothing'        => __( 'Select at least one item.', 'wp-cleanup' ),
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'mediaNonce'     => wp_create_nonce( 'wpcu_media_batch' ),
				'removeNonce'    => wp_create_nonce( 'wpcu_media_remove' ),
				'removeDone'     => __( 'Finished: %1$d of %2$d moved to backup set %3$s. Review any refused items below, then check the Backups tab.', 'wp-cleanup' ),
				'removeStopped'  => __( 'Stopped after the current item.', 'wp-cleanup' ),
				'removeInterrupted' => __( 'The server response was lost. Processing stopped so this item is not retried blindly. Check the Backups tab and rescan before continuing.', 'wp-cleanup' ),
				'mediaPolicyHash' => md5( wp_json_encode( Media_Policy::settings() ) ),
				'mediaPolicyUnsaved' => __( 'Save the image policy before converting. Your changes are not active yet.', 'wp-cleanup' ),
				'mediaPolicyChanged' => __( 'The saved image policy changed. Reload this page before converting.', 'wp-cleanup' ),
				'confirmMerge'   => __( "Replace known WordPress references to image #%1$d with image #%2$d?\n\nThe image on this card and its files will leave the Media Library and go to a restorable backup. The selected image stays.\n\nCrops or dimensions may display differently. Confirm you have a recent full site backup.", 'wp-cleanup' ),
				'confirmMedia'   => __( "Convert %d image(s) with the current image policy?\n\nEvery other size and the original are moved into a backup set. Take a full backup of files and database first.", 'wp-cleanup' ),
				'confirmGap' => __( "Apply the size gap to %d selected image(s)?\n\nMatching existing small AVIFs move to a restorable backup; unconverted images are converted without an unnecessary small AVIF. Take a full backup of files and database first.", 'wp-cleanup' ),
				'mediaDone'      => __( 'Finished: %1$d updated, %2$d failed, %3$d skipped. Old files are in backup set %4$s. Delete it on the Backups tab once the site looks right, to free the space.', 'wp-cleanup' ),
				'mediaBadResponse' => __( 'The server returned an invalid response (HTTP %d). The current image may have completed; check the library before retrying.', 'wp-cleanup' ),
				/* translators: 1: HTTP status, 2: seconds, 3: attachment id */
				'mediaTimedOut' => __( 'The server did not answer in time (HTTP %1$d after %2$d s). Checking whether image #%3$d finished on the server…', 'wp-cleanup' ),
				/* translators: 1: attachment id, 2: seconds */
				'mediaWaiting' => __( 'Image #%1$d is still being converted on the server (%2$d s). Waiting…', 'wp-cleanup' ),
				'mediaFinishedLate' => __( 'Converted. The server finished after the connection timed out, so file details are not shown here; see the backup set on the Backups tab.', 'wp-cleanup' ),
				'mediaNotFinished' => __( 'Not converted: the server stopped before finishing. The image data still matches its files, so it can be converted again. Partly written new files, if any, show up under files without an attachment.', 'wp-cleanup' ),
				/* translators: 1: number of images, 2: WP-CLI command */
				'mediaDeferred' => __( '%1$d image(s) were not converted because they may take longer than this server allows for one request. Convert them with WP-CLI (%2$s) or tick "Also try images that may time out".', 'wp-cleanup' ),
				'mediaGiveUp' => __( 'The server is still busy with this image after a long wait. Stopped; check the library and the Backups tab before retrying.', 'wp-cleanup' ),
				'mediaLocation' => __( 'Location', 'wp-cleanup' ),
				'mediaOriginal' => __( 'Original', 'wp-cleanup' ),
				'mediaNew' => __( 'New', 'wp-cleanup' ),
				'mediaReferences' => __( '%d stored path entries (including revisions)', 'wp-cleanup' ),
				'mediaOriginals' => __( '%d original files moved to backup', 'wp-cleanup' ),
				'mediaCreated' => __( '%d new files', 'wp-cleanup' ),
				'mediaStopped'   => __( 'Stopped.', 'wp-cleanup' ),
				'mediaConfirmBox' => __( 'Please tick the backup confirmation first.', 'wp-cleanup' ),
				'confirmRemove' => __( 'Move %d unused image(s) and their attachment records into a restorable backup set? Check that none are used from CSS, theme files, external sites or plugin tables.', 'wp-cleanup' ),
				'confirmServerFiles' => __( 'Move %d unregistered server file(s) into a restorable backup set? Check theme code, CSS and external links first.', 'wp-cleanup' ),
			)
		);
	}

	/**
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open', 'wp-cleanup' ) . '</a>' );
		return $links;
	}

	/**
	 * @param array $args Query args.
	 */
	private static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Handlers                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Require the administrative capability before any handler reads input.
	 */
	private function guard() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-cleanup' ), 403 );
		}
	}

	/**
	 * @param string $type    success|error|warning|info.
	 * @param string $message Plain text or limited HTML.
	 * @param array  $details Lines.
	 */
	private function notice( $type, $message, array $details = array() ) {
		set_transient(
			'wpcu_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
				'details' => array_slice( $details, 0, 200 ),
			),
			300
		);
	}

	/**
	 * @param array $args Query args.
	 */
	private function back( array $args = array() ) {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	public function handle_scan() {
		$this->guard();
		check_admin_referer( 'wpcu_scan' );
		try {
			$result = ( new Scanner( ! empty( $_POST['fresh'] ) ) )->scan_and_store();
			$this->notice(
				'success',
				/* translators: 1: items, 2: seconds */
				sprintf( __( 'Scan complete: %1$d items checked in %2$s s.', 'wp-cleanup' ), count( $result['items'] ), $result['duration'] )
			);
		} catch ( \Exception $e ) {
			$this->notice( 'error', $e->getMessage() );
		}
		$this->back( isset( $_POST['tab'] ) ? array( 'tab' => sanitize_key( wp_unslash( $_POST['tab'] ) ) ) : array() );
	}

	public function handle_clean() {
		$this->guard();
		check_admin_referer( 'wpcu_clean' );
		$tab   = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		$items = isset( $_POST['items'] ) ? array_map( 'wp_unslash', (array) $_POST['items'] ) : array(); // phpcs:ignore -- keys are validated against a fresh scan.
		$items = array_filter( array_map( 'strval', $items ) );
		if ( ! $items ) {
			$this->notice( 'warning', __( 'Nothing selected.', 'wp-cleanup' ) );
			$this->back( array( 'tab' => $tab ) );
		}
		if ( empty( $_POST['confirm'] ) ) {
			$this->notice( 'warning', __( 'Please tick the confirmation box first.', 'wp-cleanup' ) );
			$this->back( array( 'tab' => $tab ) );
		}

		try {
			$report = ( new Cleaner() )->clean(
				$items,
				array(
					'allow_unknown'  => ! empty( $_POST['allow_unknown'] ),
					'allow_inactive' => ! empty( $_POST['allow_inactive'] ),
				)
			);
		} catch ( \Exception $e ) {
			$this->notice( 'error', $e->getMessage() );
			$this->back( array( 'tab' => $tab ) );
		}

		$details = array();
		foreach ( $report['failed'] as $row ) {
			$details[] = '✗ ' . $row['label'] . ': ' . $row['message'];
		}
		foreach ( $report['refused'] as $row ) {
			$details[] = '⊘ ' . $row['label'] . ': ' . $row['message'];
		}
		$type = $report['failed'] ? 'error' : ( $report['refused'] ? 'warning' : 'success' );
		$this->notice(
			$type,
			sprintf(
				/* translators: 1: deleted, 2: refused, 3: failed, 4: backup id */
				__( 'Deleted %1$d item(s), refused %2$d, failed %3$d. Backup set: %4$s', 'wp-cleanup' ),
				count( $report['deleted'] ),
				count( $report['refused'] ),
				count( $report['failed'] ),
				$report['backup'] ? $report['backup'] : '—'
			),
			$details
		);
		$this->back( array( 'tab' => $tab ) );
	}

	public function handle_restore() {
		$this->guard();
		$single = isset( $_POST['item'] );
		check_admin_referer( $single ? 'wpcu_restore_item' : 'wpcu_restore' );
		$backup = Backup::open( isset( $_POST['backup'] ) ? sanitize_text_field( wp_unslash( $_POST['backup'] ) ) : '' );
		if ( ! $backup ) {
			$this->notice( 'error', __( 'Backup set not found.', 'wp-cleanup' ) );
			$this->back( array( 'tab' => 'backups' ) );
		}
		if ( $single ) {
			$raw_item = is_scalar( $_POST['item'] ) ? sanitize_text_field( wp_unslash( $_POST['item'] ) ) : '';
			if ( ! is_scalar( $raw_item ) || ! ctype_digit( (string) $raw_item ) || ! isset( $backup->manifest['items'][ (int) $raw_item ] ) ) {
				$this->notice( 'error', __( 'Backup item not found.', 'wp-cleanup' ) );
				$this->back( array( 'tab' => 'backups' ) );
			}
			$report = $backup->restore( (int) $raw_item );
		} else {
			$report = $backup->restore();
		}
		try {
			( new Scanner() )->scan_and_store(); // Keep the lists in step with what came back.
			if ( array_intersect( array( 'media', 'media_delete' ), wp_list_pluck( $backup->manifest['items'], 'type' ) ) && Media_Report::last() ) {
				Media_Report::build_and_store();
			}
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
			// The restore itself succeeded; a stale list is acceptable.
		}
		$failed = array_filter(
			$report,
			static function ( $r ) {
				return ! $r['ok'];
			}
		);
		$details = array();
		foreach ( $report as $row ) {
			$details[] = ( $row['ok'] ? '✓ ' : '✗ ' ) . $row['label'] . ': ' . $row['message'];
		}
		$this->notice(
			$failed ? 'warning' : 'success',
			/* translators: 1: restored, 2: failed */
			sprintf( __( 'Restored %1$d item(s), %2$d failed.', 'wp-cleanup' ), count( $report ) - count( $failed ), count( $failed ) ),
			$details
		);
		$this->back( array( 'tab' => 'backups' ) );
	}

	/** Download one original file without changing the live attachment or its backup. */
	public function handle_backup_download() {
		$this->guard();
		$bid = isset( $_GET['backup'] ) ? sanitize_text_field( wp_unslash( $_GET['backup'] ) ) : '';
		$raw_item = isset( $_GET['item'] ) && is_scalar( $_GET['item'] ) ? sanitize_text_field( wp_unslash( $_GET['item'] ) ) : '';
		$raw_file = isset( $_GET['file'] ) && is_scalar( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
		check_admin_referer( 'wpcu_backup_download_' . $bid );
		$backup = Backup::open( $bid );
		if ( ! $backup || ! is_scalar( $raw_item ) || ! ctype_digit( (string) $raw_item ) || ! is_scalar( $raw_file ) || ! ctype_digit( (string) $raw_file ) ) {
			wp_die( esc_html__( 'Backup file not found.', 'wp-cleanup' ), '', array( 'response' => 404 ) );
		}
		$n = (int) $raw_item;
		$index = (int) $raw_file;
		$item = isset( $backup->manifest['items'][ $n ] ) ? $backup->manifest['items'][ $n ] : null;
		$rel = is_array( $item ) && isset( $item['extra']['moved'][ $index ] ) ? $item['extra']['moved'][ $index ] : '';
		$root = realpath( $backup->dir . '/files/' . $n . '/orig' );
		$file = is_string( $rel ) && $root && ! preg_match( '#(^/|(^|/)\.\.(/|$)|\\\\|:)#', $rel ) ? realpath( $root . '/' . $rel ) : false;
		if ( ! $file || 0 !== strpos( wp_normalize_path( $file ), wp_normalize_path( $root ) . '/' ) || ! is_file( $file ) ) {
			wp_die( esc_html__( 'Backup file not found.', 'wp-cleanup' ), '', array( 'response' => 404 ) );
		}
		$size = filesize( $file );
		$mime = wp_check_filetype( $file )['type'];
		nocache_headers();
		header( 'Content-Type: ' . ( $mime ? $mime : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', wp_basename( $file ) ) . '"' );
		header( 'Content-Length: ' . (int) $size );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Protected backup download.
		exit;
	}

	public function handle_delete_backup() {
		$this->guard();
		check_admin_referer( 'wpcu_delete_backup' );
		$backup = Backup::open( isset( $_POST['backup'] ) ? sanitize_text_field( wp_unslash( $_POST['backup'] ) ) : '' );
		if ( $backup && $backup->delete() ) {
			$this->notice( 'success', __( 'Backup set deleted.', 'wp-cleanup' ) );
		} else {
			$this->notice( 'error', __( 'Could not delete the backup set.', 'wp-cleanup' ) );
		}
		$this->back( array( 'tab' => 'backups' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$scan   = Scanner::last();
		$types  = Plugin::type_labels();
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs   = array_merge( array( 'overview' => __( 'Overview', 'wp-cleanup' ) ), $types, array( 'images' => __( 'Images', 'wp-cleanup' ), 'backups' => __( 'Backups', 'wp-cleanup' ) ) );
		$tab    = isset( $tabs[ $tab ] ) ? $tab : 'overview';
		$counts = $scan ? $this->counts( $scan['items'] ) : array();

		echo '<div class="wrap wpcu">';
		echo '<h1>' . esc_html__( 'WP Cleanup', 'wp-cleanup' ) . '</h1>';
		$this->render_notice();

		echo '<div class="wpcu-toolbar">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpcu_scan' );
		echo '<input type="hidden" name="action" value="wpcu_scan"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		submit_button( $scan ? __( 'Scan again', 'wp-cleanup' ) : __( 'Scan now', 'wp-cleanup' ), 'primary', 'submit', false );
		echo ' <label class="wpcu-inline"><input type="checkbox" name="fresh" value="1"> ' . esc_html__( 'Re-read all plugin/theme code', 'wp-cleanup' ) . '</label>';
		echo '</form>';
		if ( $scan ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: 1: date, 2: items, 3: sources, 4: seconds */
					__( 'Last scan %1$s · %2$d items · %3$d code sources indexed · %4$s s', 'wp-cleanup' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $scan['created'] ),
					count( $scan['items'] ),
					isset( $scan['sources'] ) ? $scan['sources'] : 0,
					$scan['duration']
				)
			) . '</p>';
			if ( ! empty( $scan['incomplete'] ) ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html(
					sprintf(
						/* translators: %s: plugin/theme names */
						__( 'Some installed code could not be fully checked (%s). Until it can, nothing is marked "orphaned"; possible leftovers are shown as "unknown owner" instead.', 'wp-cleanup' ),
						implode( ', ', $scan['incomplete'] )
					)
				) . '</p></div>';
			}
		}
		echo '</div>';

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$badge = '';
			if ( isset( $counts[ $key ] ) && $counts[ $key ]['cleanable'] ) {
				$badge = ' <span class="wpcu-count">' . (int) $counts[ $key ]['cleanable'] . '</span>';
			}
			printf(
				'<a href="%s" class="nav-tab%s">%s%s</a>',
				esc_url( self::url( array( 'tab' => $key ) ) ),
				$key === $tab ? ' nav-tab-active' : '',
				esc_html( $label ),
				$badge // phpcs:ignore -- built above from ints.
			);
		}
		echo '</nav>';

		if ( 'images' === $tab ) {
			$this->render_images();
		} elseif ( 'backups' === $tab ) {
			$this->render_backups();
		} elseif ( ! $scan ) {
			echo '<div class="wpcu-empty"><p>' . esc_html__( 'Run a scan to find data left behind by removed plugins and themes. Scanning only reads; nothing is changed until you choose to delete.', 'wp-cleanup' ) . '</p></div>';
		} elseif ( 'overview' === $tab ) {
			$this->render_overview( $scan, $counts );
		} else {
			$this->render_items( $tab, $scan['items'] );
		}
		echo '</div>';
	}

	private function render_notice() {
		$key    = 'wpcu_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
		if ( $notice['details'] ) {
			echo '<details><summary>' . esc_html__( 'Details', 'wp-cleanup' ) . '</summary><ul class="wpcu-details">';
			foreach ( $notice['details'] as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul></details>';
		}
		echo '</div>';
	}

	/**
	 * @param array $items Items.
	 * @return array<string,array> Per type: status => n, cleanable, bytes, autoload_bytes.
	 */
	private function counts( array $items ) {
		$out = array();
		foreach ( $items as $item ) {
			$t = $item['type'];
			if ( ! isset( $out[ $t ] ) ) {
				$out[ $t ] = array( 'cleanable' => 0, 'bytes' => 0, 'autoload_bytes' => 0, 'status' => array() );
			}
			$out[ $t ]['status'][ $item['status'] ] = ( isset( $out[ $t ]['status'][ $item['status'] ] ) ? $out[ $t ]['status'][ $item['status'] ] : 0 ) + 1;
			if ( in_array( $item['status'], array( Plugin::STATUS_ORPHANED, Plugin::STATUS_SAFE, Plugin::STATUS_UNKNOWN, Plugin::STATUS_INACTIVE ), true ) ) {
				++$out[ $t ]['cleanable'];
				$out[ $t ]['bytes'] += (int) $item['bytes'];
				if ( ! empty( $item['autoload'] ) ) {
					$out[ $t ]['autoload_bytes'] += (int) $item['bytes'];
				}
			}
		}
		return $out;
	}

	/**
	 * @param array $scan   Scan.
	 * @param array $counts Counts.
	 */
	private function render_overview( array $scan, array $counts ) {
		$types    = Plugin::type_labels();
		$statuses = Plugin::status_labels();
		$autoload = 0;
		$bytes    = 0;
		foreach ( $counts as $c ) {
			$autoload += $c['autoload_bytes'];
			$bytes    += $c['bytes'];
		}

		echo '<div class="wpcu-cards">';
		$this->card( __( 'Candidate leftovers', 'wp-cleanup' ), number_format_i18n( array_sum( wp_list_pluck( $counts, 'cleanable' ) ) ) );
		$this->card( __( 'Their size', 'wp-cleanup' ), size_format( $bytes, 1 ) ? size_format( $bytes, 1 ) : '0 B' );
		$this->card( __( 'Autoloaded on every page', 'wp-cleanup' ), size_format( $autoload, 1 ) ? size_format( $autoload, 1 ) : '0 B', __( 'Leftover options loaded into memory on each request.', 'wp-cleanup' ) );
		echo '</div>';

		echo '<table class="widefat striped wpcu-summary"><thead><tr><th>' . esc_html__( 'Type', 'wp-cleanup' ) . '</th>';
		foreach ( array( Plugin::STATUS_ORPHANED, Plugin::STATUS_SAFE, Plugin::STATUS_UNKNOWN, Plugin::STATUS_INACTIVE, Plugin::STATUS_IN_USE, Plugin::STATUS_CORE ) as $s ) {
			echo '<th><span class="wpcu-badge wpcu-' . esc_attr( $s ) . '">' . esc_html( $statuses[ $s ] ) . '</span></th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $types as $type => $label ) {
			echo '<tr><td><a href="' . esc_url( self::url( array( 'tab' => $type ) ) ) . '">' . esc_html( $label ) . '</a></td>';
			foreach ( array( Plugin::STATUS_ORPHANED, Plugin::STATUS_SAFE, Plugin::STATUS_UNKNOWN, Plugin::STATUS_INACTIVE, Plugin::STATUS_IN_USE, Plugin::STATUS_CORE ) as $s ) {
				$n = isset( $counts[ $type ]['status'][ $s ] ) ? $counts[ $type ]['status'][ $s ] : 0;
				echo '<td>' . ( $n ? esc_html( number_format_i18n( $n ) ) : '<span class="wpcu-muted">–</span>' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<div class="wpcu-legend"><h2>' . esc_html__( 'What the statuses mean', 'wp-cleanup' ) . '</h2><dl>';
		$help = array(
			Plugin::STATUS_ORPHANED => __( 'Matches a known plugin or theme that is not installed, and no installed code mentions it. Cleanable.', 'wp-cleanup' ),
			Plugin::STATUS_SAFE     => __( 'Structural junk such as meta rows whose post was deleted, or expired transients. Cleanable.', 'wp-cleanup' ),
			Plugin::STATUS_UNKNOWN  => __( 'No installed code mentions it and the owner is not recognised. Usually left over from removed software; review before deleting.', 'wp-cleanup' ),
			Plugin::STATUS_INACTIVE => __( 'Belongs to a plugin or theme that is installed but inactive. Delete that plugin first, or opt in explicitly.', 'wp-cleanup' ),
			Plugin::STATUS_IN_USE   => __( 'Referenced by active code. Never deleted.', 'wp-cleanup' ),
			Plugin::STATUS_CORE     => __( 'Part of WordPress, or of another install sharing this database. Never deleted.', 'wp-cleanup' ),
		);
		foreach ( $help as $s => $text ) {
			echo '<dt><span class="wpcu-badge wpcu-' . esc_attr( $s ) . '">' . esc_html( $statuses[ $s ] ) . '</span></dt><dd>' . esc_html( $text ) . '</dd>';
		}
		echo '</dl></div>';
	}

	/**
	 * @param string $title Title.
	 * @param string $value Value.
	 * @param string $hint  Hint.
	 */
	private function card( $title, $value, $hint = '' ) {
		echo '<div class="wpcu-card"><div class="wpcu-card-title">' . esc_html( $title ) . '</div><div class="wpcu-card-value">' . esc_html( $value ) . '</div>';
		if ( $hint ) {
			echo '<div class="wpcu-card-hint">' . esc_html( $hint ) . '</div>';
		}
		echo '</div>';
	}

	/**
	 * @param string $type  Item type.
	 * @param array  $items All items.
	 */
	private function render_items( $type, array $items ) {
		$statuses = Plugin::status_labels();
		$rows     = array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $type ) {
					return $item['type'] === $type;
				}
			)
		);
		$order    = array_flip( array( Plugin::STATUS_ORPHANED, Plugin::STATUS_SAFE, Plugin::STATUS_UNKNOWN, Plugin::STATUS_INACTIVE, Plugin::STATUS_IN_USE, Plugin::STATUS_CORE ) );
		usort(
			$rows,
			static function ( $a, $b ) use ( $order ) {
				return array( $order[ $a['status'] ], $a['owner'], $b['bytes'], $a['label'] ) <=> array( $order[ $b['status'] ], $b['owner'], $a['bytes'], $b['label'] );
			}
		);

		$show_autoload = in_array( $type, array( 'option', 'transient' ), true );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpcu-clean-form">';
		wp_nonce_field( 'wpcu_clean' );
		echo '<input type="hidden" name="action" value="wpcu_clean"><input type="hidden" name="tab" value="' . esc_attr( $type ) . '">';

		echo '<div class="wpcu-filters">';
		echo '<label>' . esc_html__( 'Show', 'wp-cleanup' ) . ' <select class="wpcu-filter-status">';
		echo '<option value="cleanable">' . esc_html__( 'Cleanable (orphaned, safe, unknown, inactive)', 'wp-cleanup' ) . '</option>';
		foreach ( $statuses as $s => $label ) {
			echo '<option value="' . esc_attr( $s ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '<option value="all">' . esc_html__( 'Everything', 'wp-cleanup' ) . '</option></select></label> ';
		echo '<input type="search" class="wpcu-filter-search" placeholder="' . esc_attr__( 'Filter by name or owner…', 'wp-cleanup' ) . '"> ';
		echo '<span class="wpcu-visible-count"></span>';
		echo '</div>';

		echo '<table class="widefat striped wpcu-items"><thead><tr>';
		echo '<td class="check-column"><input type="checkbox" class="wpcu-check-all" aria-label="' . esc_attr__( 'Select all visible', 'wp-cleanup' ) . '"></td>';
		echo '<th>' . esc_html__( 'Name', 'wp-cleanup' ) . '</th><th>' . esc_html__( 'Status', 'wp-cleanup' ) . '</th><th>' . esc_html__( 'Owner', 'wp-cleanup' ) . '</th>';
		echo '<th class="num">' . esc_html( 'file' === $type ? __( 'Files', 'wp-cleanup' ) : __( 'Rows', 'wp-cleanup' ) ) . '</th><th class="num">' . esc_html__( 'Size', 'wp-cleanup' ) . '</th>';
		if ( $show_autoload ) {
			echo '<th>' . esc_html__( 'Autoload', 'wp-cleanup' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Why', 'wp-cleanup' ) . '</th></tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="8">' . esc_html__( 'Nothing found.', 'wp-cleanup' ) . '</td></tr>';
		}
		foreach ( $rows as $item ) {
			$locked = in_array( $item['status'], array( Plugin::STATUS_CORE, Plugin::STATUS_IN_USE ), true );
			$key    = $item['type'] . '|' . $item['id'];
			$label  = $item['label'];
			if ( 'meta' === $type ) {
				$label = $item['meta_type'] . ': ' . $item['label'];
			}
			printf(
				'<tr data-status="%s" data-search="%s">',
				esc_attr( $item['status'] ),
				esc_attr( strtolower( $label . ' ' . $item['owner'] . ' ' . $item['group'] ) )
			);
			echo '<th scope="row" class="check-column">';
			if ( ! $locked ) {
				echo '<input type="checkbox" name="items[]" value="' . esc_attr( $key ) . '" data-status="' . esc_attr( $item['status'] ) . '">';
			}
			echo '</th>';
			echo '<td class="wpcu-name"><code>' . esc_html( $label ) . '</code></td>';
			echo '<td><span class="wpcu-badge wpcu-' . esc_attr( $item['status'] ) . '">' . esc_html( $statuses[ $item['status'] ] ) . '</span>';
			if ( in_array( $item['status'], array( Plugin::STATUS_ORPHANED, Plugin::STATUS_UNKNOWN ), true ) ) {
				echo ' <span class="wpcu-confidence wpcu-conf-' . esc_attr( $item['confidence'] ) . '" title="' . esc_attr__( 'Confidence', 'wp-cleanup' ) . '">' . esc_html( $item['confidence'] ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . ( $item['owner'] ? esc_html( $item['owner'] ) : ( $item['group'] ? '<span class="wpcu-muted">' . esc_html( $item['group'] . '*' ) . '</span>' : '' ) ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( (int) $item['count'] ) . ( ! empty( $item['capped'] ) ? '+' : '' ) ) . '</td>';
			echo '<td class="num">' . esc_html( $item['bytes'] ? size_format( $item['bytes'], 1 ) : '–' ) . '</td>';
			if ( $show_autoload ) {
				echo '<td>' . ( $item['autoload'] ? '<span class="wpcu-autoload">' . esc_html__( 'yes', 'wp-cleanup' ) . '</span>' : '<span class="wpcu-muted">' . esc_html__( 'no', 'wp-cleanup' ) . '</span>' ) . '</td>';
			}
			echo '<td class="wpcu-reason">' . esc_html( $item['reason'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<div class="wpcu-actions">';
		echo '<label><input type="checkbox" name="allow_unknown" value="1" class="wpcu-allow" data-status="unknown"> ' . esc_html__( 'I reviewed the selected "unknown owner" items', 'wp-cleanup' ) . '</label>';
		echo '<label><input type="checkbox" name="allow_inactive" value="1" class="wpcu-allow" data-status="inactive"> ' . esc_html__( 'Also delete data of installed-but-inactive plugins/themes', 'wp-cleanup' ) . '</label>';
		echo '<label><input type="checkbox" name="confirm" value="1" required> <strong>' . esc_html__( 'I have a recent full backup of this site', 'wp-cleanup' ) . '</strong></label>';
		echo '<p>';
		submit_button( __( 'Back up & delete selected', 'wp-cleanup' ), 'primary wpcu-delete', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'Selections are re-checked against a fresh scan before anything is deleted.', 'wp-cleanup' ) . '</span>';
		if ( 'file' === $type ) {
			echo ' <span class="description">' . esc_html__( 'Folders are moved into the backup set, not deleted, until you delete that backup set.', 'wp-cleanup' ) . '</span>';
		}
		if ( 'table' === $type ) {
			echo ' <span class="description">' . esc_html__( 'Large tables are copied into the backup first, which can take a while; WP-CLI is better for very large ones.', 'wp-cleanup' ) . '</span>';
		}
		echo '</p></div>';
		echo '</form>';
	}

	/* ------------------------------------------------------------------ */
	/* Images                                                              */
	/* ------------------------------------------------------------------ */

	public function handle_media_settings() {
		$this->guard();
		check_admin_referer( 'wpcu_media_settings' );
		Media_Policy::save(
			array(
				'full_max'   => isset( $_POST['full_max'] ) ? absint( $_POST['full_max'] ) : 0,
				'small_max'  => isset( $_POST['small_max'] ) ? absint( $_POST['small_max'] ) : 0,
				'jpeg_max'   => isset( $_POST['jpeg_max'] ) ? absint( $_POST['jpeg_max'] ) : 0,
				'jpeg_quality' => isset( $_POST['jpeg_quality'] ) ? absint( $_POST['jpeg_quality'] ) : 0,
				'jpeg_fallback' => ! empty( $_POST['jpeg_fallback'] ),
				'small_name' => isset( $_POST['small_name'] ) ? sanitize_key( wp_unslash( $_POST['small_name'] ) ) : '',
				'set_flag'   => ! empty( $_POST['set_flag'] ),
			)
		);
		$this->notice( 'success', __( 'Image policy saved. Check the library again to see its effect.', 'wp-cleanup' ) );
		$this->back( array( 'tab' => 'images' ) );
	}

	/** Repair saved image data that no longer matches the files (fresh check per image). */
	public function handle_media_repair() {
		$this->guard( 'wpcu_media_repair' );
		$report = Media_Report::last();
		$ids    = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified the nonce above.
		if ( ! $ids && $report && ! empty( $report['issues'] ) ) {
			$ids = array_map( 'intval', array_keys( $report['issues'] ) );
		}
		if ( ! $ids ) {
			$this->notice( 'info', __( 'No image problems to repair.', 'wp-cleanup' ) );
			$this->back( array( 'tab' => 'images' ) );
		}
		$backup  = Backup::start();
		$details = array();
		$fixed   = 0;
		$failed  = 0;
		foreach ( $ids as $id ) {
			$r = Media_Integrity::repair( $id, $backup );
			if ( 'repaired' === $r['status'] ) {
				++$fixed;
				foreach ( $r['fixed'] as $line ) {
					$details[] = '✓ #' . $id . ' ' . wp_basename( (string) get_attached_file( $id, true ) ) . ': ' . $line;
				}
			} elseif ( 'failed' === $r['status'] ) {
				++$failed;
				$details[] = '✗ #' . $id . ': ' . $r['message'];
			}
		}
		Media_Report::refresh_issues( $ids );
		if ( ! $fixed && ! $failed ) {
			$backup->delete(); // Nothing needed repair after the fresh check; keep no empty set.
			$this->notice( 'info', __( 'A fresh check found nothing left to repair.', 'wp-cleanup' ) );
			$this->back( array( 'tab' => 'images' ) );
		}
		$this->notice(
			$failed ? 'warning' : 'success',
			/* translators: 1: repaired images, 2: failed, 3: backup id */
			sprintf( __( 'Repaired %1$d image(s), %2$d failed. Only WordPress data was changed, no image files. Undo any repair from backup set %3$s on the Backups tab.', 'wp-cleanup' ), $fixed, $failed, $backup->id ),
			$details
		);
		$this->back( array( 'tab' => 'images' ) );
	}

	public function handle_media_scan() {
		$this->guard();
		check_admin_referer( 'wpcu_media_scan' );
		try {
			$r = Media_Report::build_and_store();
			/* translators: 1: images, 2: seconds */
			$this->notice( 'success', sprintf( __( 'Checked %1$d images in %2$s s.', 'wp-cleanup' ), $r['total'], $r['duration'] ) );
		} catch ( \Exception $e ) {
			$this->notice( 'error', $e->getMessage() );
		}
		$this->back( array( 'tab' => 'images' ) );
	}

	/** Refresh one image analysis section without running the full inventory. */
	public function handle_media_rescan() {
		$this->guard();
		check_admin_referer( 'wpcu_media_rescan' );
		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
		if ( ! in_array( $scope, array( 'usage', 'similarity' ), true ) ) {
			$this->notice( 'error', __( 'Unknown image analysis section.', 'wp-cleanup' ) );
			$this->back( array( 'tab' => 'images' ) );
		}
		try {
			if ( 'usage' === $scope ) {
				Media_Report::refresh_usage();
				$this->notice( 'success', __( 'Not used anywhere has been rescanned.', 'wp-cleanup' ) );
			} else {
				Media_Report::refresh_similarity();
				$this->notice( 'success', __( 'Look-alike images have been rescanned.', 'wp-cleanup' ) );
			}
		} catch ( \Exception $e ) {
			$this->notice( 'error', $e->getMessage() );
		}
		$this->back( array( 'tab' => 'images' ) );
	}

	/** Move selected, freshly verified unused attachments into a restorable backup. */
	public function handle_media_remove() {
		$this->guard();
		check_admin_referer( 'wpcu_media_remove' );
		$ids = isset( $_POST['ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ) ) ) : array();
		if ( ! $ids || empty( $_POST['confirm'] ) ) {
			$this->notice( 'warning', __( 'Select at least one image and confirm that you have a full site backup.', 'wp-cleanup' ) );
			$this->back( array( 'tab' => 'images' ) );
		}
		try {
			$usage   = Media_Usage::build();
			$backup  = Backup::start();
			$results = array();
			foreach ( $ids as $id ) {
				$results[] = Media_Remover::remove( $id, $backup, $usage );
			}
			$deleted = array_values( array_filter( $results, static function ( $r ) { return 'deleted' === $r['status']; } ) );
			$removed = count( $deleted );
			$details = array_map( static function ( $r ) { return '#' . $r['id'] . ': ' . $r['status'] . ' — ' . $r['message']; }, $results );
			if ( $removed ) {
				Media_Report::forget_removed( wp_list_pluck( $deleted, 'id' ) );
			}
			// nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- This is a plain-text transient notice, never a SQL query.
			$this->notice( $removed === count( $ids ) ? 'success' : 'warning', sprintf( __( 'Removed %1$d of %2$d images. Backup set: %3$s. Rescan a section or check the full library to update the report.', 'wp-cleanup' ), $removed, count( $ids ), $backup->id ), $details );
		} catch ( \Exception $e ) {
			$this->notice( 'error', $e->getMessage() );
		}
		$this->back( array( 'tab' => 'images' ) );
	}

	/** Process selected image removals in short requests sharing one backup set. */
	public function ajax_media_remove_step() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wp-cleanup' ) ), 403 );
		}
		check_ajax_referer( 'wpcu_media_remove' );
		if ( empty( $_POST['confirm'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Confirm that you have a full site backup first.', 'wp-cleanup' ) ), 400 );
		}
		try {
			$kind = isset( $_POST['kind'] ) ? sanitize_key( sanitize_text_field( wp_unslash( $_POST['kind'] ) ) ) : '';
			if ( 'start' === $kind ) {
				wp_send_json_success( array( 'backup' => Backup::start()->id ) );
			}
			$bid = isset( $_POST['backup'] ) ? sanitize_text_field( wp_unslash( $_POST['backup'] ) ) : '';
			$backup = Backup::open( $bid );
			if ( ! $backup || $backup->manifest['site'] !== home_url() || $backup->manifest['user'] !== wp_get_current_user()->user_login || ! empty( $backup->manifest['restored'] ) ) {
				wp_send_json_error( array( 'message' => __( 'This backup set cannot accept more images.', 'wp-cleanup' ) ), 400 );
			}
			if ( 'image' === $kind ) {
				$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
				if ( ! $id ) {
					wp_send_json_error( array( 'message' => __( 'Select an image.', 'wp-cleanup' ) ), 400 );
				}
				$result = Media_Remover::remove( $id, $backup, Media_Usage::build() );
				if ( 'deleted' === $result['status'] ) {
					Media_Report::forget_removed( array( $id ) );
				}
			} elseif ( 'file' === $kind ) {
				$path = isset( $_POST['path'] ) ? wp_unslash( $_POST['path'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Matched against the fresh catalog and validated by Media_Orphan_Files.
				if ( ! is_string( $path ) || '' === $path ) {
					wp_send_json_error( array( 'message' => __( 'Select a server file.', 'wp-cleanup' ) ), 400 );
				}
				$result = Media_Orphan_Files::remove( $path, $backup, Media_Files::catalog( Media_Files::all_ids() ) );
				if ( 'deleted' === $result['status'] ) {
					Media_Report::mark_inventory_stale();
				}
			} else {
				wp_send_json_error( array( 'message' => __( 'Unknown image action.', 'wp-cleanup' ) ), 400 );
			}
			wp_send_json_success( array( 'backup' => $backup->id, 'result' => $result ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	/** Redirect a used look-alike attachment to a chosen keeper, then back it up. */
	public function handle_media_merge() {
		$this->guard();
		check_admin_referer( 'wpcu_media_merge' );
		$drop = isset( $_POST['drop'] ) ? absint( wp_unslash( $_POST['drop'] ) ) : 0;
		$keep = isset( $_POST['keep'] ) ? absint( wp_unslash( $_POST['keep'] ) ) : 0;
		if ( ! $drop || ! $keep || empty( $_POST['confirm'] ) ) {
			$this->notice( 'warning', __( 'Choose an image to keep and confirm that you have a full site backup.', 'wp-cleanup' ) );
			$this->back( array( 'tab' => 'images' ) );
		}
		try {
			$backup_id = Media_Merger::merge( $drop, $keep, Backup::start() );
			$this->notice( 'success', sprintf( __( 'Image #%1$d now uses image #%2$d. The removed copy and references are in backup set %3$s.', 'wp-cleanup' ), $drop, $keep, $backup_id ) );
		} catch ( \Exception $e ) {
			$this->notice( 'error', $e->getMessage() );
		}
		$this->back( array( 'tab' => 'images' ) );
	}

	/** Move selected unregistered uploads images into a restorable backup. */
	public function handle_media_file_remove() {
		$this->guard();
		check_admin_referer( 'wpcu_media_file_remove' );
		$paths = array();
		foreach ( (array) ( isset( $_POST['paths'] ) ? wp_unslash( $_POST['paths'] ) : array() ) as $path ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every path must match the fresh on-disk catalog and pass path validation.
			if ( is_string( $path ) && '' !== $path ) {
				$paths[] = $path;
			}
		}
		$paths = array_values( array_unique( $paths ) );
		if ( ! $paths || empty( $_POST['confirm'] ) ) {
			$this->notice( 'warning', __( 'Select at least one server file and confirm that you have a full site backup.', 'wp-cleanup' ) );
			$this->back( array( 'tab' => 'images' ) );
		}
		try {
			$catalog = Media_Files::catalog( Media_Files::all_ids() );
			$backup = Backup::start();
			$results = array();
			foreach ( $paths as $path ) {
				$results[] = Media_Orphan_Files::remove( $path, $backup, $catalog );
			}
			$removed = count( array_filter( $results, static function ( $r ) { return 'deleted' === $r['status']; } ) );
			if ( $removed ) {
				Media_Report::mark_inventory_stale();
			}
			$details = array_map( static function ( $r ) { return $r['path'] . ': ' . $r['status'] . ' — ' . $r['message']; }, $results );
			$this->notice( $removed === count( $paths ) ? 'success' : 'warning', sprintf( __( 'Moved %1$d of %2$d unregistered files into backup set %3$s. Check the library again to refresh the file list.', 'wp-cleanup' ), $removed, count( $paths ), $backup->id ), $details );
		} catch ( \Exception $e ) {
			$this->notice( 'error', $e->getMessage() );
		}
		$this->back( array( 'tab' => 'images' ) );
	}

	/**
	 * Check one image before converting it, or after its request ended
	 * without an answer (HTTP 502/504 from a proxy while PHP kept working).
	 */
	public function ajax_media_check() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wp-cleanup' ) ), 403 );
		}
		check_ajax_referer( 'wpcu_media_batch' );
		$id          = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$bid         = isset( $_POST['backup'] ) ? sanitize_text_field( wp_unslash( $_POST['backup'] ) ) : '';
		$policy_hash = isset( $_POST['policy'] ) ? sanitize_text_field( wp_unslash( $_POST['policy'] ) ) : '';
		$slow        = ! empty( $_POST['slow'] );
		$waited      = isset( $_POST['waited'] ) ? absint( wp_unslash( $_POST['waited'] ) ) : 0;
		$gap         = isset( $_POST['gap'] ) ? min( 8192, absint( wp_unslash( $_POST['gap'] ) ) ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one item.', 'wp-cleanup' ) ), 400 );
		}
		if ( ! hash_equals( md5( wp_json_encode( Media_Policy::settings() ) ), $policy_hash ) ) {
			wp_send_json_error( array( 'message' => __( 'The saved image policy changed. Reload this page before converting.', 'wp-cleanup' ) ), 409 );
		}
		if ( $waited ) {
			Media_Guard::gateway_failed( $waited );
		}
		try {
			$check = Media_Guard::preflight( $id, $slow, $waited > 0, $gap );
			if ( in_array( $check['state'], array( 'done', 'interrupted' ), true ) ) {
				Media_Report::forget( array( $id ) );
				Media_Report::refresh_issues( array( $id ) );
			}
			// Open the backup set before the first conversion, so its id survives a request that times out.
			if ( 'ready' === $check['state'] ) {
				$backup = '' !== $bid ? Backup::open( $bid ) : Backup::start();
				if ( ! $backup ) {
					throw new \RuntimeException( __( 'Backup set not found.', 'wp-cleanup' ) );
				}
				$check['backup'] = $backup->id;
			}
			wp_send_json_success( $check );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	public function ajax_media_batch() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore -- One image may take longer than the normal web request.
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wp-cleanup' ) ), 403 );
		}
		check_ajax_referer( 'wpcu_media_batch' );
		$ids = isset( $_POST['ids'] ) ? array_slice( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ), 0, 10 ) : array();
		$bid = isset( $_POST['backup'] ) ? sanitize_text_field( wp_unslash( $_POST['backup'] ) ) : '';
		$policy_hash = isset( $_POST['policy'] ) ? sanitize_text_field( wp_unslash( $_POST['policy'] ) ) : '';
		$gap = isset( $_POST['gap'] ) ? min( 8192, absint( wp_unslash( $_POST['gap'] ) ) ) : 0;
		if ( ! hash_equals( md5( wp_json_encode( Media_Policy::settings() ) ), $policy_hash ) ) {
			wp_send_json_error( array( 'message' => __( 'The saved image policy changed. Reload this page before converting.', 'wp-cleanup' ) ), 409 );
		}
		try {
			$backup = '' !== $bid ? Backup::open( $bid ) : Backup::start();
			if ( ! $backup ) {
				throw new \RuntimeException( __( 'Backup set not found.', 'wp-cleanup' ) );
			}
			$results = array();
			foreach ( $ids as $id ) {
				$results[] = Media_Converter::convert( $id, $backup, false, $gap );
			}
			Media_Report::forget(
				wp_list_pluck(
					array_filter(
						$results,
						static function ( $r ) {
							return in_array( $r['status'], array( 'converted', 'compliant', 'skipped' ), true );
						}
					),
					'id'
				)
			);
			Media_Report::refresh_issues( $ids );
			wp_send_json_success(
				array(
					'backup'  => $backup->id,
					'results' => $results,
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	private function render_images() {
		$s      = Media_Policy::settings();
		$avif   = Media_Policy::avif_supported();
		$report = Media_Report::last();
		$action = admin_url( 'admin-post.php' );

		if ( $report ) {
			$this->render_media_stats( $report );
		}

		echo '<div class="wpcu-media-intro"><p>' . esc_html(
			sprintf(
				/* translators: 1: JPEG maximum, 2: AVIF maximum, 3: small size name, 4: small maximum */
				__( 'With the JPEG fallback enabled, each image gets one optimized JPEG of at most %1$d px, plus a full AVIF of at most %2$d px and an optional "%3$s" AVIF of at most %4$d px. The AVIF stays the main attachment and direct URL; WordPress image markup includes the JPEG for older browsers. Old files and sizes move into a restorable backup set.', 'wp-cleanup' ),
				$s['jpeg_max'],
				$s['full_max'],
				$s['small_name'],
				$s['small_max']
			)
		) . '</p><p class="description">' . esc_html__( 'GIF and WebP (may be animated), site icons, headers and offloaded files are never touched. Space is freed when you delete the backup set on the Backups tab after checking the site.', 'wp-cleanup' ) . '</p></div>';

		if ( ! $avif && $s['jpeg_fallback'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This server cannot write AVIF. Conversion will keep the optimized JPEG fallback only.', 'wp-cleanup' ) . '</p></div>';
		}
		if ( ! $avif && ! $s['jpeg_fallback'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'AVIF-only conversion is unavailable on this server. Enable the JPEG fallback or install AVIF encoding support.', 'wp-cleanup' ) . '</p></div>';
		}
		$jpeg = Media_Policy::jpeg_supported();
		if ( ! $jpeg && $s['jpeg_fallback'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'This server cannot write JPEG images.', 'wp-cleanup' ) . '</p></div>';
		}

		echo '<details class="wpcu-media-settings"><summary>' . esc_html__( 'Image policy', 'wp-cleanup' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( $action ) . '" class="wpcu-policy-form">';
		wp_nonce_field( 'wpcu_media_settings' );
		echo '<input type="hidden" name="action" value="wpcu_media_settings">';
		echo '<p><label><input type="checkbox" name="jpeg_fallback" value="1"' . checked( $s['jpeg_fallback'], true, false ) . '> ' . esc_html__( 'Keep one JPEG fallback beside the main AVIF for older devices', 'wp-cleanup' ) . '</label></p>';
		echo '<p><label>' . esc_html__( 'JPEG fallback: longest side at most', 'wp-cleanup' ) . ' <input type="number" name="jpeg_max" min="320" max="8192" value="' . esc_attr( $s['jpeg_max'] ) . '"> px</label> <label>' . esc_html__( 'quality', 'wp-cleanup' ) . ' <input type="number" name="jpeg_quality" min="40" max="95" value="' . esc_attr( $s['jpeg_quality'] ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Full image: longest side at most', 'wp-cleanup' ) . ' <input type="number" name="full_max" min="320" max="8192" value="' . esc_attr( $s['full_max'] ) . '"> px</label></p>';
		echo '<p><label>' . esc_html__( 'Small image size name', 'wp-cleanup' ) . ' <input type="text" name="small_name" value="' . esc_attr( $s['small_name'] ) . '"></label> <label>' . esc_html__( 'longest side at most', 'wp-cleanup' ) . ' <input type="number" name="small_max" min="64" value="' . esc_attr( $s['small_max'] ) . '"> px</label></p>';
		echo '<p><label><input type="checkbox" name="set_flag" value="1"' . checked( $s['set_flag'], true, false ) . '> ' . esc_html__( 'Mark converted images for the ALPS theme (_alps_two_size_upload)', 'wp-cleanup' ) . '</label></p>';
		$alps = Media_Policy::alps_theme();
		echo '<p class="description">' . esc_html__( 'The marker tells ALPS that an image follows its size policy: one full image of at most 1920 px plus an optional "alps-small" of at most 768 px. ALPS marks small uploads that only have one file too. Theme templates then map old size names to these files, and regenerating thumbnails does not bring back the old sizes. It is only set while the policy above matches those ALPS sizes; switching it off removes it from converted images.', 'wp-cleanup' ) . '</p>';
		echo '<p class="description"><strong>' . esc_html(
			$alps['active']
				/* translators: %s: theme name and version */
				? sprintf( __( 'Active theme %s uses the ALPS image policy, so the marker has an effect.', 'wp-cleanup' ), $alps['label'] )
				/* translators: %s: theme name and version */
				: sprintf( __( 'Active theme %s does not use the ALPS image policy, so the marker has no effect here.', 'wp-cleanup' ), $alps['label'] )
		) . '</strong>';
		if ( $s['set_flag'] && ! Media_Policy::alps_compatible( $s ) ) {
			echo ' ' . esc_html__( 'The sizes above differ from ALPS (alps-small, 1920/768 px), so no marker is set.', 'wp-cleanup' );
		}
		echo '</p>';
		if ( ! empty( $alps['converts_uploads'] ) ) {
			echo '<p class="description">' . esc_html(
				Media_Policy::alps_compatible( $s )
					? __( 'The theme converts new uploads itself with this policy (full AVIF, small AVIF and one JPEG fallback) and serves them as AVIF with the JPEG for older browsers. New uploads therefore show as already converted here; this page is for older images.', 'wp-cleanup' )
					: __( 'The theme converts new uploads itself with its own ALPS sizes, because the sizes above differ from ALPS. Those uploads will be listed here as needing conversion.', 'wp-cleanup' )
			) . '</p>';
		}
		submit_button( __( 'Save policy', 'wp-cleanup' ), 'secondary', 'submit', false );
		echo '</form></details>';

		echo '<form method="post" action="' . esc_url( $action ) . '" class="wpcu-inline-form">';
		wp_nonce_field( 'wpcu_media_scan' );
		echo '<input type="hidden" name="action" value="wpcu_media_scan">';
		submit_button( $report ? __( 'Check the library again', 'wp-cleanup' ) : __( 'Check the media library', 'wp-cleanup' ), 'primary', 'submit', false );
		echo '</form>';

		if ( ! $report ) {
			echo '<div class="wpcu-empty"><p>' . esc_html__( 'Checking only reads. Nothing changes until you convert.', 'wp-cleanup' ) . '</p></div>';
			return;
		}

		if ( ! empty( $report['inventory_stale'] ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The conversion totals are from the last full library check. Run it again to update those totals.', 'wp-cleanup' ) . '</p></div>';
		}
		$this->render_media_issues( $report );

		echo '<div class="wpcu-cards">';
		$this->card( __( 'Images to convert', 'wp-cleanup' ), number_format_i18n( count( $report['items'] ) ) );
		$this->card( __( 'Their files now', 'wp-cleanup' ), number_format_i18n( $report['files'] ) . ' · ' . ( $report['bytes'] ? size_format( $report['bytes'], 1 ) : '0 B' ) );
		$this->card( __( 'Stray files among them', 'wp-cleanup' ), number_format_i18n( $report['strays'] ) . ' · ' . ( $report['stray_b'] ? size_format( $report['stray_b'], 1 ) : '0 B' ), __( 'Sizes no metadata lists, e.g. from old themes, and .webp sidecars.', 'wp-cleanup' ) );
		$this->card( __( 'Already compliant / skipped', 'wp-cleanup' ), number_format_i18n( $report['compliant'] ) . ' / ' . number_format_i18n( $report['skipped'] ) );
		$this->card( __( 'Existing small AVIFs you can select', 'wp-cleanup' ), number_format_i18n( count( isset( $report['small_items'] ) ? $report['small_items'] : array() ) ), __( 'Apply the size gap to selected rows below to back up an unnecessary small file.', 'wp-cleanup' ) );
		if ( isset( $report['unused'], $report['groups'] ) ) {
			$this->card( __( 'Not used anywhere', 'wp-cleanup' ), number_format_i18n( count( $report['unused'] ) ), __( 'Of all images. See the list below.', 'wp-cleanup' ) );
			$this->card(
				__( 'Look-alike groups', 'wp-cleanup' ),
				number_format_i18n( count( $report['groups'] ) ),
				/* translators: %d: images in all groups */
				sprintf( __( '%d images in these groups.', 'wp-cleanup' ), array_sum( array_map( 'count', wp_list_pluck( $report['groups'], 'ids' ) ) ) )
			);
		}
		echo '</div>';
		if ( $report['reasons'] ) {
			echo '<details><summary>' . esc_html__( 'Why some images are skipped', 'wp-cleanup' ) . '</summary><ul class="wpcu-details">';
			foreach ( $report['reasons'] as $reason => $count ) {
				echo '<li>' . esc_html( number_format_i18n( $count ) . ' × ' . $reason ) . '</li>';
			}
			echo '</ul></details>';
		}
		if ( $report['settings'] != $s ) { // phpcs:ignore -- array comparison.
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The policy changed since this check. Check the library again before converting.', 'wp-cleanup' ) . '</p></div>';
		}
		if ( ! $report['items'] && empty( $report['small_items'] ) ) {
			echo '<div class="wpcu-empty"><p>' . esc_html__( 'Nothing to convert.', 'wp-cleanup' ) . '</p></div>';
			$this->render_image_usage( $report );
			$this->render_file_catalog( $report );
			return;
		}
		$has_usage = isset( $report['use_counts'] );
		$rows = array_merge( $report['items'], isset( $report['small_items'] ) ? $report['small_items'] : array() );

		echo '<form class="wpcu-media-form">';
		echo '<div class="wpcu-filters"><input type="search" class="wpcu-filter-search" placeholder="' . esc_attr__( 'Filter by file name…', 'wp-cleanup' ) . '">';
		if ( $has_usage ) {
			echo ' <select class="wpcu-filter-usage" aria-label="' . esc_attr__( 'Filter by use', 'wp-cleanup' ) . '"><option value="all">' . esc_html__( 'Used or not', 'wp-cleanup' ) . '</option><option value="1">' . esc_html__( 'Used somewhere', 'wp-cleanup' ) . '</option><option value="0">' . esc_html__( 'Not used anywhere', 'wp-cleanup' ) . '</option></select>';
		}
		echo ' <span class="wpcu-visible-count"></span></div>';
		echo '<div class="wpcu-scroll"><table class="widefat striped wpcu-items wpcu-sortable"><thead><tr><td class="check-column"><input type="checkbox" class="wpcu-check-all" aria-label="' . esc_attr__( 'Select all visible', 'wp-cleanup' ) . '"></td>';
		echo '<th class="wpcu-thumb-col"></th>';
		echo self::sort_heading( __( 'File', 'wp-cleanup' ), 'text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes output.
		if ( $has_usage ) {
			echo self::sort_heading( __( 'Used in', 'wp-cleanup' ), 'number' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes output.
		}
		echo self::sort_heading( __( 'Format', 'wp-cleanup' ), 'text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes output.
		echo self::sort_heading( __( 'Size', 'wp-cleanup' ), 'number', 'num' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes output.
		echo self::sort_heading( __( 'Files', 'wp-cleanup' ), 'number', 'num' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes output.
		echo self::sort_heading( __( 'Strays', 'wp-cleanup' ), 'number', 'num' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes output.
		echo self::sort_heading( __( 'Disk', 'wp-cleanup' ), 'number', 'num' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes output.
		echo '<th>' . esc_html__( 'Result', 'wp-cleanup' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $i ) {
			$kind = isset( $i['kind'] ) ? $i['kind'] : 'convert';
			printf( '<tr data-search="%s" data-id="%d" data-used="%d" data-kind="%s">', esc_attr( strtolower( $i['file'] . ' ' . $i['title'] ) ), (int) $i['id'], empty( $report['use_counts'][ $i['id'] ] ) ? 0 : 1, esc_attr( $kind ) );
			echo '<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="' . esc_attr( $i['id'] ) . '"></th>';
			echo '<td class="wpcu-thumb-col">' . self::thumb( (int) $i['id'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- thumb() escapes its attributes.
			echo '<td class="wpcu-name"><a href="' . esc_url( (string) get_edit_post_link( $i['id'] ) ) . '"><code>' . esc_html( $i['file'] ) . '</code></a></td>';
			if ( $has_usage ) {
				echo '<td class="wpcu-used" data-sort-value="' . (int) ( isset( $report['use_counts'][ $i['id'] ] ) ? $report['use_counts'][ $i['id'] ] : 0 ) . '">' . self::uses_html( (int) $i['id'], $report ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- uses_html() escapes every part.
			}
			echo '<td class="wpcu-format">' . esc_html( str_replace( 'image/', '', $i['mime'] ) ) . '</td>';
			echo '<td class="num wpcu-size" data-sort-value="' . (int) ( $i['width'] * $i['height'] ) . '">' . esc_html( $i['width'] . '×' . $i['height'] ) . '</td>';
			echo '<td class="num wpcu-file-count" data-sort-value="' . (int) $i['files'] . '">' . esc_html( number_format_i18n( $i['files'] ) ) . '</td>';
			echo '<td class="num wpcu-stray-count" data-sort-value="' . (int) $i['strays'] . '">' . ( $i['strays'] ? esc_html( number_format_i18n( $i['strays'] ) ) : '<span class="wpcu-muted">–</span>' ) . '</td>';
			echo '<td class="num wpcu-disk" data-sort-value="' . (int) $i['bytes'] . '">' . esc_html( size_format( $i['bytes'], 1 ) ) . '</td>';
			echo '<td class="wpcu-result">' . ( 'simplify' === $kind ? esc_html__( 'Already converted · select to remove the small AVIF when the gap matches.', 'wp-cleanup' ) : '' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<div class="wpcu-actions wpcu-media-actions">';
		echo '<label class="wpcu-media-option wpcu-media-gap" for="wpcu-size-gap"><span>' . esc_html__( 'Skip the small AVIF when its longest side is within', 'wp-cleanup' ) . '</span> <input id="wpcu-size-gap" class="wpcu-size-gap" type="number" min="0" max="8192" step="1" value="300" inputmode="numeric" required> <span>' . esc_html__( 'px of the full AVIF', 'wp-cleanup' ) . '</span></label>';
		echo '<p class="description">' . esc_html__( 'For Apply to selected only. A value of 0 keeps the normal two-size rule. Existing small AVIFs that match are moved to Backups; the full AVIF and any JPEG fallback stay.', 'wp-cleanup' ) . '</p>';
		echo '<label class="wpcu-media-option"><input type="checkbox" class="wpcu-media-confirm"><span><strong>' . esc_html__( 'I have a recent full backup of files and database', 'wp-cleanup' ) . '</strong></span></label>';
		$gateway = (int) get_option( Media_Guard::GATEWAY, 0 );
		echo '<div><label class="wpcu-media-option"><input type="checkbox" class="wpcu-media-slow"><span>' . esc_html__( 'Also try images that may time out', 'wp-cleanup' ) . '</span></label><details class="wpcu-media-details"><summary>' . esc_html__( 'How timeouts are handled', 'wp-cleanup' ) . '</summary><p class="description wpcu-media-help">' . esc_html(
			sprintf(
				/* translators: 1: seconds per image, 2: how it was determined */
				__( 'Each image is checked before conversion. Completed or still-running images are skipped. Images estimated to need more than about %1$d s (%2$s) are deferred unless you select the option above. After a timeout, WP Cleanup checks whether the server finished the image.', 'wp-cleanup' ),
				Media_Guard::budget(),
				$gateway
					/* translators: %d: seconds */
					? sprintf( __( 'this server timed out after %d s before', 'wp-cleanup' ), $gateway )
					: __( 'a typical proxy limit; no timeout seen yet', 'wp-cleanup' )
			)
		) . '</p></details></div>';
		echo '<div class="wpcu-media-controls"><button type="button" class="button button-primary wpcu-delete wpcu-media-run" data-scope="selected"' . disabled( ! empty( $report['small_items'] ) || ( $s['jpeg_fallback'] ? $jpeg : $avif ), false, false ) . '>' . esc_html__( 'Apply to selected', 'wp-cleanup' ) . '</button> ';
		/* translators: %d: images */
		echo '<button type="button" class="button wpcu-media-run" data-scope="all"' . disabled( $report['items'] && ( $s['jpeg_fallback'] ? $jpeg : $avif ), false, false ) . '>' . esc_html( sprintf( __( 'Convert all %d', 'wp-cleanup' ), count( $report['items'] ) ) ) . '</button>';
		echo '<button type="button" class="button wpcu-media-stop" hidden>' . esc_html__( 'Stop after current image', 'wp-cleanup' ) . '</button></div>';
		echo '<div class="wpcu-media-feedback"><progress class="wpcu-media-progress" max="100" value="0" hidden></progress><span class="wpcu-media-status" aria-live="polite"></span></div>';
		echo '</div></form>';
		$this->render_image_usage( $report );
		$this->render_file_catalog( $report );
	}


	/**
	 * Small preview of an image, from its smallest fitting size.
	 *
	 * @param int $id   Attachment id.
	 * @param int $size Displayed edge in px.
	 */
	private static function thumb( $id, $size = 48 ) {
		$src = wp_get_attachment_image_src( $id, array( $size * 2, $size * 2 ) );
		if ( ! $src ) {
			return '<span class="wpcu-thumb wpcu-thumb-missing" style="width:' . (int) $size . 'px;height:' . (int) $size . 'px"></span>';
		}
		return '<img class="wpcu-thumb" src="' . esc_url( $src[0] ) . '" width="' . (int) $size . '" height="' . (int) $size . '" loading="lazy" decoding="async" alt="">';
	}

	/**
	 * Escaped link to a post's edit screen, with its type and (if not published) status.
	 *
	 * @param int $post_id Post id.
	 */
	private static function post_link( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			/* translators: %d: post id */
			return esc_html( sprintf( __( 'post #%d (deleted)', 'wp-cleanup' ), $post_id ) );
		}
		$title = '' !== trim( $post->post_title ) ? $post->post_title : __( '(no title)', 'wp-cleanup' );
		$type  = get_post_type_object( $post->post_type );
		$extra = $type ? $type->labels->singular_name : $post->post_type;
		if ( 'publish' !== $post->post_status ) {
			$extra .= ', ' . $post->post_status;
		}
		$url = get_edit_post_link( $post_id );
		return ( $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . ' <span class="wpcu-muted">(' . esc_html( $extra ) . ')</span>';
	}

	/**
	 * One use of an image, as escaped HTML.
	 *
	 * @param array $use Use entry from Media_Usage.
	 */
	private static function use_label( array $use ) {
		switch ( $use['k'] ) {
			case 'featured':
				/* translators: %s: post link */
				return sprintf( esc_html__( 'Featured image of %s', 'wp-cleanup' ), self::post_link( (int) $use['o'] ) );
			case 'content':
				/* translators: %s: post link */
				return sprintf( esc_html__( 'In the content of %s', 'wp-cleanup' ), self::post_link( (int) $use['o'] ) );
			case 'field':
				/* translators: 1: field name, 2: post link */
				return sprintf( esc_html__( 'Field %1$s of %2$s', 'wp-cleanup' ), '<code>' . esc_html( $use['f'] ) . '</code>', self::post_link( (int) $use['o'] ) );
			case 'term':
				$term = get_term( (int) $use['o'] );
				$name = $term && ! is_wp_error( $term ) ? $term->name . ' (' . $term->taxonomy . ')' : '#' . $use['o'];
				/* translators: 1: field name, 2: term name */
				return sprintf( esc_html__( 'Field %1$s of the term %2$s', 'wp-cleanup' ), '<code>' . esc_html( $use['f'] ) . '</code>', esc_html( $name ) );
			default:
				$name   = (string) $use['o'];
				$labels = array(
					'site_icon' => __( 'Site icon', 'wp-cleanup' ),
					'site_logo' => __( 'Site logo', 'wp-cleanup' ),
				);
				if ( isset( $labels[ $name ] ) ) {
					return esc_html( $labels[ $name ] );
				}
				if ( 0 === strpos( $name, 'theme_mods_' ) ) {
					/* translators: %s: theme folder */
					return sprintf( esc_html__( 'Customizer settings of the theme %s (logo, header or background)', 'wp-cleanup' ), '<code>' . esc_html( substr( $name, 11 ) ) . '</code>' );
				}
				if ( 0 === strpos( $name, 'widget_' ) ) {
					/* translators: %s: widget type */
					return sprintf( esc_html__( 'A widget (%s)', 'wp-cleanup' ), '<code>' . esc_html( substr( $name, 7 ) ) . '</code>' );
				}
				/* translators: %s: option name */
				return sprintf( esc_html__( 'Site setting %s', 'wp-cleanup' ), '<code>' . esc_html( $name ) . '</code>' );
		}
	}

	/** A keyboard-accessible sortable table heading. */
	private static function sort_heading( $label, $type, $class = '' ) {
		return '<th scope="col" class="' . esc_attr( $class ) . '" data-sort-type="' . esc_attr( $type ) . '" aria-sort="none"><button type="button" class="wpcu-sort-button">' . esc_html( $label ) . '</button></th>';
	}

	/** A section-only rescan button. */
	private static function rescan_button( $scope, $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpcu-inline-form">';
		wp_nonce_field( 'wpcu_media_rescan' );
		echo '<input type="hidden" name="action" value="wpcu_media_rescan"><input type="hidden" name="scope" value="' . esc_attr( $scope ) . '">';
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}

	/** Backup confirmation and submission for a removal form. */
	private static function removal_footer() {
		echo '<div class="wpcu-actions"><p><label><input type="checkbox" name="confirm" value="1" class="wpcu-removal-confirm"> <strong>' . esc_html__( 'I have a recent full backup of files and database', 'wp-cleanup' ) . '</strong></label></p>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Move selected images to backup', 'wp-cleanup' ) . '</button> <span class="wpcu-selection-count" aria-live="polite"></span></p>';
		echo '<p class="description">' . esc_html__( 'Selected images are moved one at a time into the same restorable backup set. Delete that set only after checking the site.', 'wp-cleanup' ) . '</p>';
		echo '<p><button type="button" class="button wpcu-removal-stop" hidden>' . esc_html__( 'Stop after current image', 'wp-cleanup' ) . '</button> <progress class="wpcu-removal-progress" max="1" value="0" hidden></progress> <span class="wpcu-removal-status" role="status" aria-live="polite"></span></p><ul class="wpcu-removal-errors"></ul></div>';
	}

	/**
	 * Where an image is used, as escaped HTML: a summary plus the list.
	 *
	 * @param int   $id     Attachment id.
	 * @param array $report Media report.
	 */
	private static function uses_html( $id, array $report ) {
		$count  = isset( $report['use_counts'][ $id ] ) ? (int) $report['use_counts'][ $id ] : 0;
		$uses   = isset( $report['uses'][ $id ] ) ? $report['uses'][ $id ] : array();
		$parent = isset( $report['parents'][ $id ] ) ? (int) $report['parents'][ $id ] : 0;
		$html   = '';
		if ( isset( $report['usage_checked_ids'] ) && ! in_array( $id, $report['usage_checked_ids'], true ) ) {
			$html .= '<span class="wpcu-badge">' . esc_html__( 'Usage not checked', 'wp-cleanup' ) . '</span>';
		} elseif ( ! $count ) {
			$html .= '<span class="wpcu-badge wpcu-unused">' . esc_html__( 'Not used', 'wp-cleanup' ) . '</span>';
		} else {
			/* translators: %d: number of places */
			$html .= '<details class="wpcu-uses"><summary>' . esc_html( sprintf( _n( 'Referenced in %d current location', 'Referenced in %d current locations', $count, 'wp-cleanup' ), $count ) ) . '</summary><ul>';
			foreach ( $uses as $use ) {
				$html .= '<li>' . self::use_label( $use ) . '</li>';
			}
			if ( $count > count( $uses ) ) {
				/* translators: %d: number of further places */
				$html .= '<li class="wpcu-muted">' . esc_html( sprintf( __( '… and %d more', 'wp-cleanup' ), $count - count( $uses ) ) ) . '</li>';
			}
			$html .= '</ul></details>';
		}
		if ( $parent ) {
			/* translators: %s: post link */
			$html .= '<div class="wpcu-attached">' . sprintf( esc_html__( 'Uploaded to %s', 'wp-cleanup' ), self::post_link( $parent ) ) . '</div>';
		}
		return $html;
	}

	/**
	 * Look-alike groups and images used nowhere.
	 *
	 * @param array $report Media report.
	 */
	private function render_image_usage( array $report ) {
		if ( ! isset( $report['groups'], $report['info'], $report['unused'] ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Check the library again to see where images are used and which look alike.', 'wp-cleanup' ) . '</p></div>';
			return;
		}
		$info         = $report['info'];
		$known        = array_fill_keys( isset( $report['usage_checked_ids'] ) ? $report['usage_checked_ids'] : array_keys( $info ), true );
		$recommended  = array_fill_keys( isset( $report['duplicate_unused'] ) ? $report['duplicate_unused'] : array(), true );
		$action       = esc_url( admin_url( 'admin-post.php' ) );

		echo '<div class="wpcu-section-head" id="wpcu-lookalikes"><h2>' . esc_html__( 'Look-alike images', 'wp-cleanup' ) . '</h2>';
		self::rescan_button( 'similarity', __( 'Rescan look-alike images', 'wp-cleanup' ) );
		echo '</div>';
		echo '<p class="description wpcu-media-intro">' . esc_html__( 'Choose a keeper to merge a used look-alike: known WordPress URLs and image IDs are redirected, then the redundant attachment and files move to a restorable backup. Review crops and dimensions first. References in theme files, CSS, plugin tables or external sites cannot be redirected.', 'wp-cleanup' ) . '</p>';
		if ( ! empty( $report['hash_left'] ) ) {
			/* translators: 1: images compared, 2: images left */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'Compared %1$d images; %2$d are not compared yet. Rescan look-alike images to continue: finished images are remembered.', 'wp-cleanup' ), $report['hashed'], $report['hash_left'] ) ) . '</p></div>';
		}
		if ( ! $report['groups'] ) {
			echo '<div class="wpcu-empty"><p>' . esc_html__( 'No look-alike images found.', 'wp-cleanup' ) . '</p></div>';
		} else {
			echo '<form id="wpcu-merge-form" method="post" action="' . $action . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- URL escaped above.
			wp_nonce_field( 'wpcu_media_merge' );
			echo '<input type="hidden" name="action" value="wpcu_media_merge"><input type="hidden" name="drop" value=""><input type="hidden" name="keep" value=""><input type="hidden" name="confirm" value="1"></form>';
			echo '<form id="wpcu-lookalikes-form" method="post" action="' . $action . '" class="wpcu-removal-form wpcu-lookalikes-form">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- URL escaped above.
			wp_nonce_field( 'wpcu_media_remove' );
			echo '<input type="hidden" name="action" value="wpcu_media_remove">';
			echo '<p><button type="button" class="button wpcu-select-images" data-select-mode="recommended">' . esc_html__( 'Select unused look-alike copies', 'wp-cleanup' ) . '</button> <button type="button" class="button wpcu-select-images" data-select-mode="clear">' . esc_html__( 'Clear selection', 'wp-cleanup' ) . '</button></p>';
			foreach ( array_slice( $report['groups'], 0, 200 ) as $group ) {
				echo '<div class="wpcu-dup-group"><div class="wpcu-dup-head">';
				echo esc_html(
					sprintf(
						/* translators: %d: images in the group */
						_n( '%d image', '%d images', count( $group['ids'] ), 'wp-cleanup' ),
						count( $group['ids'] )
					)
				) . ' · ' . ( $group['identical'] ? esc_html__( 'identical files', 'wp-cleanup' ) : esc_html__( 'look alike', 'wp-cleanup' ) );
				echo '</div><div class="wpcu-dup-items">';
				foreach ( $group['ids'] as $id ) {
					$i        = isset( $info[ $id ] ) ? $info[ $id ] : array( 'file' => '#' . $id, 'w' => 0, 'h' => 0, 'bytes' => 0 );
					$eligible = isset( $known[ $id ] ) && empty( $report['use_counts'][ $id ] ) && ! Media_Policy::skip_reason( $id );
					echo '<div class="wpcu-dup-item"><div class="wpcu-dup-summary">' . self::thumb( $id, 96 ) . '<div class="wpcu-dup-meta">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- thumb() escapes its attributes.
					echo '<a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '"><code>' . esc_html( $i['file'] ) . '</code></a>';
					echo '<div class="wpcu-muted">' . esc_html( $i['w'] . '×' . $i['h'] . ' · ' . size_format( $i['bytes'], 1 ) ) . '</div>';
					echo self::uses_html( $id, $report ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- uses_html() escapes every part.
					echo '</div></div>';
					if ( ! Media_Policy::skip_reason( $id ) && count( $group['ids'] ) > 1 ) {
						echo '<div class="wpcu-dup-merge"><label>' . esc_html__( 'Keep this image instead:', 'wp-cleanup' ) . ' <select class="wpcu-merge-keeper" aria-label="' . esc_attr( sprintf( __( 'Replacement for image %d', 'wp-cleanup' ), $id ) ) . '">';
						foreach ( $group['ids'] as $other_id ) {
							if ( $other_id !== $id && ! Media_Policy::skip_reason( $other_id ) ) {
								$other_info = isset( $info[ $other_id ] ) ? $info[ $other_id ] : array( 'file' => '#' . $other_id );
								echo '<option value="' . (int) $other_id . '">' . esc_html( '#' . $other_id . ' · ' . $other_info['file'] ) . '</option>';
							}
						}
						echo '</select></label><button type="button" class="button wpcu-merge-image" data-drop="' . (int) $id . '">' . esc_html__( 'Replace this image', 'wp-cleanup' ) . '</button><p class="description">' . esc_html__( 'This card\'s image leaves the Media Library and moves to Backups. The selected image stays.', 'wp-cleanup' ) . '</p></div>';
					}
					if ( $eligible ) {
						echo '<div class="wpcu-dup-remove"><label><input type="checkbox" name="ids[]" form="wpcu-lookalikes-form" value="' . (int) $id . '" data-lookalike="' . ( isset( $recommended[ $id ] ) ? '1' : '0' ) . '"> ' . esc_html__( 'Select for backup', 'wp-cleanup' ) . '</label> <button type="button" class="button button-small wpcu-single-remove" data-id="' . (int) $id . '">' . esc_html__( 'Move this image to backup', 'wp-cleanup' ) . '</button></div>';
					}
					echo '</div>';
				}
				echo '</div></div>';
			}
			if ( count( $report['groups'] ) > 200 ) {
				/* translators: %d: groups not shown */
				echo '<p class="description">' . esc_html( sprintf( __( '%d more groups are not shown.', 'wp-cleanup' ), count( $report['groups'] ) - 200 ) ) . '</p>';
			}
			self::removal_footer();
			echo '</form>';
		}

		echo '<div class="wpcu-section-head" id="wpcu-unused"><h2>' . esc_html__( 'Not used anywhere', 'wp-cleanup' ) . '</h2>';
		self::rescan_button( 'usage', __( 'Rescan not used anywhere', 'wp-cleanup' ) );
		echo '</div>';
		echo '<p class="description wpcu-media-intro">' . esc_html__( 'Not a featured image, not in any post, page, block, gallery, custom field, term field, widget or site setting. Images can still be used from theme files, CSS, other plugins\' own tables or other websites, so check before removing any.', 'wp-cleanup' ) . '</p>';
		if ( ! $report['unused'] ) {
			echo '<div class="wpcu-empty"><p>' . esc_html__( 'Every image is used somewhere.', 'wp-cleanup' ) . '</p></div>';
			return;
		}
		/* translators: %d: images */
		echo '<details class="wpcu-unused-list"><summary>' . esc_html( sprintf( _n( 'Show %d image', 'Show %d images', count( $report['unused'] ), 'wp-cleanup' ), count( $report['unused'] ) ) ) . '</summary>';
		echo '<form id="wpcu-unused-form" method="post" action="' . $action . '" class="wpcu-removal-form wpcu-unused-form">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- URL escaped above.
		wp_nonce_field( 'wpcu_media_remove' );
		echo '<input type="hidden" name="action" value="wpcu_media_remove">';
		echo '<p><button type="button" class="button wpcu-select-images" data-select-mode="recommended">' . esc_html__( 'Select unused look-alike copies', 'wp-cleanup' ) . '</button> <button type="button" class="button wpcu-select-images" data-select-mode="all">' . esc_html__( 'Select all unused images', 'wp-cleanup' ) . '</button> <button type="button" class="button wpcu-select-images" data-select-mode="clear">' . esc_html__( 'Clear selection', 'wp-cleanup' ) . '</button></p>';
		echo '<div class="wpcu-scroll"><table class="widefat striped wpcu-items wpcu-sortable"><thead><tr><th class="check-column"></th><th class="wpcu-thumb-col"></th>';
		echo self::sort_heading( __( 'File', 'wp-cleanup' ), 'text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes output.
		echo self::sort_heading( __( 'Size', 'wp-cleanup' ), 'number', 'num' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes output.
		echo self::sort_heading( __( 'Disk', 'wp-cleanup' ), 'number', 'num' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes output.
		echo self::sort_heading( __( 'Uploaded to', 'wp-cleanup' ), 'text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes output.
		echo '</tr></thead><tbody>';
		foreach ( $report['unused'] as $id ) {
			$i        = isset( $info[ $id ] ) ? $info[ $id ] : array( 'file' => '#' . $id, 'w' => 0, 'h' => 0, 'bytes' => 0 );
			$eligible = ! Media_Policy::skip_reason( $id );
			echo '<tr><th scope="row" class="check-column"><input type="checkbox" name="ids[]" form="wpcu-unused-form" value="' . (int) $id . '" data-lookalike="' . ( isset( $recommended[ $id ] ) ? '1' : '0' ) . '"' . disabled( $eligible, false, false ) . ' aria-label="' . esc_attr( sprintf( __( 'Select image %d', 'wp-cleanup' ), $id ) ) . '"></th><td class="wpcu-thumb-col">' . self::thumb( $id ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- thumb() escapes its attributes.
			echo '<td class="wpcu-name"><a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '"><code>' . esc_html( $i['file'] ) . '</code></a></td>';
			echo '<td class="num" data-sort-value="' . (int) ( $i['w'] * $i['h'] ) . '">' . esc_html( $i['w'] . '×' . $i['h'] ) . '</td><td class="num" data-sort-value="' . (int) $i['bytes'] . '">' . esc_html( size_format( $i['bytes'], 1 ) ) . '</td>';
			echo '<td>' . ( ! empty( $report['parents'][ $id ] ) ? self::post_link( (int) $report['parents'][ $id ] ) : '<span class="wpcu-muted">–</span>' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- post_link() escapes.
		}
		echo '</tbody></table></div>';
		self::removal_footer();
		echo '</form></details>';
	}

	/**
	 * "1920×1280 · AVIF · 58.3 KB" for a file info array.
	 *
	 * @param array|null $info bytes, width, height, mime.
	 */
	private static function file_info_text( $info ) {
		if ( ! is_array( $info ) ) {
			return '';
		}
		$parts = array();
		if ( ! empty( $info['width'] ) && ! empty( $info['height'] ) ) {
			$parts[] = (int) $info['width'] . '×' . (int) $info['height'];
		}
		if ( ! empty( $info['mime'] ) ) {
			$parts[] = strtoupper( str_replace( 'image/', '', $info['mime'] ) );
		}
		if ( isset( $info['bytes'] ) ) {
			$parts[] = size_format( (int) $info['bytes'], 1 ) ? size_format( (int) $info['bytes'], 1 ) : '0 B';
		}
		return implode( ' · ', $parts );
	}

	/** Totals of every image file on the server, shown at the top even when nothing is left to convert. */
	private function render_media_stats( array $report ) {
		$cat = isset( $report['file_catalog'] ) ? $report['file_catalog'] : null;
		echo '<div class="wpcu-section-head"><h2>' . esc_html__( 'Images on this server', 'wp-cleanup' ) . '</h2></div>';
		if ( ! $cat ) {
			echo '<p class="description">' . esc_html__( 'Check the media library again to count every image file on the server.', 'wp-cleanup' ) . '</p>';
			return;
		}
		$lib_files = isset( $cat['library_files'] ) ? (int) $cat['library_files'] : array_sum( array_map( 'count', wp_list_pluck( $cat['library'], 'files' ) ) );
		$lib_bytes = isset( $cat['library_bytes'] ) ? (int) $cat['library_bytes'] : 0;
		if ( ! isset( $cat['library_bytes'] ) ) {
			foreach ( $cat['library'] as $image ) {
				$lib_bytes += array_sum( wp_list_pluck( $image['files'], 'bytes' ) );
			}
		}
		$all_files = $lib_files + (int) $cat['unregistered_count'];
		$all_bytes = $lib_bytes + (int) $cat['unregistered_bytes'];
		$held      = 0;
		foreach ( Backup::all() as $b ) {
			$held += (int) $b['bytes'];
		}
		$size = static function ( $bytes ) {
			return size_format( (int) $bytes, 1 ) ? size_format( (int) $bytes, 1 ) : '0 B';
		};

		echo '<div class="wpcu-cards wpcu-stats">';
		/* translators: %s: date */
		$this->card( __( 'Image files on the server', 'wp-cleanup' ), number_format_i18n( $all_files ) . ' · ' . $size( $all_bytes ), sprintf( __( 'Every image file under uploads, as of %s.', 'wp-cleanup' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $report['created'] ) ) );
		/* translators: 1: images, 2: files */
		$this->card( __( 'Media Library images', 'wp-cleanup' ), number_format_i18n( count( $cat['library'] ) ) . ' · ' . $size( $lib_bytes ), sprintf( __( '%1$s images with %2$s files on disk (all sizes and fallbacks).', 'wp-cleanup' ), number_format_i18n( count( $cat['library'] ) ), number_format_i18n( $lib_files ) ) );
		$this->card( __( 'Files without a Media Library attachment', 'wp-cleanup' ), number_format_i18n( (int) $cat['unregistered_count'] ) . ' · ' . $size( $cat['unregistered_bytes'] ), __( 'Listed at the bottom of this page.', 'wp-cleanup' ) );
		$this->card( __( 'Held in backup sets', 'wp-cleanup' ), $size( $held ), __( 'Freed when you delete those sets on the Backups tab.', 'wp-cleanup' ) );
		echo '</div>';

		if ( ! empty( $cat['formats'] ) ) {
			$formats = $cat['formats'];
			uasort(
				$formats,
				static function ( $a, $b ) {
					return $b['bytes'] - $a['bytes'];
				}
			);
			echo '<div class="wpcu-scroll"><table class="widefat striped wpcu-format-table"><thead><tr><th>' . esc_html__( 'Format', 'wp-cleanup' ) . '</th><th class="num">' . esc_html__( 'Files', 'wp-cleanup' ) . '</th><th class="num">' . esc_html__( 'In Media Library', 'wp-cleanup' ) . '</th><th class="num">' . esc_html__( 'Without attachment', 'wp-cleanup' ) . '</th><th class="num">' . esc_html__( 'Space', 'wp-cleanup' ) . '</th><th>' . esc_html__( 'Share of space', 'wp-cleanup' ) . '</th></tr></thead><tbody>';
			foreach ( $formats as $name => $f ) {
				$share = $all_bytes ? round( 100 * $f['bytes'] / $all_bytes, 1 ) : 0;
				echo '<tr><td>' . esc_html( $name ) . '</td><td class="num">' . esc_html( number_format_i18n( $f['files'] ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $f['library'] ) ) . '</td><td class="num">' . esc_html( number_format_i18n( $f['unregistered'] ) ) . '</td><td class="num">' . esc_html( $size( $f['bytes'] ) ) . '</td><td><span class="wpcu-bar"><span style="width:' . esc_attr( (string) $share ) . '%"></span></span> ' . esc_html( number_format_i18n( $share, 1 ) ) . '%</td></tr>';
			}
			echo '</tbody></table></div>';
		}
		if ( ! empty( $report['inventory_stale'] ) ) {
			echo '<p class="description">' . esc_html__( 'These totals are from the last full check; check the library again after converting or removing images.', 'wp-cleanup' ) . '</p>';
		}
	}

	/** Saved image data that disagrees with the files, found automatically on every check. */
	private function render_media_issues( array $report ) {
		if ( ! isset( $report['issues'] ) ) {
			return;
		}
		$issues = (array) $report['issues'];
		if ( ! $issues ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Image data check: every image\'s saved paths, dimensions, sizes and markers match its files.', 'wp-cleanup' ) . '</p></div>';
			return;
		}
		$fixable = 0;
		$manual  = 0;
		foreach ( $issues as $list ) {
			foreach ( $list as $issue ) {
				$issue['fixable'] ? ++$fixable : ++$manual;
			}
		}
		echo '<div class="notice notice-warning inline wpcu-issues"><p><strong>' . esc_html(
			/* translators: 1: images, 2: fixable problems, 3: manual */
			sprintf( __( 'Image data check found problems in %1$d image(s): %2$d can be repaired automatically, %3$d need your attention.', 'wp-cleanup' ), count( $issues ), $fixable, $manual )
		) . '</strong></p>';
		echo '<details><summary>' . esc_html__( 'Show the problems', 'wp-cleanup' ) . '</summary><ul class="wpcu-issue-list">';
		foreach ( $issues as $id => $list ) {
			$id = (int) $id;
			echo '<li><span class="wpcu-catalog-thumb">' . self::thumb( $id, 32 ) . '</span> <a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '"><code>' . esc_html( (string) get_post_meta( $id, '_wp_attached_file', true ) ) . '</code></a><ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- thumb() escapes its attributes.
			foreach ( $list as $issue ) {
				echo '<li class="' . ( $issue['fixable'] ? 'wpcu-issue-fix' : 'wpcu-issue-manual' ) . '">' . ( $issue['fixable'] ? '' : '<strong>' . esc_html__( 'Needs attention:', 'wp-cleanup' ) . '</strong> ' ) . esc_html( $issue['message'] ) . '</li>';
			}
			echo '</ul></li>';
		}
		echo '</ul></details>';
		if ( $fixable ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpcu-inline-form wpcu-repair-form">';
			wp_nonce_field( 'wpcu_media_repair' );
			echo '<input type="hidden" name="action" value="wpcu_media_repair">';
			/* translators: %d: problems */
			submit_button( sprintf( _n( 'Repair %d problem', 'Repair %d problems', $fixable, 'wp-cleanup' ), $fixable ), 'primary', 'submit', false );
			echo ' <span class="description">' . esc_html__( 'Changes only WordPress data (paths, type, dimensions, size lists, ALPS marker), never image files. Each image is re-checked first and every repair can be undone on the Backups tab.', 'wp-cleanup' ) . '</span></form>';
		}
		echo '</div>';
	}

	/** Current image attachments, their variants, and files without an attachment. */
	private function render_file_catalog( array $report ) {
		echo '<div class="wpcu-section-head" id="wpcu-file-catalog"><h2>' . esc_html__( 'Current image files on the server', 'wp-cleanup' ) . '</h2></div>';
		if ( empty( $report['file_catalog'] ) ) {
			echo '<p class="description">' . esc_html__( 'Check the media library again to list current files.', 'wp-cleanup' ) . '</p>';
			return;
		}
		$catalog = $report['file_catalog'];
		echo '<p class="description wpcu-media-intro">' . esc_html__( 'Each Media Library image appears once below. Expand it to see its original, generated sizes, AVIF alternatives, JPEG fallback and extra files that the scanner associates with it. Files without any attachment are listed separately.', 'wp-cleanup' ) . '</p>';
		if ( ! empty( $report['inventory_stale'] ) ) {
			echo '<p class="description">' . esc_html__( 'This file list is from the last full check. Check the media library again after converting or removing images.', 'wp-cleanup' ) . '</p>';
		}
		$labels = array(
			'attached' => __( 'Main file', 'wp-cleanup' ),
			'jpeg_fallback' => __( 'JPEG fallback', 'wp-cleanup' ),
			'original' => __( 'Original source', 'wp-cleanup' ),
			'size' => __( 'Generated size', 'wp-cleanup' ),
			'avif_full' => __( 'Full AVIF', 'wp-cleanup' ),
			'avif_small' => __( 'Small AVIF', 'wp-cleanup' ),
			'backup' => __( 'WordPress edit backup', 'wp-cleanup' ),
			'stray' => __( 'Extra file', 'wp-cleanup' ),
		);
		echo '<details class="wpcu-catalog-list"><summary>' . esc_html( sprintf( _n( '%d Media Library image', '%d Media Library images', count( $catalog['library'] ), 'wp-cleanup' ), count( $catalog['library'] ) ) ) . '</summary>';
		foreach ( $catalog['library'] as $image ) {
			$id = (int) $image['id'];
			$primary = $image['primary'] ? $image['primary'] : __( 'Missing or offloaded main file', 'wp-cleanup' );
			echo '<details class="wpcu-catalog-image"><summary><span class="wpcu-catalog-thumb">' . self::thumb( $id, 40 ) . '</span><span><a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '"><code>' . esc_html( $primary ) . '</code></a> <span class="wpcu-muted">· ' . esc_html( (string) $image['mime'] ) . ' · ' . esc_html( sprintf( _n( '%d file', '%d files', count( $image['files'] ), 'wp-cleanup' ), count( $image['files'] ) ) ) . '</span></span></summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- thumb() escapes its attributes.
			if ( ! $image['files'] ) {
				echo '<p>' . esc_html__( 'No local file could be inventoried.', 'wp-cleanup' ) . '</p>';
			} else {
				echo '<div class="wpcu-scroll"><table class="widefat striped wpcu-catalog-table"><thead><tr><th>' . esc_html__( 'Role', 'wp-cleanup' ) . '</th><th>' . esc_html__( 'File on server', 'wp-cleanup' ) . '</th><th>' . esc_html__( 'Dimensions', 'wp-cleanup' ) . '</th><th>' . esc_html__( 'Disk', 'wp-cleanup' ) . '</th></tr></thead><tbody>';
				foreach ( $image['files'] as $file ) {
					$role = isset( $labels[ $file['role'] ] ) ? $labels[ $file['role'] ] : $file['role'];
					if ( 'size' === $file['role'] && $file['size'] ) {
						$role .= ': ' . $file['size'];
					}
					$dimensions = $file['width'] && $file['height'] ? $file['width'] . '×' . $file['height'] : '–';
					echo '<tr><td>' . esc_html( $role ) . '</td><td><code>' . esc_html( $file['path'] ) . '</code></td><td>' . esc_html( $dimensions ) . '</td><td>' . esc_html( size_format( $file['bytes'], 1 ) ) . '</td></tr>';
				}
				echo '</tbody></table></div>';
			}
			echo '</details>';
		}
		echo '</details>';
		echo '<details class="wpcu-unregistered"><summary>' . esc_html( sprintf( _n( '%d image file without a Media Library attachment', '%d image files without a Media Library attachment', $catalog['unregistered_count'], 'wp-cleanup' ), $catalog['unregistered_count'] ) ) . ' · ' . esc_html( size_format( $catalog['unregistered_bytes'], 1 ) ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'These files exist in uploads but are not part of an inventoried Media Library image. They may still be used by theme code, CSS, plugins or external links. Moving selected files rechecks WordPress database references and keeps the files in a restorable backup.', 'wp-cleanup' ) . '</p>';
		if ( ! empty( $catalog['incomplete'] ) ) {
			echo '<p class="description">' . esc_html__( 'Some uploads folders could not be read, so this list may be incomplete.', 'wp-cleanup' ) . '</p>';
		}
		if ( $catalog['unregistered'] ) {
			echo '<form method="post" class="wpcu-orphan-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'wpcu_media_file_remove' );
			echo '<input type="hidden" name="action" value="wpcu_media_file_remove">';
			echo '<div class="wpcu-scroll"><table class="widefat striped wpcu-items wpcu-sortable"><thead><tr><th class="check-column"></th>';
			echo self::sort_heading( __( 'File on server', 'wp-cleanup' ), 'text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes output.
			echo self::sort_heading( __( 'Dimensions', 'wp-cleanup' ), 'number' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes output.
			echo self::sort_heading( __( 'Disk', 'wp-cleanup' ), 'number' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes output.
			echo '</tr></thead><tbody>';
			foreach ( $catalog['unregistered'] as $file ) {
				$w = isset( $file['width'] ) ? (int) $file['width'] : 0;
				$h = isset( $file['height'] ) ? (int) $file['height'] : 0;
				/* translators: %s: file path */
				echo '<tr><th scope="row" class="check-column"><input type="checkbox" name="paths[]" value="' . esc_attr( $file['path'] ) . '" aria-label="' . esc_attr( sprintf( __( 'Select %s', 'wp-cleanup' ), $file['path'] ) ) . '"></th><td><code>' . esc_html( $file['path'] ) . '</code></td><td data-sort-value="' . (int) ( $w * $h ) . '">' . ( $w && $h ? esc_html( $w . '×' . $h ) : '<span class="wpcu-muted">–</span>' ) . '</td><td data-sort-value="' . (int) $file['bytes'] . '">' . esc_html( size_format( $file['bytes'], 1 ) ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
			echo '<div class="wpcu-actions"><p><label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'I have a recent full backup of files and database', 'wp-cleanup' ) . '</label></p><p><button type="submit" class="button button-primary">' . esc_html__( 'Move selected server files to backup', 'wp-cleanup' ) . '</button> <span class="wpcu-selection-count" aria-live="polite"></span></p><p class="description">' . esc_html__( 'Selected files are moved one at a time into the same backup set. Files mentioned in WordPress data are refused; theme code, CSS and external links must be checked separately.', 'wp-cleanup' ) . '</p><p><button type="button" class="button wpcu-removal-stop" hidden>' . esc_html__( 'Stop after current file', 'wp-cleanup' ) . '</button> <progress class="wpcu-removal-progress" max="1" value="0" hidden></progress> <span class="wpcu-removal-status" role="status" aria-live="polite"></span></p><ul class="wpcu-removal-errors"></ul></div></form>';
		}
		if ( $catalog['unregistered_count'] > count( $catalog['unregistered'] ) ) {
			echo '<p class="description">' . esc_html( sprintf( __( 'Showing the first %d paths. The total above counts every unregistered image.', 'wp-cleanup' ), count( $catalog['unregistered'] ) ) ) . '</p>';
		}
		echo '</details>';
	}

	private function render_backups() {
		$backups = Backup::all();
		echo '<p class="description">' . esc_html__( 'Each cleanup writes a backup set first. Restoring replays the saved rows, re-schedules cron events and moves folders back. Items recreated since then are not overwritten.', 'wp-cleanup' ) . '</p>';
		if ( ! $backups ) {
			echo '<div class="wpcu-empty"><p>' . esc_html__( 'No backup sets yet.', 'wp-cleanup' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Backup set', 'wp-cleanup' ) . '</th><th>' . esc_html__( 'Created', 'wp-cleanup' ) . '</th><th>' . esc_html__( 'By', 'wp-cleanup' ) . '</th><th>' . esc_html__( 'Items', 'wp-cleanup' ) . '</th><th class="num">' . esc_html__( 'Size', 'wp-cleanup' ) . '</th><th>' . esc_html__( 'Restored', 'wp-cleanup' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $backups as $b ) {
			$deleted = array_filter(
				$b['items'],
				static function ( $i ) {
					return 'deleted' === $i['result'];
				}
			);
			echo '<tr><td><code>' . esc_html( $b['id'] ) . '</code><details><summary>' . esc_html__( 'Contents', 'wp-cleanup' ) . '</summary><ul class="wpcu-details">';
			foreach ( $b['items'] as $n => $i ) {
				echo '<li>' . esc_html( $i['type'] . ': ' . $i['label'] . ' — ' . $i['result'] . ( $i['message'] ? ' (' . $i['message'] . ')' : '' ) . ( $i['restored'] ? ' — ' . __( 'restored', 'wp-cleanup' ) : '' ) );
				$extra = isset( $i['extra'] ) ? $i['extra'] : array();
				if ( ! empty( $extra['moved'] ) ) {
					echo '<details><summary>' . esc_html( sprintf( __( '%d original files moved to backup', 'wp-cleanup' ), count( $extra['moved'] ) ) ) . '</summary><ul>';
					foreach ( $extra['moved'] as $file_index => $path ) {
						echo '<li><code>' . esc_html( $path ) . '</code>';
						if ( isset( $extra['moved_info'][ $path ] ) ) {
							echo ' <span class="wpcu-muted">' . esc_html( self::file_info_text( $extra['moved_info'][ $path ] ) ) . '</span>';
						}
						if ( empty( $i['restored'] ) ) {
							$url = add_query_arg( array( 'action' => 'wpcu_backup_download', 'backup' => $b['id'], 'item' => (int) $n, 'file' => (int) $file_index ), admin_url( 'admin-post.php' ) );
							echo ' <a href="' . esc_url( wp_nonce_url( $url, 'wpcu_backup_download_' . $b['id'] ) ) . '">' . esc_html__( 'Download original', 'wp-cleanup' ) . '</a>';
						}
						echo '</li>';
					}
					echo '</ul></details>';
				}
				if ( ! empty( $extra['reference_changes'] ) ) {
					echo '<details><summary>' . esc_html( sprintf( __( '%d stored path entries (including revisions)', 'wp-cleanup' ), count( $extra['reference_changes'] ) ) ) . '</summary><ul>';
					foreach ( $extra['reference_changes'] as $change ) {
						echo '<li class="wpcu-reference-change">';
						echo '<div><strong>' . esc_html__( 'Location', 'wp-cleanup' ) . ':</strong> ' . esc_html( $change['where'] ) . '</div>';
						echo '<div><strong>' . esc_html__( 'Original', 'wp-cleanup' ) . ':</strong> <code>' . esc_html( $change['from'] ) . '</code> <span class="wpcu-muted">' . esc_html( self::file_info_text( isset( $change['from_info'] ) ? $change['from_info'] : null ) ) . '</span></div>';
						echo '<div><strong>' . esc_html__( 'New', 'wp-cleanup' ) . ':</strong> <code>' . esc_html( $change['to'] ) . '</code> <span class="wpcu-muted">' . esc_html( self::file_info_text( isset( $change['to_info'] ) ? $change['to_info'] : null ) ) . '</span></div>';
						echo '</li>';
					}
					echo '</ul></details>';
				}
				if ( ! empty( $extra['created_info'] ) ) {
					/* translators: %d: files */
					echo '<details><summary>' . esc_html( sprintf( _n( '%d new file', '%d new files', count( $extra['created_info'] ), 'wp-cleanup' ), count( $extra['created_info'] ) ) ) . '</summary><ul>';
					foreach ( $extra['created_info'] as $path => $info ) {
						echo '<li>' . ( ! empty( $info['role'] ) ? esc_html( $info['role'] ) . ': ' : '' ) . '<code>' . esc_html( $path ) . '</code> <span class="wpcu-muted">' . esc_html( self::file_info_text( $info ) ) . '</span></li>';
					}
					echo '</ul></details>';
				}
				if ( ! empty( $extra['fixed'] ) ) {
					echo '<ul class="wpcu-issue-list">';
					foreach ( (array) $extra['fixed'] as $line ) {
						echo '<li>✓ ' . esc_html( $line ) . '</li>';
					}
					echo '</ul>';
				}
				if ( 'deleted' === $i['result'] && empty( $i['restored'] ) ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpcu-inline-form">';
					wp_nonce_field( 'wpcu_restore_item' );
					echo '<input type="hidden" name="action" value="wpcu_restore"><input type="hidden" name="backup" value="' . esc_attr( $b['id'] ) . '"><input type="hidden" name="item" value="' . (int) $n . '">';
					submit_button( 'media' === $i['type'] || 'media_repair' === $i['type'] || 'media_delete' === $i['type'] || 'media_merge_rewrite' === $i['type'] ? __( 'Restore this image only', 'wp-cleanup' ) : __( 'Restore this item only', 'wp-cleanup' ), 'secondary', 'submit', false );
					echo '</form>';
				}
				echo '</li>';
			}
			echo '</ul></details></td>';
			echo '<td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $b['created'] ) ) . '</td>';
			echo '<td>' . esc_html( $b['user'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( count( $deleted ) ) ) . '</td>';
			echo '<td class="num">' . esc_html( size_format( $b['bytes'], 1 ) ? size_format( $b['bytes'], 1 ) : '0 B' ) . '</td>';
			echo '<td>' . ( $b['restored'] ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $b['restored'] ) ) : '<span class="wpcu-muted">–</span>' ) . '</td>';
			echo '<td class="wpcu-backup-actions">';
			foreach ( array( 'wpcu_restore' => __( 'Restore', 'wp-cleanup' ), 'wpcu_delete_backup' => __( 'Delete backup', 'wp-cleanup' ) ) as $action => $label ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpcu-' . esc_attr( $action ) . '">';
				wp_nonce_field( $action );
				echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="backup" value="' . esc_attr( $b['id'] ) . '">';
				submit_button( $label, 'wpcu_restore' === $action ? 'secondary' : 'delete', 'submit', false );
				echo '</form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}

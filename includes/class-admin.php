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
		add_action( 'admin_post_wpcu_delete_backup', array( $this, 'handle_delete_backup' ) );
		add_action( 'admin_post_wpcu_media_settings', array( $this, 'handle_media_settings' ) );
		add_action( 'admin_post_wpcu_media_scan', array( $this, 'handle_media_scan' ) );
		add_action( 'wp_ajax_wpcu_media_batch', array( $this, 'ajax_media_batch' ) );
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
				'confirmMedia'   => __( "Convert %d image(s) to AVIF?\n\nEvery other size and the original are moved into a backup set. Take a full backup of files and database first.", 'wp-cleanup' ),
				'mediaDone'      => __( 'Finished: %1$d converted, %2$d failed, %3$d skipped. Old files are in backup set %4$s. Delete it on the Backups tab once the site looks right, to free the space.', 'wp-cleanup' ),
				'mediaStopped'   => __( 'Stopped.', 'wp-cleanup' ),
				'mediaConfirmBox' => __( 'Please tick the backup confirmation first.', 'wp-cleanup' ),
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
	 * @param string $action Nonce action.
	 */
	private function guard( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wp-cleanup' ), 403 );
		}
		check_admin_referer( $action );
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
		$this->guard( 'wpcu_scan' );
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
		$this->guard( 'wpcu_clean' );
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
		$this->guard( 'wpcu_restore' );
		$backup = Backup::open( isset( $_POST['backup'] ) ? sanitize_text_field( wp_unslash( $_POST['backup'] ) ) : '' );
		if ( ! $backup ) {
			$this->notice( 'error', __( 'Backup set not found.', 'wp-cleanup' ) );
			$this->back( array( 'tab' => 'backups' ) );
		}
		$report = $backup->restore();
		try {
			( new Scanner() )->scan_and_store(); // Keep the lists in step with what came back.
			if ( in_array( 'media', wp_list_pluck( $backup->manifest['items'], 'type' ), true ) && Media_Report::last() ) {
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

	public function handle_delete_backup() {
		$this->guard( 'wpcu_delete_backup' );
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
		$this->guard( 'wpcu_media_settings' );
		Media_Policy::save(
			array(
				'full_max'   => isset( $_POST['full_max'] ) ? absint( $_POST['full_max'] ) : 0,
				'small_max'  => isset( $_POST['small_max'] ) ? absint( $_POST['small_max'] ) : 0,
				'small_name' => isset( $_POST['small_name'] ) ? sanitize_key( wp_unslash( $_POST['small_name'] ) ) : '',
				'set_flag'   => ! empty( $_POST['set_flag'] ),
			)
		);
		$this->notice( 'success', __( 'Image policy saved. Check the library again to see its effect.', 'wp-cleanup' ) );
		$this->back( array( 'tab' => 'images' ) );
	}

	public function handle_media_scan() {
		$this->guard( 'wpcu_media_scan' );
		try {
			$r = Media_Report::build_and_store();
			/* translators: 1: images, 2: seconds */
			$this->notice( 'success', sprintf( __( 'Checked %1$d images in %2$s s.', 'wp-cleanup' ), $r['total'], $r['duration'] ) );
		} catch ( \Exception $e ) {
			$this->notice( 'error', $e->getMessage() );
		}
		$this->back( array( 'tab' => 'images' ) );
	}

	public function ajax_media_batch() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'wp-cleanup' ) ), 403 );
		}
		check_ajax_referer( 'wpcu_media_batch' );
		$ids = isset( $_POST['ids'] ) ? array_slice( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ), 0, 10 ) : array();
		$bid = isset( $_POST['backup'] ) ? sanitize_text_field( wp_unslash( $_POST['backup'] ) ) : '';
		try {
			$backup = '' !== $bid ? Backup::open( $bid ) : Backup::start();
			if ( ! $backup ) {
				throw new \RuntimeException( __( 'Backup set not found.', 'wp-cleanup' ) );
			}
			$results = array();
			foreach ( $ids as $id ) {
				$results[] = Media_Converter::convert( $id, $backup );
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

		echo '<div class="wpcu-media-intro"><p>' . esc_html(
			sprintf(
				/* translators: 1: full size, 2: small size name, 3: small size */
				__( 'Converts each JPEG, PNG or AVIF image to one AVIF of at most %1$d px plus one "%2$s" AVIF of at most %3$d px. Every other size, the original, the -scaled copy, stray thumbnails from old themes and .webp sidecars are moved into a backup set. Links to the old files in posts, meta and options are rewritten to the kept files.', 'wp-cleanup' ),
				$s['full_max'],
				$s['small_name'],
				$s['small_max']
			)
		) . '</p><p class="description">' . esc_html__( 'GIF and WebP (may be animated), site icons, headers and offloaded files are never touched. Space is freed when you delete the backup set on the Backups tab after checking the site.', 'wp-cleanup' ) . '</p></div>';

		if ( ! $avif ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'This server cannot write AVIF images. Conversion needs WordPress 6.5+ and GD or Imagick built with AVIF support.', 'wp-cleanup' ) . '</p></div>';
		}

		echo '<details class="wpcu-media-settings"><summary>' . esc_html__( 'Image policy', 'wp-cleanup' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( $action ) . '">';
		wp_nonce_field( 'wpcu_media_settings' );
		echo '<input type="hidden" name="action" value="wpcu_media_settings">';
		echo '<p><label>' . esc_html__( 'Full image: longest side at most', 'wp-cleanup' ) . ' <input type="number" name="full_max" min="320" max="8192" value="' . esc_attr( $s['full_max'] ) . '"> px</label></p>';
		echo '<p><label>' . esc_html__( 'Small image size name', 'wp-cleanup' ) . ' <input type="text" name="small_name" value="' . esc_attr( $s['small_name'] ) . '"></label> <label>' . esc_html__( 'longest side at most', 'wp-cleanup' ) . ' <input type="number" name="small_max" min="64" value="' . esc_attr( $s['small_max'] ) . '"> px</label></p>';
		echo '<p><label><input type="checkbox" name="set_flag" value="1"' . checked( $s['set_flag'], true, false ) . '> ' . esc_html__( 'Mark converted images for the ALPS theme (_alps_two_size_upload), so its templates map old size names to these two files', 'wp-cleanup' ) . '</label></p>';
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

		echo '<div class="wpcu-cards">';
		$this->card( __( 'Images to convert', 'wp-cleanup' ), number_format_i18n( count( $report['items'] ) ) );
		$this->card( __( 'Their files now', 'wp-cleanup' ), number_format_i18n( $report['files'] ) . ' · ' . ( $report['bytes'] ? size_format( $report['bytes'], 1 ) : '0 B' ) );
		$this->card( __( 'Stray files among them', 'wp-cleanup' ), number_format_i18n( $report['strays'] ) . ' · ' . ( $report['stray_b'] ? size_format( $report['stray_b'], 1 ) : '0 B' ), __( 'Sizes no metadata lists, e.g. from old themes, and .webp sidecars.', 'wp-cleanup' ) );
		$this->card( __( 'Already compliant / skipped', 'wp-cleanup' ), number_format_i18n( $report['compliant'] ) . ' / ' . number_format_i18n( $report['skipped'] ) );
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
		if ( ! $report['items'] ) {
			echo '<div class="wpcu-empty"><p>' . esc_html__( 'Nothing to convert.', 'wp-cleanup' ) . '</p></div>';
			return;
		}

		echo '<form class="wpcu-media-form">';
		echo '<div class="wpcu-filters"><input type="search" class="wpcu-filter-search" placeholder="' . esc_attr__( 'Filter by file name…', 'wp-cleanup' ) . '"> <span class="wpcu-visible-count"></span></div>';
		echo '<table class="widefat striped wpcu-items"><thead><tr><td class="check-column"><input type="checkbox" class="wpcu-check-all" aria-label="' . esc_attr__( 'Select all visible', 'wp-cleanup' ) . '"></td>';
		echo '<th>' . esc_html__( 'File', 'wp-cleanup' ) . '</th><th>' . esc_html__( 'Format', 'wp-cleanup' ) . '</th><th class="num">' . esc_html__( 'Size', 'wp-cleanup' ) . '</th><th class="num">' . esc_html__( 'Files', 'wp-cleanup' ) . '</th><th class="num">' . esc_html__( 'Strays', 'wp-cleanup' ) . '</th><th class="num">' . esc_html__( 'Disk', 'wp-cleanup' ) . '</th><th>' . esc_html__( 'Result', 'wp-cleanup' ) . '</th></tr></thead><tbody>';
		foreach ( $report['items'] as $i ) {
			printf( '<tr data-search="%s" data-id="%d">', esc_attr( strtolower( $i['file'] . ' ' . $i['title'] ) ), (int) $i['id'] );
			echo '<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="' . esc_attr( $i['id'] ) . '"></th>';
			echo '<td class="wpcu-name"><a href="' . esc_url( (string) get_edit_post_link( $i['id'] ) ) . '"><code>' . esc_html( $i['file'] ) . '</code></a></td>';
			echo '<td>' . esc_html( str_replace( 'image/', '', $i['mime'] ) ) . '</td>';
			echo '<td class="num">' . esc_html( $i['width'] . '×' . $i['height'] ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( $i['files'] ) ) . '</td>';
			echo '<td class="num">' . ( $i['strays'] ? esc_html( number_format_i18n( $i['strays'] ) ) : '<span class="wpcu-muted">–</span>' ) . '</td>';
			echo '<td class="num">' . esc_html( size_format( $i['bytes'], 1 ) ) . '</td>';
			echo '<td class="wpcu-result"></td></tr>';
		}
		echo '</tbody></table>';
		echo '<div class="wpcu-actions">';
		echo '<label><input type="checkbox" class="wpcu-media-confirm"> <strong>' . esc_html__( 'I have a recent full backup of files and database', 'wp-cleanup' ) . '</strong></label>';
		echo '<p><button type="button" class="button button-primary wpcu-delete wpcu-media-run" data-scope="selected"' . disabled( $avif, false, false ) . '>' . esc_html__( 'Convert selected', 'wp-cleanup' ) . '</button> ';
		/* translators: %d: images */
		echo '<button type="button" class="button wpcu-media-run" data-scope="all"' . disabled( $avif, false, false ) . '>' . esc_html( sprintf( __( 'Convert all %d', 'wp-cleanup' ), count( $report['items'] ) ) ) . '</button> ';
		echo '<button type="button" class="button wpcu-media-stop" hidden>' . esc_html__( 'Stop after this batch', 'wp-cleanup' ) . '</button></p>';
		echo '<p><progress class="wpcu-media-progress" max="100" value="0" hidden></progress> <span class="wpcu-media-status" aria-live="polite"></span></p>';
		echo '</div></form>';
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
			foreach ( $b['items'] as $i ) {
				echo '<li>' . esc_html( $i['type'] . ': ' . $i['label'] . ' — ' . $i['result'] . ( $i['message'] ? ' (' . $i['message'] . ')' : '' ) . ( $i['restored'] ? ' — ' . __( 'restored', 'wp-cleanup' ) : '' ) ) . '</li>';
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

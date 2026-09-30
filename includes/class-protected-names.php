<?php
/**
 * Names that belong to WordPress core and must never be offered for deletion.
 *
 * Lists were extracted from WordPress 7.1 source (populate_options(), option,
 * meta and cron calls in wp-includes/wp-admin) and extended with dynamic
 * name patterns core builds at runtime.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Protected_Names {

	const CORE_OPTIONS = array(
		'_split_terms', '_wp_suggested_policy_text_has_changed', 'active_plugins', 'admin_email', 'admin_email_lifespan',
		'adminhash', 'auto_core_update_failed', 'auto_core_update_notified', 'auto_plugin_theme_update_emails',
		'auto_update_core_dev', 'auto_update_core_major', 'auto_update_core_minor', 'auto_update_plugins', 'auto_update_themes',
		'avatar_default', 'avatar_rating', 'blacklist_keys', 'blocklist_keys', 'blog_charset', 'blog_public',
		'blogdescription', 'blogname', 'can_compress_scripts', 'category_base', 'category_children', 'close_comments_days_old',
		'close_comments_for_old_posts', 'comment_max_links', 'comment_moderation', 'comment_order', 'comment_previously_approved',
		'comment_registration', 'comment_whitelist', 'comments_notify', 'comments_per_page', 'cron', 'current_theme',
		'customize_stashed_theme_mods', 'dashboard_widget_options', 'date_format', 'db_upgraded', 'db_version',
		'default_category', 'default_comment_status', 'default_comments_page', 'default_email_category', 'default_link_category',
		'default_ping_status', 'default_pingback_flag', 'default_post_format', 'default_privacy_policy_content', 'default_role',
		'disallowed_keys', 'dismissed_update_core', 'embed_autourls', 'embed_size_h', 'embed_size_w',
		'finished_splitting_shared_terms', 'finished_updating_comment_type', 'fresh_site', 'ftp_credentials', 'gmt_offset',
		'hack_file', 'home', 'html_type', 'https_detection_errors', 'https_migration_required', 'image_default_align',
		'image_default_link_type', 'image_default_size', 'initial_db_version', 'large_size_h', 'large_size_w',
		'link_manager_enabled', 'links_recently_updated_append', 'links_recently_updated_prepend', 'links_recently_updated_time',
		'links_updated_date_format', 'mailserver_login', 'mailserver_pass', 'mailserver_port', 'mailserver_url',
		'medium_large_size_h', 'medium_large_size_w', 'medium_size_h', 'medium_size_w', 'moderation_keys', 'moderation_notify',
		'nav_menu_options', 'new_admin_email', 'page_comments', 'page_for_posts', 'page_on_front', 'permalink_structure',
		'ping_sites', 'post_count', 'posts_per_page', 'posts_per_rss', 'recently_activated', 'recently_edited',
		'recovery_keys', 'recovery_mode_email_last_sent', 'require_name_email', 'rewrite_rules', 'rss_use_excerpt',
		'show_avatars', 'show_comments_cookies_opt_in', 'show_on_front', 'sidebars_widgets', 'site_icon', 'site_logo',
		'siteurl', 'start_of_week', 'sticky_posts', 'stylesheet', 'stylesheet_root', 'tag_base', 'template',
		'template_root', 'theme_switch_menu_locations', 'theme_switched', 'theme_switched_via_customizer', 'thread_comments',
		'thread_comments_depth', 'thumbnail_crop', 'thumbnail_size_h', 'thumbnail_size_w', 'time_format', 'timezone_string',
		'uninstall_plugins', 'upgrade_500_was_gutenberg_active', 'upload_path', 'upload_url_path', 'uploads_use_yearmonth_folders',
		'use_balanceTags', 'use_smilies', 'use_trackback', 'user_count', 'users_can_register', 'widget_block',
		'wp_attachment_pages_enabled', 'wp_calendar_block_has_published_posts', 'wp_force_deactivated_plugins',
		'wp_notes_notify', 'wp_page_for_privacy_policy', 'secret_key', 'auth_key', 'auth_salt', 'logged_in_key',
		'logged_in_salt', 'nonce_key', 'nonce_salt', 'secure_auth_key', 'secure_auth_salt', 'recovery_mode_auth_key',
		'recovery_mode_auth_salt', 'WPLANG', 'wp_user_roles',
	);

	/** Regex patterns for options core names dynamically. */
	const CORE_OPTION_PATTERNS = array(
		'/^widget_/',
		'/^theme_mods_/',
		'/^mods_/',
		'/^_transient_/',
		'/^_site_transient_/',
		'/^default_term_/',
		'/^[a-z0-9_]*_children$/', // Hierarchical taxonomy caches ({taxonomy}_children).
		'/^user_roles$/',
		'/^auto_updater\.lock$/',
		'/^core_updater\.lock$/',
		'/^[a-z0-9_]+user_roles$/', // {$wpdb->prefix}user_roles.
		'/^plugins_delete_result_\d+$/', // Per-user result of a bulk plugin delete.
		'/^new_user_[A-Za-z0-9]{20}$/',  // Pending "add existing user" invitation (multisite).
	);

	const CORE_CRON_HOOKS = array(
		'delete_expired_transients', 'do_pings', 'importer_scheduled_cleanup', 'publish_future_post',
		'recovery_mode_clean_expired_keys', 'update_network_counts', 'upgrader_scheduled_cleanup',
		'wp_delete_temp_updater_backups', 'wp_https_detection', 'wp_maybe_auto_update', 'wp_privacy_delete_old_export_files',
		'wp_privacy_personal_data_cleanup_requests', 'wp_scheduled_auto_draft_delete', 'wp_scheduled_delete',
		'wp_site_health_scheduled_check', 'wp_split_shared_term_batch', 'wp_update_comment_type_batch', 'wp_update_plugins',
		'wp_update_themes', 'wp_update_user_counts', 'wp_version_check',
	);

	const CORE_POST_META = array(
		'_cover_hash', '_customize_changeset_uuid', '_customize_draft_post_name', '_customize_restore_dismissed', '_edit_last',
		'_edit_lock', '_encloseme', '_export_data_grouped', '_export_data_raw', '_export_file_name', '_export_file_path',
		'_export_file_url', '_menu_item_classes', '_menu_item_menu_item_parent', '_menu_item_object', '_menu_item_object_id',
		'_menu_item_orphaned', '_menu_item_target', '_menu_item_type', '_menu_item_url', '_menu_item_xfn', '_pingme',
		'_source_url', '_starter_content_theme', '_thumbnail_id', '_trackbackme', '_wp_admin_notified', '_wp_attached_file',
		'_wp_attachment_backup_sizes', '_wp_attachment_context', '_wp_attachment_image_alt',
		'_wp_attachment_is_custom_background', '_wp_attachment_is_custom_header', '_wp_attachment_metadata',
		'_wp_desired_post_slug', '_wp_font_face_file', '_wp_ignored_hooked_blocks', '_wp_old_date', '_wp_old_slug',
		'_wp_page_template', '_wp_suggested_privacy_policy_content', '_wp_trash_meta_comments_status', '_wp_trash_meta_status',
		'_wp_trash_meta_time', '_wp_user_notified', '_wp_user_request_completed_timestamp',
		'_wp_user_request_confirmed_timestamp', '_wp_attachment_image_alt', 'enclosure', 'footnotes', 'origin',
		'is_wp_suggestion', '_wp_note_status',
	);

	/** Post meta keys core builds dynamically. */
	const CORE_POST_META_PATTERNS = array(
		'/^_oembed_(time_)?[0-9a-f]{32}$/',                // oEmbed cache per embedded URL (WP_Embed).
		'/^_format_[a-z_]+$/',                             // Post format fields (_format_url, _format_quote_source_name…).
		'/^_wp_attachment_custom_header_last_used_.+$/', // Custom header use time per theme.
	);

	const CORE_USER_META = array(
		'_new_email', 'admin_color', 'aim', 'comment_shortcuts', 'community-events-location', 'default_password_nag',
		'description', 'dismissed_wp_pointers', 'enable_custom_fields', 'first_name', 'icq', 'jabber', 'last_name', 'locale',
		'nav_menu_recently_edited', 'nickname', 'primary_blog', 'rich_editing', 'session_tokens', 'show_admin_bar_front',
		'show_welcome_panel', 'source_domain', 'syntax_highlighting', 'use_ssl', 'wporg_favorites', 'yim', 'msn',
		'_application_passwords', 'managenav-menuscolumnshidden',
	);

	/** User meta core stores with the table prefix, e.g. wp_capabilities. */
	const CORE_USER_META_PREFIXED = array(
		'capabilities', 'user_level', 'user-settings', 'user-settings-time', 'dashboard_quick_press_last_post_id',
		'media_library_mode', 'persisted_preferences', 'user_roles',
	);

	const CORE_USER_META_PATTERNS = array(
		'/^metaboxhidden_/',
		'/^closedpostboxes_/',
		'/^meta-box-order_/',
		'/^screen_layout_/',
		'/^manage.+columnshidden$/',
		'/^edit_.+_per_page$/',
		'/^.+_per_page$/',
	);

	const CORE_TERM_META    = array( '_wp_trash_meta_status', '_wp_trash_meta_time' );
	const CORE_COMMENT_META = array( '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_note_status' );

	const CORE_POST_TYPES = array(
		'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache',
		'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
		'wp_font_family', 'wp_font_face', 'wp_note',
	);

	/** Folders directly under wp-content that WordPress itself uses. */
	const CORE_CONTENT_DIRS = array(
		'plugins', 'themes', 'uploads', 'mu-plugins', 'languages', 'upgrade', 'upgrade-temp-backup', 'fonts', 'blogs.dir',
	);

	/** Folders directly under uploads that WordPress itself uses. */
	const CORE_UPLOAD_DIRS = array( 'sites', 'fonts', 'wp-personal-data-exports' );

	/**
	 * @param string $name Option name.
	 */
	public static function is_core_option( $name ) {
		if ( in_array( $name, self::CORE_OPTIONS, true ) ) {
			return true;
		}
		foreach ( self::CORE_OPTION_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $hook Cron hook.
	 */
	public static function is_core_cron( $hook ) {
		return in_array( $hook, self::CORE_CRON_HOOKS, true );
	}

	/**
	 * @param string $meta_type post|user|term|comment.
	 * @param string $key       Meta key.
	 */
	public static function is_core_meta( $meta_type, $key ) {
		global $wpdb;
		switch ( $meta_type ) {
			case 'post':
				if ( in_array( $key, self::CORE_POST_META, true ) ) {
					return true;
				}
				foreach ( self::CORE_POST_META_PATTERNS as $pattern ) {
					if ( preg_match( $pattern, $key ) ) {
						return true;
					}
				}
				return false;
			case 'term':
				return in_array( $key, self::CORE_TERM_META, true );
			case 'comment':
				return in_array( $key, self::CORE_COMMENT_META, true );
			case 'user':
				if ( in_array( $key, self::CORE_USER_META, true ) ) {
					return true;
				}
				if ( 0 === strpos( $key, $wpdb->prefix ) && in_array( substr( $key, strlen( $wpdb->prefix ) ), self::CORE_USER_META_PREFIXED, true ) ) {
					return true;
				}
				foreach ( self::CORE_USER_META_PATTERNS as $pattern ) {
					if ( preg_match( $pattern, $key ) ) {
						return true;
					}
				}
				return false;
		}
		return false;
	}

	/**
	 * Core tables for this site, including the global ones.
	 *
	 * @return string[] Full table names.
	 */
	public static function core_tables() {
		global $wpdb;
		return array_values( $wpdb->tables( 'all', true ) );
	}

	/**
	 * Base names of core tables (without prefix), used to spot other installs sharing the database.
	 *
	 * @return string[]
	 */
	public static function core_table_basenames() {
		return array( 'options', 'posts', 'postmeta', 'users', 'usermeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'comments', 'commentmeta', 'links' );
	}
}

<?php
/**
 * Decides who owns a name and whether it is still in use.
 *
 * Evidence, strongest first:
 *   1. Active installed code references the name (or its prefix)  -> in_use
 *   2. Only inactive installed code references it                -> inactive
 *   3. A runtime signal (registered hook/post type) says it's used -> in_use
 *   4. It matches a known prefix of software that is not installed -> orphaned
 *   5. Nothing matches                                           -> unknown
 *
 * Core names are handled by the scanners before this runs.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Classifier {

	/** @var Code_Index */
	private $index;

	/** @var array<string,array> Memo of results per name. */
	private $memo = array();

	/**
	 * @param Code_Index $index Code index.
	 */
	public function __construct( Code_Index $index ) {
		$this->index = $index;
	}

	/**
	 * @param string|string[] $names         Normalized name first, then raw spellings (see normalize()).
	 * @param string|null     $runtime_owner Label of a runtime signal proving use, if any.
	 * @return array{status:string,owner:string,owner_slug:string,confidence:string,reason:string,group:string}
	 */
	public function classify( $names, $runtime_owner = null ) {
		$names = array_values( array_unique( array_filter( array_map( 'strtolower', (array) $names ), 'strlen' ) ) );
		if ( ! $names ) {
			$names = array( '' );
		}
		$key = implode( '|', $names ) . '#' . (string) $runtime_owner;
		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}
		return $this->memo[ $key ] = $this->decide( $names, $runtime_owner );
	}

	/**
	 * @param string[]    $names         Lower-case spellings; the first is the normalized one.
	 * @param string|null $runtime_owner Runtime signal label.
	 * @return array
	 */
	private function decide( array $names, $runtime_owner ) {
		$name  = $names[0];
		$group = self::group_of( $name );

		foreach ( $names as $candidate ) {
			// Our own names, plus the ALPS flag this plugin writes on converted images.
			if ( 0 === strpos( ltrim( $candidate, '_' ), 'wpcu_' ) || 0 === strpos( $candidate, 'wp-cleanup' ) || ltrim( strtolower( Media_Policy::ALPS_FLAG ), '_' ) === ltrim( $candidate, '_' ) ) {
				return $this->result( Plugin::STATUS_IN_USE, 'WP Cleanup', 'wp-cleanup', 'high', __( 'Used by WP Cleanup itself.', 'wp-cleanup' ), $group );
			}
		}

		foreach ( $names as $candidate ) {
			if ( $this->index->core_references( $candidate ) ) {
				return $this->result(
					Plugin::STATUS_CORE,
					'WordPress',
					'wordpress',
					'high',
					/* translators: %s: name */
					sprintf( __( 'WordPress core code references "%s".', 'wp-cleanup' ), $candidate ),
					'wordpress'
				);
			}
		}

		// Code references: an exact literal under any spelling beats a prefix hit, so a
		// derived name ("hero" from "_hero|x|0|0|value") wins over a loose prefix match.
		$refs = array( 'ids' => array(), 'match' => '', 'exact' => false );
		foreach ( $names as $candidate ) {
			$hit = $this->index->references( $candidate );
			if ( $hit['ids'] && ( ! empty( $hit['exact'] ) || ! $refs['ids'] ) ) {
				$refs = $hit;
				if ( ! empty( $hit['exact'] ) ) {
					break;
				}
			}
		}
		$tokens = $this->index->token_owners( $name );

		// A prefix-only hit on another product's known prefix (e.g. Akismet mentioning 'woocommerce_'
		// hooks) is an integration, not ownership. Exact hits still count.
		$sig = Signatures::match( $name );
		if ( $refs['ids'] && empty( $refs['exact'] ) && $sig && 0 === strpos( rtrim( $refs['match'], '_-.:' ), rtrim( $sig['prefix'], '_-.:' ) ) ) {
			$installed = false;
			foreach ( $refs['ids'] as $id ) {
				$source = $this->index->source( $id );
				if ( $source && $source['slug'] === $sig['slug'] ) {
					$installed = true;
				}
			}
			if ( ! $installed ) {
				$refs = array( 'ids' => array(), 'match' => '', 'exact' => false );
			}
		}

		// Weakest ownership evidence: an installed slug inside the name. Only consulted when
		// nothing else claims the name, and never enough on its own to call it orphaned.
		$slugs = ( $tokens['ids'] || $refs['ids'] ) ? array( 'ids' => array(), 'match' => '' ) : $this->index->slug_owners( $name );

		$active   = array();
		$inactive = array();
		// Token owners first: they name the most likely owner.
		foreach ( array( 'token' => $tokens, 'ref' => $refs, 'slug' => $slugs ) as $kind => $hit ) {
			foreach ( $hit['ids'] as $id ) {
				$source = $this->index->source( $id );
				if ( ! $source ) {
					continue;
				}
				$entry = array( 'source' => $source, 'match' => $hit['match'], 'kind' => $kind );
				if ( $source['active'] ) {
					$active[] = $entry;
				} else {
					$inactive[] = $entry;
				}
			}
		}

		if ( $active ) {
			$first = $active[0];
			return $this->result(
				Plugin::STATUS_IN_USE,
				$first['source']['name'],
				$first['source']['slug'],
				'slug' === $first['kind'] ? 'medium' : 'high',
				$this->evidence_text( $first, count( $active ) ),
				$group
			);
		}

		if ( $runtime_owner ) {
			return $this->result(
				Plugin::STATUS_IN_USE,
				$runtime_owner,
				'',
				'high',
				/* translators: %s: what registered it */
				sprintf( __( 'Registered at runtime (%s).', 'wp-cleanup' ), $runtime_owner ),
				$group
			);
		}

		if ( $inactive ) {
			$first = $inactive[0];
			return $this->result(
				Plugin::STATUS_INACTIVE,
				$first['source']['name'],
				$first['source']['slug'],
				'slug' === $first['kind'] ? 'medium' : 'high',
				$this->evidence_text( $first, count( $inactive ) ) . ' ' . __( 'That code is installed but not active. Delete the plugin/theme first, or allow inactive items explicitly.', 'wp-cleanup' ),
				$group
			);
		}

		$incomplete = $this->index->incomplete_sources();
		if ( $incomplete ) {
			// Some installed code could not be read, so "nobody references it" is not proven.
			return $this->result(
				Plugin::STATUS_UNKNOWN,
				$sig ? $sig['name'] : '',
				$sig ? $sig['slug'] : '',
				'low',
				/* translators: %s: plugin/theme names */
				sprintf( __( 'No reference found, but some installed code could not be fully checked (%s), so this cannot be confirmed as orphaned.', 'wp-cleanup' ), implode( ', ', wp_list_pluck( $incomplete, 'name' ) ) ),
				$group
			);
		}

		if ( $sig ) {
			return $this->result(
				Plugin::STATUS_ORPHANED,
				$sig['name'],
				$sig['slug'],
				( $sig['exact'] || strlen( $sig['prefix'] ) >= 4 ) ? 'high' : 'medium',
				$sig['exact']
					/* translators: %s: plugin name */
					? sprintf( __( 'A known name used by %s, which is not installed. No installed code references this name.', 'wp-cleanup' ), $sig['name'] )
					/* translators: 1: prefix, 2: plugin name */
					: sprintf( __( 'Matches prefix "%1$s" of %2$s, which is not installed. No installed code references this name.', 'wp-cleanup' ), $sig['prefix'], $sig['name'] ),
				$group
			);
		}

		$field = '';
		foreach ( $names as $candidate ) {
			$field = '' !== $field ? $field : self::carbon_field( $candidate );
		}
		if ( '' !== $field ) {
			return $this->result(
				Plugin::STATUS_UNKNOWN,
				'',
				'',
				'low',
				/* translators: %s: field name */
				sprintf( __( 'Stored in Carbon Fields format for the field "%s", which no installed plugin or theme defines by that name. A theme or plugin may build the field name dynamically, or a removed one left it behind. Review before deleting.', 'wp-cleanup' ), $field ),
				$group
			);
		}

		return $this->result(
			Plugin::STATUS_UNKNOWN,
			'',
			'',
			'low',
			__( 'No installed plugin, theme or known signature references this name. Probably left by removed software, but it may also be created dynamically. Review before deleting.', 'wp-cleanup' ),
			$group
		);
	}

	/**
	 * @param array $entry Evidence entry.
	 * @param int   $count Number of matching sources.
	 * @return string
	 */
	private function evidence_text( array $entry, $count ) {
		switch ( $entry['kind'] ) {
			case 'token':
				/* translators: 1: plugin/theme name, 2: matched prefix */
				$text = sprintf( __( 'Name belongs to %1$s (prefix "%2$s").', 'wp-cleanup' ), $entry['source']['name'], $entry['match'] );
				break;
			case 'slug':
				/* translators: 1: plugin/theme name, 2: slug */
				$text = sprintf( __( 'Name contains the folder name of %1$s ("%2$s"), as update and license caches do. Treated as that software\'s data.', 'wp-cleanup' ), $entry['source']['name'], $entry['match'] );
				break;
			default:
				/* translators: 1: plugin/theme name, 2: matched literal */
				$text = sprintf( __( 'Referenced in the code of %1$s ("%2$s").', 'wp-cleanup' ), $entry['source']['name'], $entry['match'] );
		}
		if ( $count > 1 ) {
			/* translators: %d: number of other sources */
			$text .= ' ' . sprintf( _n( 'Also matched by %d other source.', 'Also matched by %d other sources.', $count - 1, 'wp-cleanup' ), $count - 1 );
		}
		return $text;
	}

	/**
	 * @return array
	 */
	private function result( $status, $owner, $slug, $confidence, $reason, $group ) {
		return array(
			'status'     => $status,
			'owner'      => $owner,
			'owner_slug' => $slug,
			'confidence' => $confidence,
			'reason'     => $reason,
			'group'      => $group,
		);
	}

	/**
	 * First word of a name, used to group unknown items ("oldplugin_*").
	 *
	 * @param string $name Lower-case name.
	 */
	public static function group_of( $name ) {
		$parts = preg_split( '/[_\-\.:]/', ltrim( $name, '_' ), 2 );
		return isset( $parts[0] ) && '' !== $parts[0] ? $parts[0] : $name;
	}

	/**
	 * Every spelling of a stored name worth looking up in code, normalized first.
	 *
	 * Besides the stored and normalized forms this adds names that code builds
	 * dynamically, so the literal in the code differs from the stored name:
	 *   - Carbon Fields keys ("_hero|slide_image|1|0|value", "_footer||0|_empty")
	 *     yield the field name defined in code ("hero", "footer").
	 *   - Numeric suffixes ("post_by_email_address4", "x_backup_14_2_1") yield
	 *     the base the code concatenates with an id or version.
	 *
	 * @param string $type Item type.
	 * @param string $raw  Stored name.
	 * @return string[]
	 */
	public static function spellings( $type, $raw ) {
		$raw   = (string) $raw;
		$first = self::normalize( $type, $raw );
		$names = array( $first, $raw, ltrim( $raw, '_' ) );
		$field = self::carbon_field( $raw );
		if ( '' !== $field ) {
			$names[] = $field;
		}
		foreach ( array( $first, $field ) as $name ) {
			$base = preg_replace( '/(?:[_\-]?\d+)+$/', '', (string) $name );
			if ( $base !== $name && strlen( trim( $base, '_-' ) ) >= 6 ) {
				$names[] = rtrim( $base, '_-' );
			}
		}
		return array_values( array_unique( array_filter( $names, 'strlen' ) ) );
	}

	/**
	 * Field name of a key stored in Carbon Fields' format, or ''.
	 *
	 * Carbon Fields keeps complex and theme-option values as
	 * "_{field}|{sub-field}|{index}|{group}|{property}".
	 *
	 * @param string $raw Stored name.
	 */
	public static function carbon_field( $raw ) {
		$raw = ltrim( (string) $raw, '_' );
		$bar = strpos( $raw, '|' );
		if ( false === $bar || $bar < 3 || ! preg_match( '/^[A-Za-z0-9_\-]+$/', substr( $raw, 0, $bar ) ) ) {
			return '';
		}
		return strtolower( substr( $raw, 0, $bar ) );
	}

	/**
	 * Strip storage decorations so names line up with what code uses.
	 *
	 * @param string $type Item type.
	 * @param string $name Raw name.
	 */
	public static function normalize( $type, $name ) {
		global $wpdb;
		$name = (string) $name;
		switch ( $type ) {
			case 'table':
				if ( 0 === strpos( $name, $wpdb->prefix ) ) {
					$name = substr( $name, strlen( $wpdb->prefix ) );
				}
				break;
			case 'transient':
				$name = preg_replace( '/^_(site_)?transient_(timeout_)?/', '', $name );
				break;
			case 'meta':
				if ( 0 === strpos( $name, $wpdb->prefix ) ) {
					$name = substr( $name, strlen( $wpdb->prefix ) );
				}
				$name = ltrim( $name, '_' );
				break;
		}
		return $name;
	}
}

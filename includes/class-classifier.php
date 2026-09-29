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

		// Code references: try every spelling; the first one with hits wins.
		$refs = array( 'ids' => array(), 'match' => '' );
		foreach ( $names as $candidate ) {
			$refs = $this->index->references( $candidate );
			if ( $refs['ids'] ) {
				break;
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

		$active   = array();
		$inactive = array();
		foreach ( array( $tokens, $refs ) as $hit ) { // Token owners first: they name the most likely owner.
			foreach ( $hit['ids'] as $id ) {
				$source = $this->index->source( $id );
				if ( ! $source ) {
					continue;
				}
				$entry = array( 'source' => $source, 'match' => $hit['match'], 'by_token' => $hit === $tokens );
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
				'high',
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
				'high',
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
				strlen( $sig['prefix'] ) >= 4 ? 'high' : 'medium',
				/* translators: 1: prefix, 2: plugin name */
				sprintf( __( 'Matches prefix "%1$s" of %2$s, which is not installed. No installed code references this name.', 'wp-cleanup' ), $sig['prefix'], $sig['name'] ),
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
		$text = $entry['by_token']
			/* translators: 1: plugin/theme name, 2: matched prefix */
			? sprintf( __( 'Name belongs to %1$s (prefix "%2$s").', 'wp-cleanup' ), $entry['source']['name'], $entry['match'] )
			/* translators: 1: plugin/theme name, 2: matched literal */
			: sprintf( __( 'Referenced in the code of %1$s ("%2$s").', 'wp-cleanup' ), $entry['source']['name'], $entry['match'] );
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

<?php
/**
 * Rewrites references to removed image files so pages keep working.
 *
 * Keys are paths that start at the uploads folder name ("uploads/2024/05/a.jpg"),
 * so absolute, relative, protocol-relative and CDN URLs all match, while an
 * unrelated "a.jpg" elsewhere does not. JSON-escaped forms ("uploads\/2024\/...")
 * used by the block editor and page builders are handled too.
 *
 * Serialized values are unserialized, walked and re-serialized, never
 * string-replaced. A row holding a serialized object that would need a change
 * is reported as unsafe and the whole attachment is left alone.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Reference_Rewriter {

	/** @var array<string,string> old key => new key, longest first */
	private $map;

	/** @var string[] Regex patterns aligned with $replacements. */
	private $patterns = array();

	/** @var string[] */
	private $replacements = array();

	/** @var array<string,int> new key => real pixel width, for fixing srcset descriptors */
	private $widths;

	/**
	 * @param array<string,string> $map    Old uploads-relative key => new key.
	 * @param array<string,int>    $widths New key => width in px.
	 */
	public function __construct( array $map, array $widths = array() ) {
		$this->widths = $widths;
		uksort(
			$map,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		$this->map = $map;
		foreach ( $map as $old => $new ) {
			foreach ( array( '/', '\\/' ) as $slash ) {
				$this->patterns[]     = '~(?<![A-Za-z0-9_.\-])' . preg_quote( str_replace( '/', $slash, $old ), '~' ) . '(?![A-Za-z0-9_\-]|\.[A-Za-z0-9])~';
				$this->replacements[] = str_replace( '/', $slash, $new );
			}
		}
	}

	/**
	 * Uploads-relative key for a file: "<uploads dir name>/<rel dir>/<file>".
	 *
	 * @param string $rel_dir Folder relative to uploads ('' for the root).
	 * @param string $file    File name.
	 */
	public static function key( $rel_dir, $file ) {
		$uploads = wp_basename( wp_upload_dir( null, false )['basedir'] );
		return $uploads . '/' . ( '' === $rel_dir ? '' : $rel_dir . '/' ) . $file;
	}

	/**
	 * Tables/columns that can hold image URLs, with their primary key.
	 *
	 * @return array<int,array{table:string,pk:string,columns:string[],exclude:string}>
	 */
	private static function targets( $attachment_id ) {
		global $wpdb;
		$id = (int) $attachment_id;
		return array(
			array(
				'table'   => $wpdb->posts,
				'pk'      => 'ID',
				'columns' => array( 'post_content', 'post_excerpt', 'post_content_filtered' ),
				'exclude' => '1 = 0', // Revisions too: restoring one must not bring back dead URLs.
			),
			array(
				'table'   => $wpdb->postmeta,
				'pk'      => 'meta_id',
				'columns' => array( 'meta_value' ),
				'exclude' => "post_id = {$id} AND meta_key IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes','_wpcu_image_outputs')",
			),
			array(
				'table'   => $wpdb->options,
				'pk'      => 'option_id',
				'columns' => array( 'option_value' ),
				'exclude' => "option_name LIKE 'wpcu\\_%' OR option_name IN ('cron','rewrite_rules')",
			),
			array(
				'table'   => $wpdb->termmeta,
				'pk'      => 'meta_id',
				'columns' => array( 'meta_value' ),
				'exclude' => '1 = 0',
			),
			array(
				'table'   => $wpdb->usermeta,
				'pk'      => 'umeta_id',
				'columns' => array( 'meta_value' ),
				'exclude' => "meta_key = 'session_tokens'",
			),
			array(
				'table'   => $wpdb->comments,
				'pk'      => 'comment_ID',
				'columns' => array( 'comment_content' ),
				'exclude' => '1 = 0',
			),
		);
	}

	/**
	 * Find every row that needs a change. Nothing is written.
	 *
	 * @param string[] $needles       Short substrings to prefilter with LIKE (e.g. "uploads/2024/05/photo").
	 * @param int      $attachment_id Attachment whose own file metadata is handled elsewhere.
	 * @return array<int,array{table:string,pk:string,id:string,column:string,old:string,new:string,row:array}>
	 * @throws \RuntimeException When a match sits inside a serialized object.
	 */
	public function plan( array $needles, $attachment_id ) {
		global $wpdb;
		$changes = array();
		foreach ( self::targets( $attachment_id ) as $t ) {
			foreach ( $t['columns'] as $column ) {
				$likes = array();
				foreach ( $needles as $needle ) {
					foreach ( array( $needle, str_replace( '/', '\\/', $needle ) ) as $variant ) {
						$likes[] = $wpdb->prepare( "`{$column}` LIKE %s", '%' . $wpdb->esc_like( $variant ) . '%' ); // phpcs:ignore
					}
				}
				$sql  = "SELECT * FROM `{$t['table']}` WHERE (" . implode( ' OR ', $likes ) . ") AND NOT ({$t['exclude']})";
				$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore -- built from prepared fragments and constants.
				foreach ( (array) $rows as $row ) {
					$old = (string) $row[ $column ];
					$new = $this->replace_value( $old, $t['table'] . '#' . $row[ $t['pk'] ] );
					if ( $new !== $old ) {
						$changes[] = array(
							'table'  => $t['table'],
							'pk'     => $t['pk'],
							'id'     => (string) $row[ $t['pk'] ],
							'column' => $column,
							'old'    => $old,
							'new'    => $new,
							'row'    => $row,
						);
					}
				}
			}
		}
		return $changes;
	}

	/**
	 * Apply planned changes, only where the value is still what was planned.
	 *
	 * @param array $changes From plan().
	 * @return int Rows updated.
	 * @throws \RuntimeException When a row changed in the meantime.
	 */
	public static function apply( array $changes ) {
		global $wpdb;
		$done = 0;
		foreach ( $changes as $c ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$c['table']}` SET `{$c['column']}` = %s WHERE `{$c['pk']}` = %s AND `{$c['column']}` = %s", // phpcs:ignore
					$c['new'],
					$c['id'],
					$c['old']
				)
			);
			if ( 1 !== (int) $updated ) {
				/* translators: 1: table, 2: row id */
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
				throw new \RuntimeException( sprintf( /* translators: 1: database table, 2: row ID */ __( 'Row %2$s in %1$s changed during the update.', 'wp-cleanup' ), $c['table'], $c['id'] ) );
			}
			++$done;
		}
		return $done;
	}

	/**
	 * @param string $value Stored value.
	 * @param string $where Row label for error messages.
	 * @return string
	 * @throws \RuntimeException When unsafe.
	 */
	public function replace_value( $value, $where = '' ) {
		if ( is_serialized( $value ) ) {
			$data = @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore
			if ( false === $data && 'b:0;' !== trim( $value ) ) {
				if ( $this->replace_text( $value ) !== $value ) {
					/* translators: %s: row */
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
					throw new \RuntimeException( sprintf( /* translators: %s: where the value is stored */ __( 'Could not safely rewrite a broken serialized value (%s).', 'wp-cleanup' ), $where ) );
				}
				return $value;
			}
			$changed = false;
			$walked  = $this->walk( $data, $changed, $where );
			return $changed ? serialize( $walked ) : $value;
		}
		return $this->replace_text( $value );
	}

	/**
	 * @param mixed  $data    Unserialized data.
	 * @param bool   $changed Set when something changed.
	 * @param string $where   Row label.
	 * @return mixed
	 * @throws \RuntimeException When an object would need a change.
	 */
	private function walk( $data, &$changed, $where ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = $this->walk( $v, $changed, $where );
			}
			return $data;
		}
		if ( is_object( $data ) ) {
			// Objects (often __PHP_Incomplete_Class here) cannot be edited safely.
			if ( $this->replace_text( serialize( $data ) ) !== serialize( $data ) ) {
				/* translators: %s: row */
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
				throw new \RuntimeException( sprintf( /* translators: %s: where the value is stored */ __( 'An image URL is stored inside a PHP object (%s); rewrite it manually.', 'wp-cleanup' ), $where ) );
			}
			return $data;
		}
		if ( is_string( $data ) ) {
			$new = is_serialized( $data ) ? $this->replace_value( $data, $where ) : $this->replace_text( $data );
			if ( $new !== $data ) {
				$changed = true;
			}
			return $new;
		}
		return $data;
	}

	/**
	 * @param string $text Plain text, HTML or JSON.
	 */
	public function replace_text( $text ) {
		$out = (string) preg_replace( $this->patterns, $this->replacements, $text );
		if ( $out !== $text && $this->widths && false !== stripos( $out, 'srcset' ) ) {
			$out = (string) preg_replace_callback( '~(srcset=)(["\'])(.*?)\2~is', array( $this, 'fix_srcset' ), $out );
		}
		return $out;
	}

	/**
	 * After rewriting, several srcset candidates can point at the same new
	 * file with stale width descriptors. List each file once at its real width.
	 *
	 * @param array $m Regex match.
	 * @return string
	 */
	private function fix_srcset( array $m ) {
		$seen = array();
		$out  = array();
		foreach ( explode( ',', $m[3] ) as $candidate ) {
			$parts = preg_split( '/\s+/', trim( $candidate ), 2 );
			$url   = isset( $parts[0] ) ? $parts[0] : '';
			if ( '' === $url ) {
				continue;
			}
			$desc = isset( $parts[1] ) ? $parts[1] : '';
			foreach ( $this->widths as $key => $width ) {
				if ( substr( $url, -strlen( $key ) ) === $key ) {
					$desc = (int) $width . 'w';
					break;
				}
			}
			if ( isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$out[]        = trim( $url . ' ' . $desc );
		}
		return $m[1] . $m[2] . implode( ', ', $out ) . $m[2];
	}
}

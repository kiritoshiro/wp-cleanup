<?php
/**
 * Read-only view of image files under uploads, including files without attachments.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Media_Files {
	const LIST_LIMIT = 2000;

	/** All image attachment IDs, without running usage or similarity analysis. */
	public static function all_ids() {
		$ids = array();
		$offset = 0;
		do {
			$page = Media_Inventory::image_ids( $offset, 200 );
			$ids = array_merge( $ids, $page['ids'] );
			$offset += 200;
		} while ( $page['ids'] );
		return $ids;
	}

	/** Build a snapshot of attachments and image files that no attachment owns. */
	public static function catalog( array $ids ) {
		$uploads = wp_upload_dir( null, false );
		$root = wp_normalize_path( $uploads['basedir'] );
		$library = array();
		$owned = array();
		foreach ( $ids as $id ) {
			$inv = Media_Inventory::attachment( $id );
			$attached = get_attached_file( $id, true );
			$primary = $attached ? wp_normalize_path( $attached ) : '';
			$relative = $primary && 0 === strpos( $primary, $root . '/' ) ? substr( $primary, strlen( $root ) + 1 ) : '';
			$entry = array(
				'id' => (int) $id,
				'title' => get_the_title( $id ),
				'primary' => $relative,
				'mime' => get_post_mime_type( $id ),
				'files' => array(),
			);
			if ( $inv ) {
				$outputs = get_post_meta( $id, Media_Policy::OUTPUT_META, true );
				foreach ( $inv['files'] as $name => $file ) {
					$rel = ltrim( $inv['rel_dir'] . '/' . $name, '/' );
					$owned[ $rel ] = true;
					$path = $inv['dir'] . '/' . $name;
					$width = (int) $file['width'];
					$height = (int) $file['height'];
					if ( is_array( $outputs ) ) {
						foreach ( array( 'avif_full', 'avif_small' ) as $variant ) {
							if ( isset( $outputs[ $variant ] ) && $rel === $outputs[ $variant ] ) {
								$width = isset( $outputs[ $variant . '_width' ] ) ? (int) $outputs[ $variant . '_width' ] : $width;
								$height = isset( $outputs[ $variant . '_height' ] ) ? (int) $outputs[ $variant . '_height' ] : $height;
							}
						}
					}
					if ( ! $width || ! $height ) {
						$dimensions = @getimagesize( $path ); // phpcs:ignore -- Local uploads file, read only.
						if ( $dimensions ) {
							$width = (int) $dimensions[0];
							$height = (int) $dimensions[1];
						}
					}
					$role = $file['role'];
					if ( 'attached' === $role && is_array( $outputs ) && ! empty( $outputs['jpeg'] ) && $rel === $outputs['jpeg'] ) {
						$role = 'jpeg_fallback';
					}
					$entry['files'][] = array(
						'path' => $rel,
						'role' => $role,
						'size' => $file['size'],
						'width' => $width,
						'height' => $height,
						'bytes' => $file['bytes'],
					);
				}
			}
			$library[] = $entry;
		}

		$unregistered = array();
		$count = 0;
		$bytes = 0;
		$incomplete = false;
		$private = Storage::dir_name();
		if ( is_dir( $root ) ) {
			try {
				$filter = new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
					static function ( $item ) use ( $root, $private ) {
						if ( $item->isLink() ) {
							return false;
						}
						if ( $item->isDir() && wp_normalize_path( dirname( $item->getPathname() ) ) === $root && ( ( $private && $item->getFilename() === $private ) || preg_match( '/^wp-cleanup-[a-z0-9]{16}$/', $item->getFilename() ) ) ) {
							return false;
						}
						return true;
					}
				);
				foreach ( new \RecursiveIteratorIterator( $filter ) as $file ) {
					if ( ! $file->isFile() || ! preg_match( '/\.(?:jpe?g|png|avif|webp|gif|bmp|tiff?)$/i', $file->getFilename() ) ) {
						continue;
					}
					$path = wp_normalize_path( $file->getPathname() );
					$rel = substr( $path, strlen( $root ) + 1 );
					if ( isset( $owned[ $rel ] ) ) {
						continue;
					}
					++$count;
					$size = (int) $file->getSize();
					$bytes += $size;
					if ( count( $unregistered ) < self::LIST_LIMIT ) {
						$unregistered[] = array( 'path' => $rel, 'bytes' => $size );
					}
				}
			} catch ( \UnexpectedValueException $e ) {
				$incomplete = true;
			}
		}
		usort( $unregistered, static function ( $a, $b ) { return strnatcasecmp( $a['path'], $b['path'] ); } );
		return array(
			'library' => $library,
			'unregistered' => $unregistered,
			'unregistered_count' => $count,
			'unregistered_bytes' => $bytes,
			'incomplete' => $incomplete,
		);
	}
}

<?php
/**
 * Safe ZIP extract with zip-slip and archive-bomb guards.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Filesystem;

defined( 'ABSPATH' ) || exit;

/**
 * Class Archiver
 */
class Archiver {

	/**
	 * Max entries in an archive.
	 */
	const MAX_ENTRIES = 2000000;

	/**
	 * Max uncompressed bytes (512 GiB safety ceiling).
	 */
	const MAX_UNCOMPRESSED = 549755813888;

	/**
	 * Max compression ratio (bomb heuristic).
	 */
	const MAX_RATIO = 100;

	/**
	 * Create or open a ZipArchive.
	 *
	 * @param string $path Archive path.
	 * @param int    $flags Zip flags.
	 * @return \ZipArchive
	 */
	public function open( $path, $flags = \ZipArchive::CREATE ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new \RuntimeException( esc_html__( 'PHP ZipArchive extension is required.', 'nobash-backup' ) );
		}
		$zip = new \ZipArchive();
		$res = $zip->open( $path, $flags );
		if ( true !== $res ) {
			throw new \RuntimeException( esc_html__( 'Unable to open archive.', 'nobash-backup' ) );
		}
		return $zip;
	}

	/**
	 * Add files to archive in a chunk.
	 *
	 * @param string             $archive   Archive path.
	 * @param array<int, string> $files     Absolute paths.
	 * @param int                $start     Start index.
	 * @param int                $limit     Max files this chunk.
	 * @param FileCollector      $collector Collector for relative paths.
	 * @return int Next index.
	 */
	public function add_files_chunk( $archive, array $files, $start, $limit, FileCollector $collector ) {
		$zip   = $this->open( $archive );
		$count = count( $files );
		$end   = min( $count, $start + $limit );

		for ( $i = $start; $i < $end; $i++ ) {
			$file = $files[ $i ];
			if ( ! is_readable( $file ) ) {
				continue;
			}
			$local = 'files/' . $collector->relative( $file );
			$zip->addFile( $file, $local );
		}

		$zip->close();
		return $end;
	}

	/**
	 * Add a single file under archive path.
	 *
	 * @param string $archive Archive path.
	 * @param string $file    Local file.
	 * @param string $local   Path inside archive.
	 * @return void
	 */
	public function add_file( $archive, $file, $local ) {
		$zip = $this->open( $archive );
		$zip->addFile( $file, $local );
		$zip->close();
	}

	/**
	 * Validate ZIP for bombs / zip-slip before extract.
	 *
	 * @param string $archive Archive path (plaintext ZIP).
	 * @return void
	 * @throws \RuntimeException On unsafe archive.
	 */
	public function assert_safe( $archive ) {
		$zip = new \ZipArchive();
		$res = $zip->open( $archive );
		if ( true !== $res ) {
			throw new \RuntimeException( esc_html__( 'Unable to open archive.', 'nobash-backup' ) );
		}

		try {
			$num = $zip->numFiles;
			if ( $num > self::MAX_ENTRIES ) {
				throw new \RuntimeException( esc_html__( 'Archive rejected: too many entries (possible zip bomb).', 'nobash-backup' ) );
			}

			$compressed_total   = is_readable( $archive ) ? (int) filesize( $archive ) : 0;
			$uncompressed_total = 0;

			for ( $i = 0; $i < $num; $i++ ) {
				$stat = $zip->statIndex( $i );
				if ( ! is_array( $stat ) || empty( $stat['name'] ) ) {
					continue;
				}
				$name = (string) $stat['name'];
				$this->assert_safe_entry_name( $name );

				$size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
				$uncompressed_total += max( 0, $size );
				if ( $uncompressed_total > self::MAX_UNCOMPRESSED ) {
					throw new \RuntimeException( esc_html__( 'Archive rejected: uncompressed size exceeds safety limit.', 'nobash-backup' ) );
				}
			}

			if ( $compressed_total > 0 && ( $uncompressed_total / $compressed_total ) > self::MAX_RATIO ) {
				throw new \RuntimeException( esc_html__( 'Archive rejected: suspicious compression ratio (possible zip bomb).', 'nobash-backup' ) );
			}
		} finally {
			$zip->close();
		}
	}

	/**
	 * Extract archive to directory with zip-slip protection.
	 *
	 * @param string $archive Archive path.
	 * @param string $dest    Destination directory.
	 * @return void
	 */
	public function extract( $archive, $dest ) {
		$this->assert_safe( $archive );

		wp_mkdir_p( $dest );
		$dest_real = realpath( $dest );
		if ( false === $dest_real ) {
			throw new \RuntimeException( esc_html__( 'Invalid extract destination.', 'nobash-backup' ) );
		}

		$zip = new \ZipArchive();
		$res = $zip->open( $archive );
		if ( true !== $res ) {
			throw new \RuntimeException( esc_html__( 'Unable to open archive.', 'nobash-backup' ) );
		}

		try {
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stat = $zip->statIndex( $i );
				if ( ! is_array( $stat ) || empty( $stat['name'] ) ) {
					continue;
				}
				$name = str_replace( '\\', '/', (string) $stat['name'] );
				$this->assert_safe_entry_name( $name );

				$target = $dest_real . '/' . ltrim( $name, '/' );
				$target = wp_normalize_path( $target );

				// Directory entries end with /.
				if ( '/' === substr( $name, -1 ) ) {
					if ( ! is_dir( $target ) ) {
						wp_mkdir_p( $target );
					}
					$this->assert_path_inside( $target, $dest_real );
					continue;
				}

				$parent = dirname( $target );
				if ( ! is_dir( $parent ) ) {
					wp_mkdir_p( $parent );
				}
				$this->assert_path_inside( $parent, $dest_real );

				$stream = $zip->getStream( $name );
				if ( false === $stream ) {
					throw new \RuntimeException(
						esc_html(
							sprintf(
								/* translators: %s: entry name */
								__( 'Could not read archive entry: %s', 'nobash-backup' ),
								$name
							)
						)
					);
				}

				// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no streaming primitives; extracted entries are read from the ZipArchive stream and written in fixed-size chunks without loading the whole file into memory.
				$out = fopen( $target, 'wb' );
				if ( ! $out ) {
					fclose( $stream );
					throw new \RuntimeException( esc_html__( 'Could not write extracted file.', 'nobash-backup' ) );
				}
				while ( ! feof( $stream ) ) {
					$chunk = fread( $stream, 1048576 );
					if ( false === $chunk ) {
						break;
					}
					fwrite( $out, $chunk );
				}
				fclose( $stream );
				fclose( $out );
				// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

				$this->assert_path_inside( $target, $dest_real );
			}
		} finally {
			$zip->close();
		}
	}

	/**
	 * Reject dangerous ZIP entry names.
	 *
	 * @param string $name Entry name.
	 * @return void
	 */
	private function assert_safe_entry_name( $name ) {
		$name = str_replace( '\\', '/', $name );
		if ( '' === $name || false !== strpos( $name, "\0" ) ) {
			throw new \RuntimeException( esc_html__( 'Archive rejected: invalid entry name.', 'nobash-backup' ) );
		}
		if ( '/' === $name[0] || preg_match( '#^[A-Za-z]:/#', $name ) ) {
			throw new \RuntimeException( esc_html__( 'Archive rejected: absolute paths are not allowed.', 'nobash-backup' ) );
		}
		$parts = explode( '/', $name );
		foreach ( $parts as $part ) {
			if ( '..' === $part ) {
				throw new \RuntimeException( esc_html__( 'Archive rejected: path traversal detected (zip-slip).', 'nobash-backup' ) );
			}
		}
	}

	/**
	 * Ensure a path stays inside a jail directory.
	 *
	 * @param string $path Path (may not exist yet for files — parent checked).
	 * @param string $jail Realpath jail.
	 * @return void
	 */
	private function assert_path_inside( $path, $jail ) {
		$jail = rtrim( wp_normalize_path( $jail ), '/' );
		$real = realpath( $path );
		if ( false === $real ) {
			// File may have just been written; check normalized prefix.
			$norm = wp_normalize_path( $path );
			if ( 0 !== strpos( $norm, $jail . '/' ) && $norm !== $jail ) {
				throw new \RuntimeException( esc_html__( 'Archive rejected: extracted path escaped destination.', 'nobash-backup' ) );
			}
			return;
		}
		$real = wp_normalize_path( $real );
		if ( 0 !== strpos( $real, $jail . '/' ) && $real !== $jail ) {
			throw new \RuntimeException( esc_html__( 'Archive rejected: extracted path escaped destination.', 'nobash-backup' ) );
		}
	}
}

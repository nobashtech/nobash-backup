<?php
/**
 * Resumable filesystem discovery.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Filesystem;

defined( 'ABSPATH' ) || exit;

/** Discover files into a durable inventory using bounded directory reads. */
class ResumableFileScanner {
	/** @var FileInventoryRepository */
	private $inventory;
	/** @var FileCollector */
	private $collector;

	public function __construct( ?FileInventoryRepository $inventory = null, ?FileCollector $collector = null ) {
		$this->inventory = $inventory ? $inventory : new FileInventoryRepository();
		$this->collector = $collector ? $collector : new FileCollector();
	}

	/** @param int $job_id Job ID. @param string $profile Profile. @param string $scope Scope. @return void */
	public function seed( $job_id, $profile, $scope ) {
		$this->seed_roots( $job_id, $this->collector->roots( $profile, $scope ) );
	}

	/** Seed explicitly selected paths that were already resolved beneath WordPress installation root. */
	public function seed_roots( $job_id, array $roots ) {
		foreach ( $roots as $root ) {
			$root = wp_normalize_path( $root );
			if ( Exclusions::is_excluded( $root ) ) {
				continue;
			}
			$meta = $this->path_metadata( $root );
			if ( ! $meta || 'link' === $meta['type'] ) {
				continue;
			}
			if ( 'file' === $meta['type'] ) {
				$this->inventory->add( $job_id, $root, $this->collector->relative( $root ), 'file', $meta['size'], $meta['mtime'] );
			} elseif ( 'directory' === $meta['type'] ) {
				$this->inventory->add( $job_id, $root, $this->collector->relative( $root ), 'directory' );
			}
		}
	}

	/**
	 * Process a bounded discovery slice.
	 *
	 * @param int $job_id Job ID.
	 * @param array<string,mixed> $cursor Cursor.
	 * @param int $max_entries Max directory entries.
	 * @param int $max_bytes Max discovered bytes.
	 * @param float $deadline Absolute microtime deadline.
	 * @return array<string,mixed>
	 */
	public function scan( $job_id, array $cursor, $max_entries, $max_bytes, $deadline ) {
		$processed = 0;
		$bytes     = 0;
		while ( $processed < $max_entries && $bytes < $max_bytes && microtime( true ) < $deadline ) {
			$directory = $this->inventory->next_directory( $job_id );
			if ( ! $directory ) {
				$cursor['scan_complete'] = true;
				break;
			}
			$dir_id         = (int) $directory['id'];
			$path           = (string) $directory['absolute_path'];
			$same_directory = isset( $cursor['scan_dir_id'] ) && (int) $cursor['scan_dir_id'] === $dir_id;
			$pos            = $same_directory ? (int) ( $cursor['scan_dir_pos'] ?? 0 ) : 0;
			$entry_index    = $same_directory ? (int) ( $cursor['scan_dir_index'] ?? 0 ) : 0;
			$can_seek       = function_exists( 'seekdir' ) && function_exists( 'telldir' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_opendir
			$handle = @opendir( $path );
			if ( false === $handle ) {
				$this->inventory->set_state( $dir_id, 'skipped', 'Directory is unreadable.' );
				unset( $cursor['scan_dir_id'], $cursor['scan_dir_pos'], $cursor['scan_dir_index'] );
				continue;
			}
			if ( $can_seek && $pos > 0 ) {
				\seekdir( $handle, $pos );
			} elseif ( ! $can_seek && $entry_index > 0 ) {
				$replayed = 0;
				while ( $replayed < $entry_index && false !== readdir( $handle ) ) {
					++$replayed;
				}
			}

			$finished          = false;
			$current_entry_idx = $entry_index;
			$batch             = array();
			while ( $processed < $max_entries && $bytes < $max_bytes && microtime( true ) < $deadline ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readdir
				$name = readdir( $handle );
				if ( false === $name ) {
					$finished = true;
					break;
				}
				++$current_entry_idx;
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$child = wp_normalize_path( trailingslashit( $path ) . $name );
				++$processed;
				if ( Exclusions::is_excluded( $child ) || $this->profile_excluded( $child, $cursor ) ) {
					continue;
				}
				$meta = $this->path_metadata( $child );
				if ( ! $meta || 'link' === $meta['type'] ) {
					continue;
				}
				if ( 'directory' === $meta['type'] ) {
					$batch[] = array(
						'path'     => $child,
						'relative' => $this->collector->relative( $child ),
						'type'     => 'directory',
						'size'     => 0,
						'mtime'    => 0,
					);
				} elseif ( 'file' === $meta['type'] ) {
					$batch[] = array(
						'path'     => $child,
						'relative' => $this->collector->relative( $child ),
						'type'     => 'file',
						'size'     => $meta['size'],
						'mtime'    => $meta['mtime'],
					);
					$bytes += $meta['size'];
				}
				if ( count( $batch ) >= 500 ) {
					$this->inventory->add_many( $job_id, $batch );
					$batch = array();
				}
			}
			if ( ! empty( $batch ) ) {
				$this->inventory->add_many( $job_id, $batch );
			}
			$cursor['scan_dir_id']    = $dir_id;
			$cursor['scan_dir_index'] = $current_entry_idx;
			$cursor['scan_dir_pos']   = $can_seek ? (int) \telldir( $handle ) : 0;
			closedir( $handle );
			if ( $finished ) {
				$this->inventory->set_state( $dir_id, 'scanned' );
				unset( $cursor['scan_dir_id'], $cursor['scan_dir_pos'], $cursor['scan_dir_index'] );
			}
		}
		$cursor['scan_entries_last'] = $processed;
		$cursor['scan_bytes_last']   = $bytes;
		$cursor['scan_entries']      = (int) ( $cursor['scan_entries'] ?? 0 ) + $processed;
		$cursor['scan_bytes']        = (int) ( $cursor['scan_bytes'] ?? 0 ) + $bytes;
		return $cursor;
	}
	/**
	 * Return file type/size/mtime from one lstat() call.
	 *
	 * A large WordPress tree can contain hundreds of thousands of entries. Calling
	 * is_link(), is_dir(), is_file(), filesize() and filemtime() independently can
	 * multiply metadata syscalls on shared storage. lstat() gives us the same safe
	 * no-symlink decision plus the metadata in one bounded lookup.
	 *
	 * @return array{type:string,size:int,mtime:int}|null
	 */
	private function path_metadata( $path ) {
		$stat = @lstat( (string) $path );
		if ( ! is_array( $stat ) ) {
			return null;
		}
		$mode = isset( $stat['mode'] ) ? (int) $stat['mode'] : ( isset( $stat[2] ) ? (int) $stat[2] : 0 );
		$type = $mode & 0170000;
		if ( 0120000 === $type ) {
			return array( 'type' => 'link', 'size' => 0, 'mtime' => 0 );
		}
		if ( 0040000 === $type ) {
			return array( 'type' => 'directory', 'size' => 0, 'mtime' => 0 );
		}
		if ( 0100000 === $type ) {
			return array(
				'type' => 'file',
				'size' => max( 0, (int) ( $stat['size'] ?? $stat[7] ?? 0 ) ),
				'mtime' => max( 0, (int) ( $stat['mtime'] ?? $stat[9] ?? 0 ) ),
			);
		}
		return null;
	}

	/** Whether a custom profile excludes the current WordPress-relative path. */
	private function profile_excluded( $path, array $cursor ) {
		$relative = trim( str_replace( '\\', '/', $this->collector->relative( $path ) ), '/' );
		foreach ( (array) ( $cursor['profile_exclusions'] ?? array() ) as $excluded ) {
			$excluded = trim( str_replace( '\\', '/', (string) $excluded ), '/' );
			if ( '' !== $excluded && ( $relative === $excluded || 0 === strpos( $relative . '/', $excluded . '/' ) ) ) {
				return true;
			}
		}
		return false;
	}

}

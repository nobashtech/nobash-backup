<?php
/**
 * Durable resumable transfer repository.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Migration;

use Nobash\Backup\Archive\NativeArchiveReader;
use Nobash\Backup\Archive\ArchiveFingerprint;
use Nobash\Backup\Security\PasswordArchive;
use Nobash\Backup\Storage\LocalStorage;

 defined( 'ABSPATH' ) || exit;

/** Persist resumable browser and site-to-site archive transfers. */
class TransferRepository {

	const MAX_ARCHIVE_SIZE = 549755813888; // 512 GiB.
	const MAX_CHUNK_SIZE   = 67108864; // 64 MiB; the advertised limit remains host-adaptive.

	/**
	 * Size-aware lifetime for a continuously progressing large transfer.
	 *
	 * The previous fixed two-hour browser token could expire halfway through a
	 * 50+ GiB import on an otherwise healthy connection. Budget at a conservative
	 * 1 MiB/s plus one hour, while retaining a finite 24-hour security boundary.
	 *
	 * @return int Seconds.
	 */
	public static function recommended_ttl( $size, $minimum = 7200 ) {
		$estimate = (int) ceil( max( 1, (int) $size ) / 1048576 ) + HOUR_IN_SECONDS;
		return max( 300, min( DAY_IN_SECONDS, max( (int) $minimum, $estimate ) ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function create( $filename, $expected_size, $sha256 = '', $mode = 'upload', $ttl = 3600 ) {
		global $wpdb;
		LocalStorage::assert_write_safe();
		$this->prune_expired();
		$id       = str_replace( '-', '', wp_generate_uuid4() );
		$filename = sanitize_file_name( basename( (string) $filename ) );
		if ( ! preg_match( '/\.nbh$/i', $filename ) ) {
			$filename .= '.nbh';
		}
		$expected_size = max( 0, (int) $expected_size );
		if ( $expected_size < 1 || $expected_size > self::MAX_ARCHIVE_SIZE ) {
			throw new \RuntimeException( esc_html__( 'Transfer size is outside the supported range.', 'nobash-backup' ) );
		}
		$sha256 = strtolower( trim( (string) $sha256 ) );
		if ( '' !== $sha256 && ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			throw new \RuntimeException( esc_html__( 'Transfer checksum is invalid.', 'nobash-backup' ) );
		}
		$this->ensure_dir();
		$now   = current_time( 'mysql', true );
		$table = $wpdb->prefix . 'nobash_transfers';
		$data  = array(
			'transfer_id'       => $id,
			'mode'              => sanitize_key( $mode ),
			'filename'          => $filename,
			'expected_size'     => $expected_size,
			'received_size'     => 0,
			'sha256'            => $sha256,
			'status'            => 'receiving',
			'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + max( 300, (int) $ttl ) ),
			'created_at'        => $now,
			'updated_at'        => $now,
			'published_filename'=> '',
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert( $table, $data );
		if ( false === $ok || ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( esc_html__( 'Could not create the transfer session.', 'nobash-backup' ) );
		}
		return $data;
	}

	/** @return array<string,mixed>|null */
	public function find( $id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'nobash_transfers';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transfer table; identifier and value are prepared.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE transfer_id = %s', $table, sanitize_key( $id ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed> */
	public function append( $id, $offset, $data, $chunk_sha256 = '' ) {
		$row = $this->find( $id );
		if ( ! $row || 'receiving' !== (string) $row['status'] ) {
			throw new \RuntimeException( esc_html__( 'Transfer session is unavailable.', 'nobash-backup' ) );
		}
		if ( strtotime( (string) $row['expires_at'] . ' UTC' ) < time() ) {
			$this->mark_status( $id, 'expired' );
			throw new \RuntimeException( esc_html__( 'Transfer session has expired.', 'nobash-backup' ) );
		}
		$data   = (string) $data;
		$length = strlen( $data );
		if ( $length < 1 || $length > self::MAX_CHUNK_SIZE ) {
			throw new \RuntimeException( esc_html__( 'Transfer chunk size is invalid.', 'nobash-backup' ) );
		}
		$chunk_sha256 = strtolower( trim( (string) $chunk_sha256 ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $chunk_sha256 ) ) {
			throw new \RuntimeException( esc_html__( 'A valid SHA-256 checksum is required for every transfer chunk.', 'nobash-backup' ) );
		}
		if ( ! hash_equals( $chunk_sha256, hash( 'sha256', $data ) ) ) {
			throw new \RuntimeException( esc_html__( 'Transfer chunk checksum mismatch.', 'nobash-backup' ) );
		}

		$path = $this->path( $id );
		if ( is_link( $path ) || ( file_exists( $path ) && ! is_file( $path ) ) ) {
			throw new \RuntimeException( esc_html__( 'The transfer file path is unsafe.', 'nobash-backup' ) );
		}
		$mode = file_exists( $path ) ? 'r+b' : 'x+b';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem has no fseek()/flock()/append-cursor primitives; resumable chunked transfer writes require a direct handle held across this request.
		$fh   = fopen( $path, $mode );
		if ( ! $fh ) {
			throw new \RuntimeException( esc_html__( 'Could not open the transfer file safely.', 'nobash-backup' ) );
		}
		$actual = 0;
		$new    = 0;
		try {
			if ( ! flock( $fh, LOCK_EX ) ) {
				throw new \RuntimeException( __( 'Could not lock the transfer file.', 'nobash-backup' ) );
			}
			clearstatcache( true, $path );
			$stat = fstat( $fh );
			if ( is_link( $path ) || ! is_file( $path ) || false === $stat ) {
				throw new \RuntimeException( __( 'The transfer file changed into an unsafe filesystem object.', 'nobash-backup' ) );
			}
			$actual = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			$duplicate = false;
			if ( (int) $offset !== $actual ) {
				/* A worker can stop after the chunk is fsynced but before received_size
				 * reaches the database. Accept only the one exact already-written chunk;
				 * every other offset disagreement remains a hard ordering error. */
				if ( (int) $offset >= 0 && $actual === (int) $offset + $length && 0 === fseek( $fh, (int) $offset ) ) {
					$existing = stream_get_contents( $fh, $length );
					$duplicate = is_string( $existing )
						&& strlen( $existing ) === $length
						&& hash_equals( $chunk_sha256, hash( 'sha256', $existing ) );
				}
				if ( ! $duplicate ) {
					/* translators: %d: expected byte offset. */
					throw new \RuntimeException( sprintf( esc_html__( 'Transfer offset mismatch. Expected byte %d.', 'nobash-backup' ), (int) $actual ) );
				}
			}
			if ( (int) $offset + $length > (int) $row['expected_size'] ) {
				throw new \RuntimeException( esc_html__( 'Transfer exceeds its declared size.', 'nobash-backup' ) );
			}
			if ( ! $duplicate ) {
				if ( 0 !== fseek( $fh, 0, SEEK_END ) ) {
					throw new \RuntimeException( esc_html__( 'Could not seek the transfer file.', 'nobash-backup' ) );
				}
				$written = 0;
				while ( $written < $length ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Part of the resumable chunked-write handle opened above; WP_Filesystem cannot append at an arbitrary verified offset.
					$n = fwrite( $fh, 0 === $written ? $data : substr( $data, $written ) );
					if ( false === $n || 0 === $n ) {
						throw new \RuntimeException( esc_html__( 'Transfer chunk write was incomplete.', 'nobash-backup' ) );
					}
					$written += $n;
				}
				if ( ! fflush( $fh ) ) {
					throw new \RuntimeException( esc_html__( 'Could not checkpoint the transfer chunk.', 'nobash-backup' ) );
				}
				if ( function_exists( 'fsync' ) && ! @fsync( $fh ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					throw new \RuntimeException( esc_html__( 'Could not make the transfer chunk durable.', 'nobash-backup' ) );
				}
			}
			$new = (int) $offset + $length;
			flock( $fh, LOCK_UN );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the same direct handle opened above for resumable chunked writes.
			fclose( $fh );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'nobash_transfers';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-use progress checkpoint during an active transfer; not cacheable.
		$updated = $wpdb->update(
			$table,
			array( 'received_size' => $new, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'transfer_id' => sanitize_key( $id ) ),
			array( '%d', '%s' ),
			array( '%s' )
		);
		if ( false === $updated || ! empty( $wpdb->last_error ) ) {
			/* The file is already durable. A retry with the same offset and checksum
			 * will take the verified duplicate path above and repair this DB cursor. */
			throw new \RuntimeException( esc_html__( 'Could not checkpoint transfer progress.', 'nobash-backup' ) );
		}
		$row['received_size'] = $new;
		return $row;
	}

	/**
	 * Atomically publish a complete archive. Repeated finalize requests are idempotent.
	 *
	 * @return array<string,mixed>
	 */
	public function finalize( $id ) {
		$row = $this->find( $id );
		if ( ! $row ) {
			throw new \RuntimeException( esc_html__( 'Transfer session not found.', 'nobash-backup' ) );
		}
		if ( 'completed' === (string) $row['status'] && ! empty( $row['published_filename'] ) ) {
			$published = ( new LocalStorage() )->resolve_archive( (string) $row['published_filename'] );
			if ( $published ) {
				return array(
					'transfer_id' => (string) $row['transfer_id'],
					'filename'    => (string) $row['published_filename'],
					'size'        => (int) filesize( $published ),
					'sha256'      => (string) $row['sha256'],
					'status'      => 'completed',
					'idempotent'  => true,
				);
			}
		}
		if ( 'receiving' !== (string) $row['status'] ) {
			throw new \RuntimeException( esc_html__( 'Transfer cannot be finalized in its current state.', 'nobash-backup' ) );
		}
		$path = $this->path( $id );
		if ( is_link( $path ) || ! is_file( $path ) ) {
			throw new \RuntimeException( esc_html__( 'Transfer file is missing or unsafe.', 'nobash-backup' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem has no flock()/fseek() primitives; this handle is held across the method for an exclusive lock and a tail-byte seek/read below.
		$lock = fopen( $path, 'r+b' );
		if ( ! $lock ) {
			throw new \RuntimeException( esc_html__( 'Transfer file is missing.', 'nobash-backup' ) );
		}
		try {
			if ( ! flock( $lock, LOCK_EX ) ) {
				throw new \RuntimeException( esc_html__( 'Could not lock the completed transfer.', 'nobash-backup' ) );
			}
			clearstatcache( true, $path );
			if ( is_link( $path ) || ! is_file( $path ) || false === fstat( $lock ) ) {
				throw new \RuntimeException( esc_html__( 'The completed transfer path is unsafe.', 'nobash-backup' ) );
			}
			$size = (int) filesize( $path );
			if ( $size !== (int) $row['expected_size'] ) {
				throw new \RuntimeException( esc_html__( 'Transfer is incomplete.', 'nobash-backup' ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- One-shot 9-byte magic-header peek; the transfer file can be up to 512 GiB, so reading it whole via WP_Filesystem::get_contents() to inspect a header would defeat the point.
			$fh    = fopen( $path, 'rb' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- See fopen() justification immediately above.
			$magic = $fh ? (string) fread( $fh, 9 ) : '';
			if ( $fh ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the one-shot header-peek handle opened above.
				fclose( $fh );
			}
			if ( ! PasswordArchive::looks_like_archive( $magic ) ) {
				throw new \RuntimeException( esc_html__( 'Transferred file is not a supported .nbh archive.', 'nobash-backup' ) );
			}
			$encrypted = PasswordArchive::is_password_protected( $path );
			if ( PasswordArchive::is_password_protected( $path ) ) {
				PasswordArchive::inspect( $path );
			} elseif ( NativeArchiveReader::is_native( $path ) ) {
				// Validate the header and final marker now. Full record verification runs before restore.
				$tail = '';
				$tail_len = strlen( \Nobash\Backup\Archive\NativeArchiveWriter::END_MAGIC );
				if ( $size >= $tail_len && 0 === fseek( $lock, -$tail_len, SEEK_END ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Random-access tail read on the locked handle opened above; WP_Filesystem has no fseek() equivalent.
					$tail = (string) fread( $lock, $tail_len );
				}
				if ( \Nobash\Backup\Archive\NativeArchiveWriter::END_MAGIC !== $tail ) {
					throw new \RuntimeException( esc_html__( 'Transferred .nbh archive is incomplete.', 'nobash-backup' ) );
				}
			}

			$fingerprint_info = ArchiveFingerprint::inspect( $path );
			$sha = (string) $fingerprint_info['fingerprint'];
			if ( ! empty( $row['sha256'] ) && ! hash_equals( strtolower( (string) $row['sha256'] ), strtolower( $sha ) ) ) {
				// Remote imports may provide a traditional byte-for-byte SHA-256. Only
				// perform that expensive scan when an explicit checksum requires it.
				$raw = 'sha256' === (string) $fingerprint_info['checksum_scheme'] ? $sha : hash_file( 'sha256', $path );
				if ( false === $raw || ! hash_equals( strtolower( (string) $row['sha256'] ), strtolower( (string) $raw ) ) ) {
					throw new \RuntimeException( esc_html__( 'Transferred archive checksum mismatch.', 'nobash-backup' ) );
				}
				$sha = strtolower( (string) $raw ); $fingerprint_info['checksum_scheme'] = 'sha256';
			}

			$profile  = LocalStorage::profile_from_filename( (string) $row['filename'] );
			$filename = LocalStorage::allocate_archive_filename( $profile ? $profile : 'full' );
			$dest     = trailingslashit( LocalStorage::ensure_directory() ) . $filename;
			flock( $lock, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the locked random-access handle opened above.
			fclose( $lock );
			$lock = null;
			$this->publish_file( $path, $dest, $size );
			$storage = new LocalStorage();
			try {
				$storage->write_archive_index(
					$filename,
					array(
						'format'           => 'nbh',
						'profile'          => $profile ? $profile : 'full',
						'source'           => 'migration' === (string) $row['mode'] ? 'migration' : 'transfer',
						'sha256'           => $sha,
						'checksum_scheme'  => (string) $fingerprint_info['checksum_scheme'],
						'encrypted'        => $encrypted,
						'integrity_status' => $encrypted ? 'password_required' : 'ready_for_verification',
						'created'          => time(),
						'original_name'    => sanitize_file_name( (string) $row['filename'] ),
					)
				);
				$this->complete_row( $id, $filename, $size, $sha );
			} catch ( \Throwable $e ) {
				$sidecar = $dest . '.json';
				if ( is_link( $sidecar ) || is_file( $sidecar ) ) { wp_delete_file( $sidecar ); }
				if ( ! file_exists( $path ) && ! is_link( $path ) && is_file( $dest ) && ! is_link( $dest ) ) {
					$this->fs()->move( $dest, $path, true );
				}
				throw $e;
			}
			return array(
				'transfer_id' => sanitize_key( (string) $id ),
				'filename'    => $filename,
				'size'        => $size,
				'sha256'      => $sha,
				'checksum_scheme' => (string) $fingerprint_info['checksum_scheme'],
				'encrypted'   => $encrypted,
				'status'      => 'completed',
			);
		} finally {
			if ( is_resource( $lock ) ) {
				flock( $lock, LOCK_UN );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the locked random-access handle opened above on an exception path.
				fclose( $lock );
			}
		}
	}

	/** Cancel and delete an incomplete transfer. */
	public function cancel( $id ) {
		$row = $this->find( $id );
		if ( ! $row || 'completed' === (string) $row['status'] ) {
			return false;
		}
		$path = $this->path( $id );
		if ( is_link( $path ) || is_file( $path ) ) {
			wp_delete_file( $path );
		}
		$this->mark_status( $id, 'cancelled' );
		return true;
	}

	/** Remove expired partial files and mark their rows. */
	public function prune_expired() {
		global $wpdb;
		$table = $wpdb->prefix . 'nobash_transfers';
		$now   = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transfer table; identifier and timestamp are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT transfer_id FROM %i WHERE status = 'receiving' AND expires_at < %s LIMIT 100", $table, $now ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = sanitize_key( (string) $row['transfer_id'] );
			$path = $this->path( $id );
			if ( is_link( $path ) || is_file( $path ) ) {
				wp_delete_file( $path );
			}
			$this->mark_status( $id, 'expired' );
		}
	}

	/** @return string */
	public function path( $id ) {
		return trailingslashit( $this->ensure_dir() ) . sanitize_key( $id ) . '.part';
	}

	/** @return string */
	private function ensure_dir() {
		$dir = trailingslashit( LocalStorage::ensure_directory() ) . 'tmp/transfers';
		if ( is_link( $dir ) ) {
			throw new \RuntimeException( esc_html__( 'The transfer directory cannot be a symbolic link.', 'nobash-backup' ) );
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( esc_html__( 'Could not create the transfer directory.', 'nobash-backup' ) );
		}
		$this->fs()->chmod( $dir, 0700 );
		LocalStorage::harden( $dir );
		return $dir;
	}

	/**
	 * Lazily initialized WP_Filesystem handle for simple, single-shot filesystem operations.
	 *
	 * @return \WP_Filesystem_Base
	 */
	private function fs() {
		$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
		return $wp_filesystem;
	}

	/** Publish a transfer without following a destination symlink. @return void */
	private function publish_file( $source, $destination, $expected_size ) {
		if ( is_link( $source ) || ! is_file( $source ) || is_link( $destination ) || file_exists( $destination ) ) {
			throw new \RuntimeException( esc_html__( 'The transfer publication path is unsafe.', 'nobash-backup' ) );
		}
		if ( $this->fs()->move( $source, $destination, true ) ) {
			$this->fs()->chmod( $destination, 0640 );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Cross-device rename fallback: streams a potentially multi-GB archive in bounded chunks, which WP_Filesystem cannot do.
		$in  = fopen( $source, 'rb' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- See justification immediately above.
		$out = fopen( $destination, 'xb' );
		if ( ! $in || ! $out ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the streaming-copy handles opened above.
			if ( $in ) { fclose( $in ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the streaming-copy handles opened above.
			if ( $out ) { fclose( $out ); }
			wp_delete_file( $destination );
			throw new \RuntimeException( esc_html__( 'Could not publish the transferred archive safely.', 'nobash-backup' ) );
		}
		$bytes = 0;
		try {
			while ( ! feof( $in ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Bounded chunked read from the streaming-copy handle opened above.
				$chunk = fread( $in, 4194304 );
				if ( false === $chunk ) { throw new \RuntimeException( esc_html__( 'Could not read the completed transfer.', 'nobash-backup' ) ); }
				if ( '' === $chunk ) { continue; }
				$offset = 0; $length = strlen( $chunk );
				while ( $offset < $length ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Bounded chunked write to the streaming-copy handle opened above.
					$written = fwrite( $out, substr( $chunk, $offset ) );
					if ( false === $written || 0 === $written ) { throw new \RuntimeException( esc_html__( 'Could not write the transferred archive.', 'nobash-backup' ) ); }
					$offset += $written;
				}
				$bytes += $length;
			}
			if ( ! fflush( $out ) ) { throw new \RuntimeException( esc_html__( 'Could not flush the transferred archive.', 'nobash-backup' ) ); }
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the streaming-copy handles opened above on an exception path.
			fclose( $in ); fclose( $out ); wp_delete_file( $destination ); throw $e;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the streaming-copy handles opened above.
		fclose( $in ); fclose( $out );
		if ( $bytes !== (int) $expected_size || (int) filesize( $destination ) !== (int) $expected_size ) {
			wp_delete_file( $destination );
			throw new \RuntimeException( esc_html__( 'The published transfer size did not match the verified source.', 'nobash-backup' ) );
		}
		$this->fs()->chmod( $destination, 0640 );
		wp_delete_file( $source );
	}

	private function mark_status( $id, $status ) {
		global $wpdb;
		$table = $wpdb->prefix . 'nobash_transfers';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-use status write on the hardcoded transfers table; not cacheable.
		$wpdb->update(
			$table,
			array( 'status' => sanitize_key( $status ), 'updated_at' => current_time( 'mysql', true ) ),
			array( 'transfer_id' => sanitize_key( $id ) ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}

	private function complete_row( $id, $filename, $size, $sha ) {
		global $wpdb;
		$table = $wpdb->prefix . 'nobash_transfers';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-use completion write on the hardcoded transfers table; not cacheable.
		$updated = $wpdb->update(
			$table,
			array(
				'status'             => 'completed',
				'received_size'      => (int) $size,
				'sha256'             => (string) $sha,
				'published_filename' => basename( (string) $filename ),
				'updated_at'         => current_time( 'mysql', true ),
			),
			array( 'transfer_id' => sanitize_key( $id ) ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%s' )
		);
		if ( false === $updated || ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( esc_html__( 'Could not mark the transfer as completed.', 'nobash-backup' ) );
		}
	}
}

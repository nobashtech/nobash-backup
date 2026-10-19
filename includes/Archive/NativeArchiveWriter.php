<?php
/**
 * Append-only native .nbh archive writer.
 *
 * @package Nobash\Backup
 */
namespace Nobash\Backup\Archive;

use Nobash\Backup\Filesystem\ChunkFingerprint;

defined( 'ABSPATH' ) || exit;

/** Writes bounded, independently verifiable records with resumable integrity. */
class NativeArchiveWriter {
	const MAGIC           = "NBHSTRM2\n";
	const END_MAGIC       = "NBHEND2\n";
	const VERSION         = 3;
	const MAX_HEADER      = 1048576;
	const DEFAULT_CHUNK   = 16777216;
	const MAX_RECORD_SIZE = 16777216;

	/** @var string */ private $path;
	/** @var resource|null */ private $append_handle;
	/** @var int */ private $append_offset;
	/** @var IntegrityLedger */ private $ledger;

	/** @param string $path Archive path. */
	public function __construct( $path ) {
		$this->path = (string) $path;
		if ( '' === trim( $this->path ) ) {
			throw new \RuntimeException( esc_html__( 'The native backup archive path is missing.', 'nobash-backup' ) );
		}
		$this->append_handle = null;
		$this->append_offset = 0;
		$this->ledger = new IntegrityLedger( $this->path );
	}

	public function __destruct() { try { $this->close_append_handle( false, false ); } catch ( \Throwable $error ) { /* Uncommitted archive bytes are truncated on the next durable resume. */ } }

	/** Initialize or safely rewind to last committed offset. @return int */
	public function prepare( $committed_offset = 0, array $integrity_checkpoint = array() ) {
		$this->close_append_handle( false, false );
		$exists = is_file( $this->path );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/streaming primitives; resuming or rewinding a multi-gigabyte append-only archive to a byte checkpoint requires a direct file handle.
		$fh = fopen( $this->path, $exists ? 'c+b' : 'w+b' );
		if ( ! $fh ) { throw new \RuntimeException( esc_html__( 'Could not open the native archive.', 'nobash-backup' ) ); }
		try {
			if ( ! $exists || 0 === (int) filesize( $this->path ) ) {
				$this->write_all( $fh, self::MAGIC );
				$this->flush( $fh, true );
				$this->ledger->initialize( strlen( self::MAGIC ) );
				return strlen( self::MAGIC );
			}
			rewind( $fh );
			if ( self::MAGIC !== (string) fread( $fh, strlen( self::MAGIC ) ) ) {
				throw new \RuntimeException( esc_html__( 'Invalid native archive header.', 'nobash-backup' ) );
			}
			$minimum = strlen( self::MAGIC );
			$stat = fstat( $fh );
			$size = is_array( $stat ) && isset( $stat['size'] ) ? (int) $stat['size'] : $this->archive_size();
			$target = $committed_offset > 0 ? (int) $committed_offset : $size;
			if ( $target < $minimum || $target > $size ) { throw new \RuntimeException( esc_html__( 'Invalid native archive checkpoint.', 'nobash-backup' ) ); }
			if ( $target < $size ) {
				if ( ! ftruncate( $fh, $target ) ) { throw new \RuntimeException( esc_html__( 'Could not rewind the native archive to its checkpoint.', 'nobash-backup' ) ); }
				/* A rewind changes the durable archive boundary and must be committed.
				 * A normal resume at the existing size does not need another fsync. */
				$this->flush( $fh, true );
			}
			$this->ledger->prepare( $target, $integrity_checkpoint );
			return $target;
		} finally { fclose( $fh ); }
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/** Append one bounded source-file chunk. @return array<string,mixed> */
	public function append_file_chunk( $source, $archive_path, $file_offset, $max_bytes, array $meta = array() ) {
		$source = (string) $source;
		$source_stat = @lstat( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$source_mode = is_array( $source_stat ) ? (int) ( $source_stat['mode'] ?? $source_stat[2] ?? 0 ) : 0;
		$total = is_array( $source_stat ) && 0100000 === ( $source_mode & 0170000 ) ? (int) ( $source_stat['size'] ?? $source_stat[7] ?? -1 ) : -1;
		if ( $total < 0 ) { throw new \RuntimeException( esc_html__( 'Source file is not readable for native archive writing.', 'nobash-backup' ) ); }
		$file_offset = max( 0, (int) $file_offset );
		if ( $file_offset > $total ) { throw new \RuntimeException( esc_html__( 'Source-file archive checkpoint is invalid.', 'nobash-backup' ) ); }
		$length = min( self::MAX_RECORD_SIZE, max( 0, min( max( 1, (int) $max_bytes ), $total - $file_offset ) ) );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/streaming primitives; a bounded chunked read from an arbitrary source-file offset requires a direct file handle.
		$in = fopen( $source, 'rb' );
		if ( ! $in || 0 !== fseek( $in, $file_offset, SEEK_SET ) ) { if ( $in ) { fclose( $in ); } throw new \RuntimeException( esc_html__( 'Could not seek the source file.', 'nobash-backup' ) ); }
		try {
			/* stream_get_contents performs the bounded sequential read in C and avoids
			 * repeatedly reallocating a PHP string for 1 MiB fragments. */
			$raw = stream_get_contents( $in, $length );
			if ( false === $raw || strlen( $raw ) !== $length ) { throw new \RuntimeException( esc_html__( 'Could not read the source file chunk.', 'nobash-backup' ) ); }
		} finally { fclose( $in ); }
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		/* Do not silently archive a torn version when WordPress replaces or
		 * rewrites a source file during the bounded read. Nothing has been
		 * appended yet, so the caller can retry from its existing checkpoint. */
		$after_stat = @lstat( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $after_stat )
			|| (int) ( $after_stat['size'] ?? $after_stat[7] ?? -1 ) !== $total
			|| (int) ( $after_stat['mtime'] ?? $after_stat[9] ?? -1 ) !== (int) ( $source_stat['mtime'] ?? $source_stat[9] ?? -2 )
			|| (int) ( $after_stat['ctime'] ?? $after_stat[10] ?? -1 ) !== (int) ( $source_stat['ctime'] ?? $source_stat[10] ?? -2 )
			|| ( (int) ( $source_stat['ino'] ?? $source_stat[1] ?? 0 ) > 0 && (int) ( $after_stat['ino'] ?? $after_stat[1] ?? 0 ) !== (int) ( $source_stat['ino'] ?? $source_stat[1] ?? 0 ) )
			|| ( (int) ( $source_stat['dev'] ?? $source_stat[0] ?? 0 ) > 0 && (int) ( $after_stat['dev'] ?? $after_stat[0] ?? 0 ) !== (int) ( $source_stat['dev'] ?? $source_stat[0] ?? 0 ) ) ) {
			throw new \RuntimeException( esc_html__( 'Source file changed while it was being read; retrying from the last safe checkpoint.', 'nobash-backup' ) );
		}

		$next = $file_offset + strlen( $raw );
		$raw_sha = hash( 'sha256', $raw );
		$chunk_index = max( 0, (int) ( $meta['chunk_index'] ?? 0 ) );
		$previous_chain = (string) ( $meta['file_chain_previous'] ?? ChunkFingerprint::seed( 'file' ) );
		$file_chain = ChunkFingerprint::advance( $previous_chain, $raw_sha, $file_offset, strlen( $raw ), $chunk_index );
		$file_fingerprint = ChunkFingerprint::finalize( $file_chain, $total, $chunk_index + 1, 'file' );

		$payload = $raw; $compression = 'store';
		$level = isset( $meta['compress'] ) ? max( 0, min( 6, (int) $meta['compress'] ) ) : 0;
		if ( $level > 0 && strlen( $raw ) > 4096 && function_exists( 'gzdeflate' ) ) {
			$compressed = gzdeflate( $raw, $level );
			if ( false !== $compressed && strlen( $compressed ) + 128 < strlen( $raw ) ) { $payload = $compressed; $compression = 'deflate'; }
		}
		$record_mtime = isset( $meta['mtime'] ) ? (int) $meta['mtime'] : (int) ( $source_stat['mtime'] ?? $source_stat[9] ?? 0 );
		$record_mode = isset( $meta['mode'] ) ? (int) $meta['mode'] : 0;
		foreach ( array( 'type', 'path', 'offset', 'size', 'original_size', 'total_size', 'compression', 'final', 'sha256', 'mtime', 'mode', 'chunk_index', 'file_chain_sha256', 'file_fingerprint', 'file_checksum_scheme', 'file_chain_previous', 'compress' ) as $reserved ) {
			unset( $meta[ $reserved ] );
		}
		$header = array_merge(
			array(
				'type' => 'file_chunk', 'path' => $this->normalize_path( $archive_path ), 'offset' => $file_offset,
				'size' => strlen( $payload ), 'original_size' => strlen( $raw ), 'total_size' => $total,
				'compression' => $compression, 'final' => $next >= $total, 'sha256' => $raw_sha,
				'mtime' => $record_mtime, 'mode' => $record_mode,
				'chunk_index' => $chunk_index, 'file_chain_sha256' => $file_chain,
				'file_fingerprint' => $file_fingerprint, 'file_checksum_scheme' => ChunkFingerprint::FILE_SCHEME,
			),
			$meta
		);
		$payload_digest = 'store' === $compression ? hex2bin( $raw_sha ) : hash( 'sha256', $payload, true );
		$new_offset = $this->append_record_bytes( $header, $payload, true, $payload_digest );
		return array(
			'archive_offset' => $new_offset, 'file_offset' => $next, 'done' => $next >= $total,
			'chunk_sha256' => $raw_sha, 'bytes' => strlen( $raw ), 'file_chain_sha256' => $file_chain,
			'file_fingerprint' => $file_fingerprint, 'chunk_index' => $chunk_index + 1,
			'checksum_scheme' => ChunkFingerprint::FILE_SCHEME,
		);
	}

	/**
	 * Append one already-rendered file chunk without first materializing a source file.
	 *
	 * This is used by the database exporter so multi-gigabyte SQL can flow directly
	 * into the native archive instead of being written to a temporary SQL file and
	 * then read a second time during packaging. The same chunk-chain integrity used
	 * for normal files is preserved, so interrupted requests can rewind safely to
	 * the last durable archive checkpoint.
	 *
	 * @param string $archive_path Logical path inside the archive.
	 * @param int    $file_offset  Uncompressed byte offset for this chunk.
	 * @param string $raw          Raw bytes to append.
	 * @param bool   $final        Whether this is the final chunk for the logical file.
	 * @param array  $meta         Optional record metadata.
	 * @return array<string,mixed>
	 */
	public function append_stream_chunk( $archive_path, $file_offset, $raw, $final = false, array $meta = array() ) {
		$raw = (string) $raw;
		$file_offset = max( 0, (int) $file_offset );
		$length = strlen( $raw );
		if ( $length > self::MAX_RECORD_SIZE ) {
			throw new \RuntimeException( esc_html__( 'Native archive stream chunk exceeds the bounded record limit.', 'nobash-backup' ) );
		}

		$raw_sha = hash( 'sha256', $raw );
		$chunk_index = max( 0, (int) ( $meta['chunk_index'] ?? 0 ) );
		$previous_chain = (string) ( $meta['file_chain_previous'] ?? ChunkFingerprint::seed( 'file' ) );
		$file_chain = ChunkFingerprint::advance( $previous_chain, $raw_sha, $file_offset, $length, $chunk_index );
		$next = $file_offset + $length;
		$file_fingerprint = $final ? ChunkFingerprint::finalize( $file_chain, $next, $chunk_index + 1, 'file' ) : '';

		$record_mtime = isset( $meta['mtime'] ) ? (int) $meta['mtime'] : 0;
		$record_mode = isset( $meta['mode'] ) ? (int) $meta['mode'] : 0;
		foreach ( array( 'type', 'path', 'offset', 'size', 'original_size', 'total_size', 'compression', 'final', 'sha256', 'mtime', 'mode', 'chunk_index', 'file_chain_sha256', 'file_fingerprint', 'file_checksum_scheme', 'file_chain_previous', 'compress' ) as $reserved ) {
			unset( $meta[ $reserved ] );
		}
		$header = array_merge(
			array(
				'type' => 'file_chunk',
				'path' => $this->normalize_path( $archive_path ),
				'offset' => $file_offset,
				'size' => $length,
				'original_size' => $length,
				'compression' => 'store',
				'final' => (bool) $final,
				'sha256' => $raw_sha,
				'mtime' => $record_mtime,
				'mode' => $record_mode,
				'chunk_index' => $chunk_index,
				'file_chain_sha256' => $file_chain,
				'file_checksum_scheme' => ChunkFingerprint::FILE_SCHEME,
			),
			$meta
		);
		if ( $final ) {
			$header['total_size'] = $next;
			$header['file_fingerprint'] = $file_fingerprint;
		}

		$new_offset = $this->append_record_bytes( $header, $raw, true, hex2bin( $raw_sha ) );
		return array(
			'archive_offset' => $new_offset,
			'file_offset' => $next,
			'done' => (bool) $final,
			'chunk_sha256' => $raw_sha,
			'bytes' => $length,
			'file_chain_sha256' => $file_chain,
			'file_fingerprint' => $file_fingerprint,
			'chunk_index' => $chunk_index + 1,
			'checksum_scheme' => ChunkFingerprint::FILE_SCHEME,
		);
	}

	/** Append a small metadata record from bytes. @return int */
	public function append_bytes( $type, $path, $bytes, array $meta = array() ) {
		$bytes = (string) $bytes;
		$digest_hex = hash( 'sha256', $bytes );
		$header = array_merge( array( 'type' => (string) $type, 'path' => $this->normalize_path( $path ), 'size' => strlen( $bytes ), 'sha256' => $digest_hex ), $meta );
		return $this->append_record_bytes( $header, $bytes, true, hex2bin( $digest_hex ) );
	}

	/** Finalize without rereading a multi-gigabyte archive. @return array<string,mixed> */
	public function finalize( array $summary = array() ) {
		$this->close_append_handle( false, false );
		$size = $this->archive_size();
		$state = $this->ledger->current();
		if ( (int) $state['offset'] !== $size ) { throw new \RuntimeException( esc_html__( 'The native archive integrity checkpoint is incomplete.', 'nobash-backup' ) ); }
		$fingerprint = ChunkFingerprint::finalize( (string) $state['chain'], $size, (int) $state['records'], 'archive' );
		$payload = wp_json_encode(
			array_merge(
				array(
					'format' => 'nbh', 'version' => self::VERSION, 'content_bytes' => $size,
					'integrity_scheme' => ChunkFingerprint::ARCHIVE_SCHEME,
					'record_chain_sha256' => (string) $state['chain'], 'record_count' => (int) $state['records'],
					'archive_fingerprint' => $fingerprint, 'finalized_at' => gmdate( 'c' ),
				),
				$summary
			),
			JSON_UNESCAPED_SLASHES
		);
		$offset = $this->append_record_bytes( array( 'type' => 'end', 'path' => 'meta/final.json', 'size' => strlen( $payload ), 'sha256' => hash( 'sha256', $payload ) ), $payload, false );
		$fh = $this->append_handle();
		$this->write_all( $fh, self::END_MAGIC ); $this->append_offset += strlen( self::END_MAGIC ); $this->flush( $fh, true ); $this->close_append_handle( false, false );
		return array(
			'size' => $this->archive_size(), 'sha256' => $fingerprint, 'archive_fingerprint' => $fingerprint,
			'checksum_scheme' => ChunkFingerprint::ARCHIVE_SCHEME, 'record_chain_sha256' => (string) $state['chain'],
			'record_count' => (int) $state['records'], 'offset' => $offset + strlen( self::END_MAGIC ),
		);
	}

	/** @return int */
	private function append_record_bytes( array $header, $bytes, $track_integrity, $payload_digest_binary = null ) {
		$json = wp_json_encode( $header, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_HEADER ) { throw new \RuntimeException( esc_html__( 'Native archive record header is invalid.', 'nobash-backup' ) ); }
		if ( strlen( (string) $bytes ) > self::MAX_RECORD_SIZE ) { throw new \RuntimeException( esc_html__( 'Native archive record exceeds the bounded record limit.', 'nobash-backup' ) ); }
		$len_raw = pack( 'N', strlen( $json ) );
		$out = $this->append_handle();
		$this->write_all( $out, $len_raw ); $this->write_all( $out, $json );
		if ( '' !== (string) $bytes ) { $this->write_all( $out, (string) $bytes ); }
		$this->append_offset += 4 + strlen( $json ) + strlen( (string) $bytes );
		if ( $track_integrity ) {
			$payload_digest_binary = is_string( $payload_digest_binary ) && 32 === strlen( $payload_digest_binary ) ? $payload_digest_binary : hash( 'sha256', (string) $bytes, true );
			$record_digest = hash( 'sha256', $len_raw . $json . $payload_digest_binary );
			$this->ledger->append( $this->append_offset, $record_digest );
		}
		return $this->append_offset;
	}

	/** @return resource */
	private function append_handle() {
		if ( is_resource( $this->append_handle ) ) { return $this->append_handle; }
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem only supports whole-file get_contents()/put_contents(); the writer keeps one persistent append-mode handle open across many bounded record writes in a job slice.
		$this->append_handle = fopen( $this->path, 'ab' );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $this->append_handle ) { throw new \RuntimeException( esc_html__( 'Could not append native archive record.', 'nobash-backup' ) ); }
		$stat = fstat( $this->append_handle );
		$this->append_offset = is_array( $stat ) && isset( $stat['size'] ) ? (int) $stat['size'] : $this->archive_size();
		return $this->append_handle;
	}


	/** Read the current archive size from a live file descriptor, avoiding stale stat-cache values. @return int */
	private function archive_size() {
		clearstatcache( true, $this->path );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A short-lived direct handle is used to read the live file size, avoiding WP_Filesystem's stat cache for a multi-gigabyte archive that this class itself is actively writing.
		$fh = @fopen( $this->path, 'rb' );
		if ( ! $fh ) { return 0; }
		try {
			$stat = fstat( $fh );
			return is_array( $stat ) && isset( $stat['size'] ) ? (int) $stat['size'] : 0;
		} finally {
			fclose( $fh );
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Durably commit archive bytes and the integrity state once per job slice.
	 *
	 * @return array{offset:int,chain:string,records:int}
	 */
	public function checkpoint() {
		if ( is_resource( $this->append_handle ) ) { $this->flush( $this->append_handle, true ); }
		$state = $this->ledger->sync();
		if ( (int) $state['offset'] !== ( is_resource( $this->append_handle ) ? (int) $this->append_offset : (int) $state['offset'] ) ) {
			throw new \RuntimeException( esc_html__( 'The archive integrity checkpoint could not be committed.', 'nobash-backup' ) );
		}
		return $state;
	}

	/**
	 * Return the current in-memory integrity boundary without forcing fsync.
	 *
	 * Used to remember the beginning of an active source file. If that source
	 * changes while a multi-slice backup is reading it, the writer can rewind to
	 * this exact record-chain state and restart the file without corrupting the
	 * archive or adding a per-file durability flush to the hot path.
	 *
	 * @return array{offset:int,chain:string,records:int}
	 */
	public function integrity_state() {
		return $this->ledger->current();
	}

	/** @return void */
	private function close_append_handle( $sync_ledger = false, $durable = false ) {
		if ( is_resource( $this->append_handle ) ) {
			$this->flush( $this->append_handle, (bool) $durable );
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing this class's own persistent direct append-mode handle (opened in append_handle(); WP_Filesystem has no equivalent long-lived streaming handle).
			fclose( $this->append_handle );
			// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		$this->append_handle = null;
		if ( $sync_ledger ) { $this->ledger->sync(); }
	}

	/** @return void */
	private function flush( $fh, $durable = false ) {
		fflush( $fh );
		if ( $durable && function_exists( 'fsync' ) ) { @fsync( $fh ); }
	}

	/** @return void */
	private function write_all( $fh, $bytes ) {
		$length = strlen( $bytes ); $offset = 0;
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes to this class's own persistent direct append-mode handle in bounded loops; WP_Filesystem only supports whole-file put_contents().
		while ( $offset < $length ) {
			$n = fwrite( $fh, 0 === $offset ? $bytes : substr( $bytes, $offset ) );
			if ( false === $n || 0 === $n ) { throw new \RuntimeException( esc_html__( 'Native archive write failed.', 'nobash-backup' ) ); }
			$offset += $n;
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}

	/** @return string */
	private function normalize_path( $path ) {
		$path = ltrim( str_replace( '\\', '/', (string) $path ), '/' );
		if ( '' === $path || false !== strpos( $path, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $path ) || preg_match( '#^[A-Za-z]:/#', $path ) ) { throw new \RuntimeException( esc_html__( 'Unsafe native archive path.', 'nobash-backup' ) ); }
		return $path;
	}
}

<?php
/** Native .nbh archive reader and resumable extractor. @package Nobash\Backup */
namespace Nobash\Backup\Archive;

use Nobash\Backup\Filesystem\ChunkFingerprint;
use Nobash\Backup\Security\PathBoundary;

defined( 'ABSPATH' ) || exit;

/** Read, verify and extract bounded archive records. */
class NativeArchiveReader {
	const MAX_RECORD_SIZE = 16777216;
	/** @var string */ private $path;

	public function __construct( $path ) { $this->path = (string) $path; }

	/** @return bool */
	public static function is_native( $path ) {
		if ( ! is_readable( $path ) ) { return false; }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
		$fh = fopen( $path, 'rb' ); if ( ! $fh ) { return false; }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
		$magic = (string) fread( $fh, strlen( NativeArchiveWriter::MAGIC ) ); fclose( $fh );
		return NativeArchiveWriter::MAGIC === $magic;
	}

	/** Return the logical SHA-256 fingerprint without rereading file payloads. @return string */
	public function fingerprint() {
		$final = $this->find_final_record();
		if ( ChunkFingerprint::ARCHIVE_SCHEME !== (string) ( $final['integrity_scheme'] ?? '' ) ) { throw new \RuntimeException( esc_html__( 'This .nbh archive version is not supported.', 'nobash-backup' ) ); }
		$fingerprint = strtolower( (string) ( $final['archive_fingerprint'] ?? '' ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) { throw new \RuntimeException( esc_html__( '.nbh archive fingerprint is invalid.', 'nobash-backup' ) ); }
		return $fingerprint;
	}

	/** Whether this archive carries a resumable record-chain fingerprint. @return bool */
	public function uses_record_chain() {
		$final = $this->find_final_record();
		return ChunkFingerprint::ARCHIVE_SCHEME === (string) ( $final['integrity_scheme'] ?? '' );
	}

	/** @return array<string,mixed> */
	public function verify() { return $this->walk( null ); }
	/** @return array<string,mixed> */
	public function extract( $dest ) { wp_mkdir_p( $dest ); return $this->walk( (string) $dest ); }

	/**
	 * Extract bounded complete records and return durable archive/file integrity state.
	 *
	 * @return array<string,mixed>
	 */
	public function extract_slice( $dest, $offset, $time_budget = 5.0, $record_limit = 250, $plan_file = '', array $known_final = array(), array $known_integrity = array(), $plan_offset = null, $retain_path = null ) {
		wp_mkdir_p( $dest );
		$minimum = strlen( NativeArchiveWriter::MAGIC ); $offset = max( $minimum, (int) $offset );
		$integrity = $this->normalize_integrity( $known_integrity, $offset );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
		$fh = fopen( $this->path, 'rb' ); if ( ! $fh ) { throw new \RuntimeException( esc_html__( 'Could not open the backup archive.', 'nobash-backup' ) ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
		if ( 0 !== fseek( $fh, 0, SEEK_SET ) || NativeArchiveWriter::MAGIC !== $this->read_exact( $fh, $minimum ) ) { fclose( $fh ); throw new \RuntimeException( esc_html__( 'Invalid .nbh archive header.', 'nobash-backup' ) ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
		if ( 0 !== fseek( $fh, $offset, SEEK_SET ) ) { fclose( $fh ); throw new \RuntimeException( esc_html__( 'Invalid restore archive checkpoint.', 'nobash-backup' ) ); }
		$started = microtime( true ); $records = 0; $payload_bytes = 0; $done = false; $final = $known_final; $last_sync_target = ''; $plan_lines = '';
		$plan_offset = $this->prepare_plan_file( $plan_file, $plan_offset );
		try {
			while ( $records < max( 1, min( 5000, (int) $record_limit ) ) && ( microtime( true ) - $started ) < max( 1.0, (float) $time_budget ) ) {
				$record_start = (int) ftell( $fh );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
				$peek = fread( $fh, strlen( NativeArchiveWriter::END_MAGIC ) );
				if ( NativeArchiveWriter::END_MAGIC === $peek ) { $offset = (int) ftell( $fh ); $done = true; break; }
				if ( strlen( $peek ) < 4 ) { throw new \RuntimeException( esc_html__( '.nbh archive is incomplete.', 'nobash-backup' ) ); }
				fseek( $fh, $record_start, SEEK_SET );
				$record = $this->read_header_record( $fh ); $header = $record['header']; $size = (int) $header['size']; $type = (string) $header['type'];
				$stored_sha = ''; $raw_size = 0;
				$discard = is_callable( $retain_path ) && ! call_user_func( $retain_path, (string) $header['path'] );
				// Validate even discarded paths; filtering never weakens archive boundaries.
				if ( $discard ) { $this->safe_target( $dest, $header['path'] ); }
				if ( 'end' === $type ) {
					$stored = $this->read_exact( $fh, $size ); $stored_sha = hash( 'sha256', $stored );
					$raw = $this->decode_and_verify( $header, $stored ); $raw_size = strlen( $raw ); $final = json_decode( $raw, true );
					if ( ! is_array( $final ) ) { throw new \RuntimeException( esc_html__( '.nbh archive final integrity record is malformed.', 'nobash-backup' ) ); }
					$integrity['end_record_start'] = $record_start;
				} elseif ( 'store' === (string) ( $header['compression'] ?? 'store' ) ) {
					$r = $this->extract_stored_record( $fh, $dest, $header, false, $discard ); $raw_size = $r['raw_size']; $stored_sha = $r['stored_sha256'];
					if ( '' !== (string) $r['target'] ) { $last_sync_target = (string) $r['target']; }
				} else {
					$stored = $this->read_exact( $fh, $size ); $stored_sha = hash( 'sha256', $stored );
					$raw = $this->decode_and_verify( $header, $stored ); $raw_size = strlen( $raw );
					$target = $discard ? '' : $this->apply_record_bytes( $dest, $header, $raw, false ); if ( '' !== $target ) { $last_sync_target = $target; }
				}
				$record_end = (int) ftell( $fh );
				if ( 'end' !== $type ) {
					$record_digest = hash( 'sha256', $record['len_raw'] . $record['header_raw'] . hex2bin( $stored_sha ) );
					$integrity['archive_chain'] = ChunkFingerprint::advance( (string) $integrity['archive_chain'], $record_digest, $record_start, $record_end - $record_start, (int) $integrity['archive_records'] );
					$integrity['archive_records'] = (int) $integrity['archive_records'] + 1;
				}
				if ( 'file_chunk' === $type ) {
					$integrity = $this->advance_file_integrity( $integrity, $header );
					if ( ! $discard && ! empty( $header['final'] ) ) { $plan_lines .= $this->finish_extracted_file( $this->safe_target( $dest, $header['path'] ), $header, $integrity ); }
				}
				$offset = $record_end; ++$records; $payload_bytes += $raw_size;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
		} finally { fclose( $fh ); }
		/* All touched files were fflush'd when their handles closed. One fsync on
		 * the last target anchors the slice without issuing hundreds/thousands of
		 * synchronous flushes for a small-file-heavy archive. */
		if ( '' !== $last_sync_target ) { $this->sync_targets( array( $last_sync_target ) ); }
		$plan_offset = $this->append_plan_lines( $plan_file, $plan_lines, $plan_offset );
		if ( $done ) {
			if ( empty( $final ) ) { $final = $this->find_final_record(); }
			$this->verify_content_digest( $final, $integrity );
		}
		return array( 'offset' => $offset, 'records' => $records, 'payload_bytes' => $payload_bytes, 'done' => $done, 'final' => $final, 'integrity' => $integrity, 'plan_offset' => $plan_offset );
	}

	/** @return void */
	public function verify_content_digest( array $final, array $integrity = array() ) {
		if ( 'nbh' !== (string) ( $final['format'] ?? '' ) || ChunkFingerprint::ARCHIVE_SCHEME !== (string) ( $final['integrity_scheme'] ?? '' ) ) { throw new \RuntimeException( esc_html__( 'This .nbh archive version is not supported.', 'nobash-backup' ) ); }
		$chain = strtolower( (string) ( $integrity['archive_chain'] ?? '' ) ); $records = (int) ( $integrity['archive_records'] ?? -1 );
		$boundary = (int) ( $integrity['end_record_start'] ?? -1 );
		if ( ! hash_equals( strtolower( (string) ( $final['record_chain_sha256'] ?? '' ) ), $chain ) || $records !== (int) ( $final['record_count'] ?? -2 ) ) { throw new \RuntimeException( esc_html__( '.nbh archive record-chain verification failed.', 'nobash-backup' ) ); }
		if ( $boundary !== (int) ( $final['content_bytes'] ?? -2 ) ) { throw new \RuntimeException( esc_html__( '.nbh archive final digest boundary is invalid.', 'nobash-backup' ) ); }
		$expected = ChunkFingerprint::finalize( $chain, $boundary, $records, 'archive' );
		if ( ! hash_equals( strtolower( (string) ( $final['archive_fingerprint'] ?? '' ) ), $expected ) ) { throw new \RuntimeException( esc_html__( '.nbh archive fingerprint mismatch.', 'nobash-backup' ) ); }
	}

	/** @return array{raw_size:int,stored_sha256:string,target:string} */
	private function extract_stored_record( $fh, $dest, array $header, $durable = true, $discard = false ) {
		$type = (string) $header['type']; $size = (int) $header['size']; $hash = hash_init( 'sha256' ); $remaining = $size;
		if ( $discard ) {
			while ( $remaining > 0 ) { $chunk = $this->read_exact( $fh, min( 4194304, $remaining ) ); hash_update( $hash, $chunk ); $remaining -= strlen( $chunk ); }
			$actual = hash_final( $hash );
			if ( ! hash_equals( strtolower( (string) $header['sha256'] ), $actual ) ) { throw new \RuntimeException( esc_html__( '.nbh archive record checksum mismatch.', 'nobash-backup' ) ); }
			if ( isset( $header['original_size'] ) && (int) $header['original_size'] !== $size ) { throw new \RuntimeException( esc_html__( '.nbh archive record size mismatch.', 'nobash-backup' ) ); }
			return array( 'raw_size' => $size, 'stored_sha256' => $actual, 'target' => '' );
		}
		$target = $this->safe_target( $dest, $header['path'] ); wp_mkdir_p( dirname( $target ) );
		$mode = 'file_chunk' === $type && (int) ( $header['offset'] ?? 0 ) > 0 ? 'c+b' : 'w+b';
		if ( is_link( $target ) ) { throw new \RuntimeException( esc_html__( 'Archive extraction blocked a symbolic-link target.', 'nobash-backup' ) ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
		$out = fopen( $target, $mode );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
		if ( ! $out || ( 'file_chunk' === $type && 0 !== fseek( $out, (int) ( $header['offset'] ?? 0 ) ) ) ) { if ( $out ) { fclose( $out ); } throw new \RuntimeException( esc_html__( 'Could not prepare extracted archive data.', 'nobash-backup' ) ); }
		try {
			while ( $remaining > 0 ) { $chunk = $this->read_exact( $fh, min( 4194304, $remaining ) ); hash_update( $hash, $chunk ); $this->write_all( $out, $chunk ); $remaining -= strlen( $chunk ); }
			fflush( $out ); if ( $durable && function_exists( 'fsync' ) ) { @fsync( $out ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
		} finally { fclose( $out ); }
		$actual = hash_final( $hash );
		if ( ! hash_equals( strtolower( (string) $header['sha256'] ), $actual ) ) { throw new \RuntimeException( esc_html__( '.nbh archive record checksum mismatch.', 'nobash-backup' ) ); }
		if ( isset( $header['original_size'] ) && (int) $header['original_size'] !== $size ) { throw new \RuntimeException( esc_html__( '.nbh archive record size mismatch.', 'nobash-backup' ) ); }
		return array( 'raw_size' => $size, 'stored_sha256' => $actual, 'target' => $target );
	}

	/** @return void */
	private function apply_record_bytes( $dest, array $header, $raw, $durable = true ) {
		$type = (string) $header['type']; if ( 'end' === $type ) { return ''; }
		$target = $this->safe_target( $dest, $header['path'] ); wp_mkdir_p( dirname( $target ) );
		if ( 'file_chunk' === $type ) {
			$offset = (int) ( $header['offset'] ?? 0 ); $mode = 0 === $offset ? 'w+b' : 'c+b';
			if ( is_link( $target ) ) { throw new \RuntimeException( esc_html__( 'Archive extraction blocked a symbolic-link target.', 'nobash-backup' ) ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
			$out = fopen( $target, $mode );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
			if ( ! $out || 0 !== fseek( $out, $offset ) ) { if ( $out ) { fclose( $out ); } throw new \RuntimeException( esc_html__( 'Could not prepare extracted file.', 'nobash-backup' ) ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
			try { $this->write_all( $out, $raw ); fflush( $out ); if ( $durable && function_exists( 'fsync' ) ) { @fsync( $out ); } } finally { fclose( $out ); }
			return $target;
		}
		if ( is_link( $target ) ) { throw new \RuntimeException( esc_html__( 'Archive extraction blocked a symbolic-link target.', 'nobash-backup' ) ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Requires LOCK_EX for exclusive-write safety during resumable extraction; WP_Filesystem::put_contents() has no locking primitive.
		if ( strlen( $raw ) !== file_put_contents( $target, $raw, LOCK_EX ) ) { throw new \RuntimeException( esc_html__( 'Could not extract archive metadata.', 'nobash-backup' ) ); }
		return $target;
	}

	/** Durably synchronize files touched by the committed extraction slice. @return void */
	private function sync_targets( array $targets ) {
		foreach ( array_values( array_unique( array_filter( array_map( 'strval', $targets ) ) ) ) as $target ) {
			if ( is_link( $target ) ) { throw new \RuntimeException( esc_html__( 'An extracted file changed into a symbolic link before synchronization.', 'nobash-backup' ) ); }
			if ( ! is_file( $target ) ) { continue; }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
			$fh = fopen( $target, 'ab' );
			if ( ! $fh ) { throw new \RuntimeException( esc_html__( 'Could not synchronize extracted archive data.', 'nobash-backup' ) ); }
			try { fflush( $fh ); if ( function_exists( 'fsync' ) ) { @fsync( $fh ); } }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
			finally { fclose( $fh ); }
		}
	}

	/** Build one deployment-plan line after the final verified file chunk. @return string */
	private function finish_extracted_file( $target, array $header, array $integrity ) {
		$total = (int) ( $header['total_size'] ?? -1 );
		if ( $total < 0 || (int) filesize( $target ) !== $total ) { throw new \RuntimeException( esc_html__( 'Extracted file size verification failed.', 'nobash-backup' ) ); }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Preserving the archived mtime on a resumable, per-record extraction target; WP_Filesystem::touch() would require a bootstrap on every chunk write for archives with thousands of file chunks.
		if ( ! empty( $header['mtime'] ) ) { @touch( $target, (int) $header['mtime'] ); }
		if ( 0 !== strpos( (string) $header['path'], 'files/' ) ) { return ''; }
		$scheme = (string) ( $header['file_checksum_scheme'] ?? 'sha256' );
		if ( ChunkFingerprint::FILE_SCHEME === $scheme ) { $checksum = (string) ( $integrity['last_file_fingerprint'] ?? '' ); }
		else { $checksum = ( 0 === (int) ( $header['offset'] ?? 0 ) && (int) ( $header['original_size'] ?? -1 ) === $total ) ? (string) $header['sha256'] : hash_file( 'sha256', $target ); }
		if ( false === $checksum || ! preg_match( '/^[a-f0-9]{64}$/', strtolower( (string) $checksum ) ) ) { throw new \RuntimeException( esc_html__( 'Could not verify the extracted file.', 'nobash-backup' ) ); }
		$entry = wp_json_encode( array( 'path' => (string) $header['path'], 'size' => $total, 'sha256' => strtolower( (string) $checksum ), 'checksum_scheme' => $scheme ) );
		return is_string( $entry ) ? $entry . "\n" : '';
	}

	/** Rewind a deployment plan to its durable cursor before replaying a slice. @return int|null */
	private function prepare_plan_file( $plan_file, $committed_offset ) {
		if ( '' === (string) $plan_file || null === $committed_offset ) { return null === $committed_offset ? null : max( 0, (int) $committed_offset ); }
		$committed_offset = max( 0, (int) $committed_offset );
		$mode = file_exists( $plan_file ) ? 'c+b' : 'w+b';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
		$fh = fopen( $plan_file, $mode );
		if ( ! $fh ) { throw new \RuntimeException( esc_html__( 'Could not prepare the restore deployment plan.', 'nobash-backup' ) ); }
		try {
			if ( ! flock( $fh, LOCK_EX ) ) { throw new \RuntimeException( esc_html__( 'Could not lock the restore deployment plan.', 'nobash-backup' ) ); }
			$stat = fstat( $fh ); $size = is_array( $stat ) && isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			if ( $committed_offset > $size ) { throw new \RuntimeException( esc_html__( 'The restore deployment plan checkpoint is invalid.', 'nobash-backup' ) ); }
			if ( $size > $committed_offset && ! ftruncate( $fh, $committed_offset ) ) { throw new \RuntimeException( esc_html__( 'Could not rewind the restore deployment plan.', 'nobash-backup' ) ); }
			fflush( $fh ); if ( function_exists( 'fsync' ) ) { @fsync( $fh ); } flock( $fh, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
		} finally { fclose( $fh ); }
		return $committed_offset;
	}

	/** Append all plan entries produced by one extraction slice in one write. @return int|null */
	private function append_plan_lines( $plan_file, $lines, $committed_offset ) {
		if ( '' === (string) $plan_file || '' === (string) $lines ) { return $committed_offset; }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
		$fh = fopen( $plan_file, 'ab' );
		if ( ! $fh ) { throw new \RuntimeException( esc_html__( 'Could not write the restore deployment plan.', 'nobash-backup' ) ); }
		try {
			if ( ! flock( $fh, LOCK_EX ) ) { throw new \RuntimeException( esc_html__( 'Could not lock the restore deployment plan.', 'nobash-backup' ) ); }
			// Append mode writes at EOF, but its initial logical position can be zero.
			// Seek explicitly so ftell returns the full durable plan offset on every slice.
			if ( 0 !== fseek( $fh, 0, SEEK_END ) ) { throw new \RuntimeException( esc_html__( 'Could not seek the restore deployment plan.', 'nobash-backup' ) ); }
			$this->write_all( $fh, (string) $lines ); fflush( $fh ); if ( function_exists( 'fsync' ) ) { @fsync( $fh ); }
			$offset = (int) ftell( $fh ); flock( $fh, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
		} finally { fclose( $fh ); }
		return $offset;
	}

	/** @return array<string,mixed> */
	private function walk( $dest ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
		$fh = fopen( $this->path, 'rb' ); if ( ! $fh ) { throw new \RuntimeException( esc_html__( 'Could not open the backup archive.', 'nobash-backup' ) ); }
		$records = 0; $bytes = 0; $file_count = 0; $final = null;
		$integrity = $this->normalize_integrity( array(), strlen( NativeArchiveWriter::MAGIC ) );
		try {
			$magic = $this->read_exact( $fh, strlen( NativeArchiveWriter::MAGIC ) );
			if ( NativeArchiveWriter::MAGIC !== $magic ) { throw new \RuntimeException( esc_html__( 'Invalid .nbh archive header.', 'nobash-backup' ) ); }
			while ( true ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
				$record_start = (int) ftell( $fh ); $peek = fread( $fh, strlen( NativeArchiveWriter::END_MAGIC ) );
				if ( NativeArchiveWriter::END_MAGIC === $peek ) { break; }
				if ( strlen( $peek ) < 4 ) { throw new \RuntimeException( esc_html__( '.nbh archive is incomplete.', 'nobash-backup' ) ); }
				fseek( $fh, $record_start, SEEK_SET ); $record = $this->read_header_record( $fh ); $header = $record['header']; $is_end = 'end' === (string) $header['type'];
				$size = (int) $header['size']; $stored = ''; $remaining = $size; $stored_hash = hash_init( 'sha256' );
				while ( $remaining > 0 ) { $chunk = $this->read_exact( $fh, min( 1048576, $remaining ) ); $stored .= $chunk; hash_update( $stored_hash, $chunk ); $remaining -= strlen( $chunk ); }
				$stored_sha = hash_final( $stored_hash ); $raw = $this->decode_and_verify( $header, $stored ); $record_end = (int) ftell( $fh );
				if ( ! $is_end ) {
					$record_digest = hash( 'sha256', $record['len_raw'] . $record['header_raw'] . hex2bin( $stored_sha ) );
					$integrity['archive_chain'] = ChunkFingerprint::advance( (string) $integrity['archive_chain'], $record_digest, $record_start, $record_end - $record_start, (int) $integrity['archive_records'] );
					$integrity['archive_records'] = (int) $integrity['archive_records'] + 1;
					if ( null !== $dest ) { $this->apply_record_bytes( $dest, $header, $raw ); }
				}
				if ( 'file_chunk' === (string) $header['type'] ) { $before = (string) ( $integrity['active_file_path'] ?? '' ); $integrity = $this->advance_file_integrity( $integrity, $header ); if ( $before !== (string) $header['path'] ) { ++$file_count; } }
				if ( $is_end ) { $final = json_decode( $raw, true ); $integrity['end_record_start'] = $record_start; }
				++$records; $bytes += $size;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/append-mode/streaming primitives; resumable chunked archive I/O requires direct file handles to avoid loading multi-GB archives into memory.
		} finally { fclose( $fh ); }
		if ( empty( $integrity['active_file_complete'] ) ) { throw new \RuntimeException( esc_html__( '.nbh archive ended before a file was complete.', 'nobash-backup' ) ); }
		if ( ! is_array( $final ) ) { throw new \RuntimeException( esc_html__( '.nbh archive final integrity record is missing.', 'nobash-backup' ) ); }
		if ( ChunkFingerprint::ARCHIVE_SCHEME !== (string) ( $final['integrity_scheme'] ?? '' ) ) { throw new \RuntimeException( esc_html__( 'This .nbh archive version is not supported.', 'nobash-backup' ) ); }
		$this->verify_content_digest( $final, $integrity );
		return array( 'records' => $records, 'payload_bytes' => $bytes, 'files' => $file_count, 'final' => $final, 'fingerprint' => (string) $final['archive_fingerprint'] );
	}

	/** @return array<string,mixed> */
	private function normalize_integrity( array $state, $offset ) {
		$minimum = strlen( NativeArchiveWriter::MAGIC );
		if ( empty( $state ) ) {
			if ( (int) $offset !== $minimum ) { throw new \RuntimeException( esc_html__( 'The resumable archive integrity checkpoint is missing.', 'nobash-backup' ) ); }
			return array( 'archive_chain' => ChunkFingerprint::seed( 'archive-records' ), 'archive_records' => 0, 'active_file_path' => '', 'active_file_offset' => 0, 'active_file_chain' => ChunkFingerprint::seed( 'file' ), 'active_file_chunks' => 0, 'active_file_complete' => true, 'last_file_fingerprint' => '' );
		}
		return array_merge( array( 'archive_chain' => ChunkFingerprint::seed( 'archive-records' ), 'archive_records' => 0, 'active_file_path' => '', 'active_file_offset' => 0, 'active_file_chain' => ChunkFingerprint::seed( 'file' ), 'active_file_chunks' => 0, 'active_file_complete' => true, 'last_file_fingerprint' => '' ), $state );
	}

	/** @return array<string,mixed> */
	private function advance_file_integrity( array $state, array $header ) {
		$path = (string) $header['path']; $offset = (int) ( $header['offset'] ?? 0 ); $chunk_index = (int) ( $header['chunk_index'] ?? $state['active_file_chunks'] );
		if ( $path !== (string) $state['active_file_path'] ) {
			if ( empty( $state['active_file_complete'] ) ) { throw new \RuntimeException( esc_html__( '.nbh archive file chunks are interleaved or incomplete.', 'nobash-backup' ) ); }
			if ( 0 !== $offset || 0 !== $chunk_index ) { throw new \RuntimeException( esc_html__( '.nbh archive file chunks are out of order.', 'nobash-backup' ) ); }
			$state['active_file_path'] = $path; $state['active_file_offset'] = 0; $state['active_file_chain'] = ChunkFingerprint::seed( 'file' ); $state['active_file_chunks'] = 0; $state['active_file_complete'] = false;
		}
		if ( $offset !== (int) $state['active_file_offset'] || $chunk_index !== (int) $state['active_file_chunks'] ) { throw new \RuntimeException( esc_html__( '.nbh archive file chunks are out of order.', 'nobash-backup' ) ); }
		$length = (int) ( $header['original_size'] ?? $header['size'] );
		$next = ChunkFingerprint::advance( (string) $state['active_file_chain'], (string) $header['sha256'], $offset, $length, $chunk_index );
		if ( isset( $header['file_chain_sha256'] ) && ! hash_equals( strtolower( (string) $header['file_chain_sha256'] ), $next ) ) { throw new \RuntimeException( esc_html__( '.nbh file chunk-chain verification failed.', 'nobash-backup' ) ); }
		$state['active_file_chain'] = $next; $state['active_file_chunks'] = $chunk_index + 1; $state['active_file_offset'] = $offset + $length;
		if ( ! empty( $header['final'] ) ) {
			$total = (int) ( $header['total_size'] ?? -1 );
			if ( (int) $state['active_file_offset'] !== $total ) { throw new \RuntimeException( esc_html__( '.nbh archive file size verification failed.', 'nobash-backup' ) ); }
			$fingerprint = ChunkFingerprint::finalize( $next, $total, (int) $state['active_file_chunks'], 'file' );
			if ( isset( $header['file_fingerprint'] ) && ! hash_equals( strtolower( (string) $header['file_fingerprint'] ), $fingerprint ) ) { throw new \RuntimeException( esc_html__( '.nbh file fingerprint verification failed.', 'nobash-backup' ) ); }
			$state['last_file_fingerprint'] = $fingerprint; $state['active_file_complete'] = true;
		}
		return $state;
	}

	/** @return array{header:array<string,mixed>,len_raw:string,header_raw:string} */
	private function read_header_record( $fh ) {
		$len_raw = $this->read_exact( $fh, 4 ); $hlen = unpack( 'Nlen', $len_raw )['len'];
		if ( $hlen < 2 || $hlen > NativeArchiveWriter::MAX_HEADER ) { throw new \RuntimeException( esc_html__( '.nbh archive record header length is invalid.', 'nobash-backup' ) ); }
		$header_raw = $this->read_exact( $fh, $hlen ); $header = json_decode( $header_raw, true ); $this->validate_header( $header );
		return array( 'header' => $header, 'len_raw' => $len_raw, 'header_raw' => $header_raw );
	}

	/** @return void */
	private function validate_header( $header ) {
		if ( ! is_array( $header ) || ! isset( $header['type'], $header['path'], $header['size'], $header['sha256'] ) ) { throw new \RuntimeException( esc_html__( '.nbh archive record header is malformed.', 'nobash-backup' ) ); }
		$type = (string) $header['type'];
		if ( ! in_array( $type, array( 'file_chunk', 'metadata', 'manifest', 'end' ), true ) ) { throw new \RuntimeException( esc_html__( '.nbh archive record type is not supported.', 'nobash-backup' ) ); }
		if ( ! preg_match( '/^[a-f0-9]{64}$/i', (string) $header['sha256'] ) ) { throw new \RuntimeException( esc_html__( '.nbh archive record checksum is malformed.', 'nobash-backup' ) ); }
		$size = (int) $header['size']; if ( $size < 0 || $size > self::MAX_RECORD_SIZE ) { throw new \RuntimeException( esc_html__( '.nbh archive record exceeds safety limits.', 'nobash-backup' ) ); }
	}

	/** @return string */
	private function decode_and_verify( array $header, $stored ) {
		$raw = (string) $stored;
		if ( 'deflate' === (string) ( $header['compression'] ?? 'store' ) ) { $raw = gzinflate( $stored, self::MAX_RECORD_SIZE ); if ( false === $raw ) { throw new \RuntimeException( esc_html__( '.nbh archive record decompression failed.', 'nobash-backup' ) ); } }
		if ( isset( $header['original_size'] ) && ( (int) $header['original_size'] < 0 || (int) $header['original_size'] > self::MAX_RECORD_SIZE || strlen( $raw ) !== (int) $header['original_size'] ) ) { throw new \RuntimeException( esc_html__( '.nbh archive record size mismatch.', 'nobash-backup' ) ); }
		if ( ! hash_equals( strtolower( (string) $header['sha256'] ), hash( 'sha256', $raw ) ) ) { throw new \RuntimeException( esc_html__( '.nbh archive record checksum mismatch.', 'nobash-backup' ) ); }
		return $raw;
	}

	/** Locate final record while seeking over large payloads. @return array<string,mixed> */
	private function find_final_record() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem has no fseek()/streaming primitives; locating the final record requires seeking over large payloads without loading them into memory.
		$fh = fopen( $this->path, 'rb' ); if ( ! $fh ) { throw new \RuntimeException( esc_html__( 'Could not open the backup archive.', 'nobash-backup' ) ); }
		$final = array(); $end_seen = false;
		try {
			if ( NativeArchiveWriter::MAGIC !== $this->read_exact( $fh, strlen( NativeArchiveWriter::MAGIC ) ) ) { throw new \RuntimeException( esc_html__( 'Invalid .nbh archive header.', 'nobash-backup' ) ); }
			while ( true ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- WP_Filesystem has no fseek()/streaming primitives; locating the final record requires seeking over large payloads without loading them into memory.
				$start = (int) ftell( $fh ); $peek = fread( $fh, strlen( NativeArchiveWriter::END_MAGIC ) );
				if ( NativeArchiveWriter::END_MAGIC === $peek ) { $end_seen = true; break; }
				if ( strlen( $peek ) < 4 ) { throw new \RuntimeException( esc_html__( '.nbh archive is incomplete.', 'nobash-backup' ) ); }
				fseek( $fh, $start, SEEK_SET ); $record = $this->read_header_record( $fh ); $header = $record['header']; $size = (int) $header['size'];
				if ( 'end' === (string) $header['type'] ) { $raw = $this->decode_and_verify( $header, $this->read_exact( $fh, $size ) ); $final = json_decode( $raw, true ); }
				elseif ( 0 !== fseek( $fh, $size, SEEK_CUR ) ) { throw new \RuntimeException( esc_html__( '.nbh archive record range is invalid.', 'nobash-backup' ) ); }
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/streaming primitives; locating the final record requires seeking over large payloads without loading them into memory.
		} finally { fclose( $fh ); }
		if ( ! $end_seen || ! is_array( $final ) || empty( $final ) ) { throw new \RuntimeException( esc_html__( '.nbh archive final integrity record is missing.', 'nobash-backup' ) ); }
		return $final;
	}

	/** @return string */
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- WP_Filesystem has no streaming primitives; reads an exact byte count from an open archive handle in bounded chunks without loading the whole archive into memory.
	private function read_exact( $fh, $length ) { $buffer = ''; while ( strlen( $buffer ) < $length ) { $chunk = fread( $fh, $length - strlen( $buffer ) ); if ( false === $chunk || '' === $chunk ) { throw new \RuntimeException( esc_html__( 'Unexpected end of .nbh archive.', 'nobash-backup' ) ); } $buffer .= $chunk; } return $buffer; }
	/** @return void */
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WP_Filesystem has no streaming primitives; writes bounded chunks to an open archive handle without loading the whole payload into memory.
	private function write_all( $fh, $bytes ) { $length = strlen( $bytes ); $offset = 0; while ( $offset < $length ) { $written = fwrite( $fh, 0 === $offset ? $bytes : substr( $bytes, $offset ) ); if ( false === $written || 0 === $written ) { throw new \RuntimeException( esc_html__( 'Could not write extracted archive data.', 'nobash-backup' ) ); } $offset += $written; } }
	/** @return string */
	private function safe_target( $dest, $path ) {
		$dest = (string) $dest;
		if ( is_link( $dest ) ) {
			throw new \RuntimeException( esc_html__( 'The archive extraction root cannot be a symbolic link.', 'nobash-backup' ) );
		}
		if ( ! is_dir( $dest ) && ! wp_mkdir_p( $dest ) ) {
			throw new \RuntimeException( esc_html__( 'Invalid extraction destination.', 'nobash-backup' ) );
		}

		$target = PathBoundary::resolve( $dest, (string) $path, true );
		$parent = dirname( $target );
		if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
			throw new \RuntimeException( esc_html__( 'Could not create an archive extraction directory.', 'nobash-backup' ) );
		}

		// Re-resolve after creating parents to catch symlink substitution and reject
		// a pre-existing symlink at the final filename before any write occurs.
		$target = PathBoundary::resolve( $dest, (string) $path, true );
		if ( is_link( $target ) ) {
			throw new \RuntimeException( esc_html__( 'Archive extraction blocked a symbolic-link target.', 'nobash-backup' ) );
		}
		if ( file_exists( $target ) && ! is_file( $target ) ) {
			throw new \RuntimeException( esc_html__( 'Archive extraction target is not a regular file.', 'nobash-backup' ) );
		}
		return $target;
	}
	/** @return string */
}

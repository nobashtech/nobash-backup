<?php
/** Durable append-only journal for destructive restore actions. @package Nobash\Backup */
namespace Nobash\Backup\Restore;

defined( 'ABSPATH' ) || exit;

/** Persist filesystem restore actions before they are applied. */
class RestoreJournal {
	const INDEX_RECORD_BYTES = 21;
	/** @var string */ private $path;
	/** @var string */ private $index_path;

	public function __construct( $path ) {
		$this->path = (string) $path;
		$this->index_path = $this->path . '.idx';
	}

	/** @return string */
	public function path() { return $this->path; }

	/** Append the action and its fixed-width reverse index before the mutation occurs. @return void */
	public function append( array $entry ) {
		$directory = dirname( $this->path );
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) { throw new \RuntimeException( esc_html__( 'Could not create the restore journal directory.', 'nobash-backup' ) ); }
		$entry['recorded_at'] = gmdate( 'c' );
		$line = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES ) . "\n";
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/flock()/streaming primitives; the append-only journal and its fixed-width reverse index require direct file handles for durable, lockable appends.
		$handle = fopen( $this->path, 'ab+' );
		if ( ! $handle ) { throw new \RuntimeException( esc_html__( 'Could not open the restore journal.', 'nobash-backup' ) ); }
		try {
			if ( ! flock( $handle, LOCK_EX ) ) { throw new \RuntimeException( esc_html__( 'Could not lock the restore journal.', 'nobash-backup' ) ); }
			fseek( $handle, 0, SEEK_END );
			$offset = (int) ftell( $handle );
			$written = fwrite( $handle, $line );
			if ( false === $written || $written !== strlen( $line ) ) { throw new \RuntimeException( esc_html__( 'Could not persist the restore journal checkpoint.', 'nobash-backup' ) ); }
			fflush( $handle ); if ( function_exists( 'fsync' ) ) { @fsync( $handle ); }
			$index = fopen( $this->index_path, 'ab' );
			if ( ! $index ) { throw new \RuntimeException( esc_html__( 'Could not open the restore journal index.', 'nobash-backup' ) ); }
			try {
				if ( ! flock( $index, LOCK_EX ) ) { throw new \RuntimeException( esc_html__( 'Could not lock the restore journal index.', 'nobash-backup' ) ); }
				$record = sprintf( '%020d', $offset ) . "\n";
				$index_written = fwrite( $index, $record );
				if ( false === $index_written || self::INDEX_RECORD_BYTES !== $index_written ) { throw new \RuntimeException( esc_html__( 'Could not persist the restore journal index.', 'nobash-backup' ) ); }
				fflush( $index ); if ( function_exists( 'fsync' ) ) { @fsync( $index ); }
				flock( $index, LOCK_UN );
			} finally { fclose( $index ); }
			flock( $handle, LOCK_UN );
		} finally { fclose( $handle ); }
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/** Read a bounded forward slice. @return array{entries:array<int,array<string,mixed>>,offset:int,done:bool} */
	public function read_slice( $offset, $limit = 250 ) {
		if ( ! is_readable( $this->path ) ) { return array( 'entries' => array(), 'offset' => 0, 'done' => true ); }
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/streaming primitives; resuming the journal read at a durable byte offset requires a direct file handle.
		$handle = fopen( $this->path, 'rb' );
		if ( ! $handle ) { throw new \RuntimeException( esc_html__( 'Could not read the restore journal.', 'nobash-backup' ) ); }
		if ( 0 !== fseek( $handle, max( 0, (int) $offset ) ) ) { fclose( $handle ); throw new \RuntimeException( esc_html__( 'Could not resume the restore journal.', 'nobash-backup' ) ); }
		$entries = array();
		while ( count( $entries ) < max( 1, min( 1000, (int) $limit ) ) && false !== ( $line = fgets( $handle ) ) ) { $row = json_decode( $line, true ); if ( is_array( $row ) ) { $entries[] = $row; } }
		$new_offset = (int) ftell( $handle ); $done = feof( $handle ); fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array( 'entries' => $entries, 'offset' => $new_offset, 'done' => $done );
	}

	/** Read newest actions first so dependent filesystem mutations are undone correctly. @param int|null $position Index byte position. @return array{entries:array<int,array<string,mixed>>,position:int,done:bool} */
	public function read_reverse_slice( $position = null, $limit = 250 ) {
		if ( ! is_readable( $this->path ) || ! is_readable( $this->index_path ) ) { return array( 'entries' => array(), 'position' => 0, 'done' => true ); }
		$index_size = (int) filesize( $this->index_path );
		$position = null === $position ? $index_size : max( 0, min( $index_size, (int) $position ) );
		$position -= $position % self::INDEX_RECORD_BYTES;
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/streaming primitives; reading the journal newest-first requires seeking to arbitrary offsets via its fixed-width index.
		$data = fopen( $this->path, 'rb' ); $index = fopen( $this->index_path, 'rb' );
		if ( ! $data || ! $index ) { if ( $data ) fclose( $data ); if ( $index ) fclose( $index ); throw new \RuntimeException( esc_html__( 'Could not read the restore rollback journal.', 'nobash-backup' ) ); }
		$entries = array(); $maximum = max( 1, min( 1000, (int) $limit ) );
		try {
			while ( $position >= self::INDEX_RECORD_BYTES && count( $entries ) < $maximum ) {
				$position -= self::INDEX_RECORD_BYTES;
				if ( 0 !== fseek( $index, $position ) ) { throw new \RuntimeException( esc_html__( 'Could not seek the restore journal index.', 'nobash-backup' ) ); }
				$record = fread( $index, self::INDEX_RECORD_BYTES );
				if ( false === $record || self::INDEX_RECORD_BYTES !== strlen( $record ) ) { throw new \RuntimeException( esc_html__( 'The restore journal index is incomplete.', 'nobash-backup' ) ); }
				$offset = (int) trim( $record );
				if ( 0 !== fseek( $data, $offset ) ) { throw new \RuntimeException( esc_html__( 'Could not seek a restore journal action.', 'nobash-backup' ) ); }
				$row = json_decode( (string) fgets( $data ), true );
				if ( is_array( $row ) ) { $entries[] = $row; }
			}
		} finally { fclose( $data ); fclose( $index ); }
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array( 'entries' => $entries, 'position' => $position, 'done' => $position < self::INDEX_RECORD_BYTES );
	}
}

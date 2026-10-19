<?php
/** Durable archive record-chain ledger. @package Nobash\Backup */
namespace Nobash\Backup\Archive;

use Nobash\Backup\Filesystem\ChunkFingerprint;

defined( 'ABSPATH' ) || exit;

/**
 * Keep the committed archive integrity state beside an in-progress archive.
 *
 * The ledger stores only the latest committed state. A state carried in the
 * durable job cursor can rewind the ledger in O(1).
 */
final class IntegrityLedger {
	/** @var string */ private $archive;
	/** @var string */ private $path;
	/** @var array<string,mixed>|null */ private $cached_state = null;

	public function __construct( $archive ) {
		$this->archive = (string) $archive;
		$this->path = $this->archive . '.integrity';
	}

	/** @return array{offset:int,chain:string,records:int} */
	public function initialize( $offset ) {
		$state = array( 'offset' => (int) $offset, 'chain' => ChunkFingerprint::seed( 'archive-records' ), 'records' => 0 );
		$this->write_state( $state );
		$this->cached_state = $state;
		return $state;
	}

	/**
	 * Rewind the ledger to the same committed archive offset.
	 *
	 * @param int $target_offset Archive byte checkpoint from the durable job cursor.
	 * @param array<string,mixed> $known_state Integrity state stored with that cursor.
	 * @return array{offset:int,chain:string,records:int}
	 */
	public function prepare( $target_offset, array $known_state = array() ) {
		$target_offset = (int) $target_offset;
		if ( $this->valid_state( $known_state ) && (int) $known_state['offset'] === $target_offset ) {
			$state = $this->normalize_state( $known_state );
			$this->cached_state = $state;
			return $state;
		}
		if ( ! is_readable( $this->path ) ) {
			if ( $target_offset === strlen( NativeArchiveWriter::MAGIC ) ) {
				return $this->initialize( $target_offset );
			}
			throw new \RuntimeException( esc_html__( 'The archive integrity checkpoint is missing. Restart this unfinished backup safely.', 'nobash-backup' ) );
		}

		$match = $this->read_state();
		if ( ! is_array( $match ) || (int) $match['offset'] !== $target_offset ) {
			throw new \RuntimeException( esc_html__( 'The archive and its integrity checkpoint are out of sync.', 'nobash-backup' ) );
		}
		$match = $this->normalize_state( $match );
		$this->cached_state = $match;
		return $match;
	}

	/** Advance in memory. Durability is committed once per resumable slice. @return array{offset:int,chain:string,records:int} */
	public function append( $offset, $record_digest ) {
		$current = is_array( $this->cached_state ) ? $this->cached_state : $this->current();
		$next = array(
			'offset' => (int) $offset,
			'chain' => ChunkFingerprint::advance( (string) $current['chain'], (string) $record_digest, (int) $current['offset'], max( 0, (int) $offset - (int) $current['offset'] ), (int) $current['records'] ),
			'records' => (int) $current['records'] + 1,
		);
		$this->cached_state = $next;
		return $next;
	}

	/** Durably commit the latest in-memory state once per resumable slice. @return array{offset:int,chain:string,records:int} */
	public function sync() {
		$state = $this->current();
		$this->write_state( $state );
		return $state;
	}

	/** @return array{offset:int,chain:string,records:int} */
	public function current() {
		if ( is_array( $this->cached_state ) ) { return $this->normalize_state( $this->cached_state ); }
		$last = $this->read_state();
		if ( ! is_array( $last ) ) { throw new \RuntimeException( esc_html__( 'The archive integrity checkpoint is invalid.', 'nobash-backup' ) ); }
		$this->cached_state = $this->normalize_state( $last );
		return $this->cached_state;
	}

	/** @return array<string,mixed>|null */
	private function read_state() {
		$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
		$raw = $wp_filesystem->get_contents( $this->path );
		if ( false === $raw ) { throw new \RuntimeException( esc_html__( 'Could not read the archive integrity checkpoint.', 'nobash-backup' ) ); }
		$state = json_decode( trim( $raw ), true );
		return $this->valid_state( $state ) ? $state : null;
	}

	/** @return bool */
	private function valid_state( $state ) {
		return is_array( $state ) && isset( $state['offset'], $state['chain'], $state['records'] ) && (int) $state['offset'] >= strlen( NativeArchiveWriter::MAGIC ) && (int) $state['records'] >= 0 && preg_match( '/^[a-f0-9]{64}$/', (string) $state['chain'] );
	}

	/** @return array{offset:int,chain:string,records:int} */
	private function normalize_state( array $state ) {
		return array( 'offset' => (int) $state['offset'], 'chain' => (string) $state['chain'], 'records' => (int) $state['records'] );
	}

	/** @return void */
	private function write_state( array $state ) {
		$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
		$tmp = $this->path . '.tmp';
		if ( false === $wp_filesystem->put_contents( $tmp, wp_json_encode( $this->normalize_state( $state ) ) . "\n" ) ) {
			throw new \RuntimeException( esc_html__( 'Could not initialize the archive integrity checkpoint.', 'nobash-backup' ) );
		}
		if ( ! $wp_filesystem->move( $tmp, $this->path, true ) ) { $wp_filesystem->delete( $tmp, false, 'f' ); throw new \RuntimeException( esc_html__( 'Could not publish the archive integrity checkpoint.', 'nobash-backup' ) ); }
	}
}

<?php
/**
 * Adaptive bounded-work controller.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Filesystem;

use Nobash\Backup\Compat\HostingEnvironment;

defined( 'ABSPATH' ) || exit;

/** Select conservative file batch sizes and learn from completed slices. */
class AdaptiveWorkController {
	const MIN_FILES = 50;
	const MAX_FILES = 5000;
	const MIN_BYTES = 33554432;
	const MAX_BYTES = 1073741824;
	const MIN_CHUNK_BYTES = 4194304;
	const MAX_CHUNK_BYTES = 16777216;
	const MIN_SCAN_ENTRIES = 1000;
	const MAX_SCAN_ENTRIES = 30000;
	const MIN_SCAN_BYTES = 67108864;
	const MAX_SCAN_BYTES = 2147483647;

	/** @param array<string,mixed> $cursor Cursor. @return array<string,int> */
	public function limits( array $cursor ) {
		$files = isset( $cursor['adaptive_files'] ) ? (int) $cursor['adaptive_files'] : 1000;
		/* `bytes` is work completed over an entire request, not a resident memory
		 * buffer. Keep it large enough for fast disks while bounding each in-memory
		 * archive chunk independently below. */
		$bytes = isset( $cursor['adaptive_bytes'] ) ? (int) $cursor['adaptive_bytes'] : 134217728;
		$memory_limit = $this->memory_limit_bytes();
		$memory_used  = function_exists( 'memory_get_usage' ) ? (int) memory_get_usage( true ) : 0;
		$headroom     = $memory_limit > 0 ? max( 0, $memory_limit - $memory_used ) : 0;
		$chunk_bytes  = isset( $cursor['adaptive_chunk_bytes'] ) ? (int) $cursor['adaptive_chunk_bytes'] : self::MAX_CHUNK_BYTES;
		if ( ( $memory_limit > 0 && $memory_limit <= 67108864 ) || ( $headroom > 0 && $headroom < 50331648 ) ) {
			$files = min( $files, 350 );
			$chunk_bytes = self::MIN_CHUNK_BYTES;
		} elseif ( ( $memory_limit > 0 && $memory_limit <= 134217728 ) || ( $headroom > 0 && $headroom < 100663296 ) ) {
			$files = min( $files, 750 );
			$chunk_bytes = 8388608;
		}
		if ( $headroom > 0 && $headroom < 33554432 ) {
			$files = min( $files, 150 );
			$chunk_bytes = self::MIN_CHUNK_BYTES;
		}
		// Provider names are advisory; actual resource capabilities determine the
		// engine caps so generic cPanel/Plesk/shared hosts receive the same safety.
		$bytes = min( $bytes, HostingEnvironment::max_file_work_bytes() );
		$chunk_bytes = min( $chunk_bytes, HostingEnvironment::max_io_chunk_bytes() );
		$scan_entries = isset( $cursor['adaptive_scan_entries'] ) ? (int) $cursor['adaptive_scan_entries'] : 10000;
		$scan_bytes   = isset( $cursor['adaptive_scan_bytes'] ) ? (int) $cursor['adaptive_scan_bytes'] : 1073741824;
		if ( $memory_limit > 0 && $memory_limit <= 134217728 ) {
			$scan_entries = min( $scan_entries, 2500 );
			$scan_bytes   = min( $scan_bytes, 268435456 );
		}
		if ( $headroom > 0 && $headroom < 67108864 ) {
			$scan_entries = min( $scan_entries, 1500 );
			$scan_bytes   = min( $scan_bytes, 134217728 );
		}
		return array(
			'files'        => max( self::MIN_FILES, min( self::MAX_FILES, $files ) ),
			'bytes'        => max( self::MIN_BYTES, min( self::MAX_BYTES, $bytes ) ),
			'chunk_bytes'  => max( self::MIN_CHUNK_BYTES, min( self::MAX_CHUNK_BYTES, $chunk_bytes ) ),
			'scan_entries' => max( self::MIN_SCAN_ENTRIES, min( self::MAX_SCAN_ENTRIES, $scan_entries ) ),
			'scan_bytes'   => max( self::MIN_SCAN_BYTES, min( self::MAX_SCAN_BYTES, $scan_bytes ) ),
		);
	}

	/**
	 * Persist learned values after a slice.
	 *
	 * @param array<string,mixed> $cursor Cursor.
	 * @param float $duration Seconds.
	 * @param bool $pressure High-memory/timeout pressure.
	 * @return array<string,mixed>
	 */
	public function learn( array $cursor, $duration, $pressure = false ) {
		$limits = $this->limits( $cursor );
		/* Reaching the request deadline is expected: the scanner/packer already
		 * stop at a safe boundary. Do not mistake a productive full-length slice
		 * for resource pressure, otherwise a 20-second worker would shrink itself
		 * toward the minimum throughput on every request. Only actual memory
		 * pressure reduces work; short capped slices can grow opportunistically. */
		if ( $pressure ) {
			$limits['files'] = max( self::MIN_FILES, (int) floor( $limits['files'] * 0.7 ) );
			$limits['bytes'] = max( self::MIN_BYTES, (int) floor( $limits['bytes'] * 0.7 ) );
		} elseif ( $duration < 3.0 ) {
			$limits['files'] = min( self::MAX_FILES, max( self::MIN_FILES, (int) ceil( $limits['files'] * 1.5 ) ) );
			$limits['bytes'] = min( self::MAX_BYTES, max( self::MIN_BYTES, (int) ceil( $limits['bytes'] * 1.35 ) ) );
		}
		$cursor['adaptive_files']        = $limits['files'];
		$cursor['adaptive_bytes']        = $limits['bytes'];
		$cursor['adaptive_chunk_bytes']  = $pressure
			? max( self::MIN_CHUNK_BYTES, (int) floor( $limits['chunk_bytes'] * 0.7 ) )
			: $limits['chunk_bytes'];
		$cursor['adaptive_scan_entries'] = $pressure
			? max( self::MIN_SCAN_ENTRIES, (int) floor( $limits['scan_entries'] * 0.7 ) )
			: ( $duration < 3.0 ? min( self::MAX_SCAN_ENTRIES, (int) ceil( $limits['scan_entries'] * 1.5 ) ) : $limits['scan_entries'] );
		$cursor['adaptive_scan_bytes']   = $pressure
			? max( self::MIN_SCAN_BYTES, (int) floor( $limits['scan_bytes'] * 0.7 ) )
			: ( $duration < 3.0 ? min( self::MAX_SCAN_BYTES, (int) ceil( $limits['scan_bytes'] * 1.25 ) ) : $limits['scan_bytes'] );
		return $cursor;
	}

	/** @return int */
	private function memory_limit_bytes() {
		$value = ini_get( 'memory_limit' );
		if ( false === $value || '-1' === $value ) {
			return 0;
		}
		$value = trim( (string) $value );
		$unit  = strtolower( substr( $value, -1 ) );
		$num   = (int) $value;
		if ( 'g' === $unit ) { return $num * 1073741824; }
		if ( 'm' === $unit ) { return $num * 1048576; }
		if ( 'k' === $unit ) { return $num * 1024; }
		return $num;
	}
}

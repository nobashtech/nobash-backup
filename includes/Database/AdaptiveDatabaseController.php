<?php
/** Adaptive database export work sizing. @package Nobash\Backup */
namespace Nobash\Backup\Database;

use Nobash\Backup\Compat\HostingEnvironment;

defined( 'ABSPATH' ) || exit;
/** Keep large-table exports inside memory and request-time budgets. */
final class AdaptiveDatabaseController {
	const MIN_ROWS = 25;
	const MAX_ROWS = 5000;
	const MIN_STATEMENT_BYTES = 262144;
	const MAX_STATEMENT_BYTES = 8388608;

	/** @return array{rows:int,statement_bytes:int} */
	public function limits( array $cursor ) {
		$memory = $this->memory_limit_bytes();
		$used = function_exists( 'memory_get_usage' ) ? (int) memory_get_usage( true ) : 0;
		$headroom = $memory > 0 ? max( 0, $memory - $used ) : 0;

		if ( isset( $cursor['adaptive_db_rows'] ) ) { $rows = (int) $cursor['adaptive_db_rows']; }
		elseif ( $memory > 0 && $memory <= 67108864 ) { $rows = 100; }
		elseif ( $memory > 0 && $memory <= 134217728 ) { $rows = 250; }
		elseif ( $memory > 0 && $memory <= 268435456 ) { $rows = 750; }
		else { $rows = 1500; }

		if ( isset( $cursor['adaptive_db_statement_bytes'] ) ) { $bytes = (int) $cursor['adaptive_db_statement_bytes']; }
		elseif ( $memory > 0 && $memory <= 67108864 ) { $bytes = 524288; }
		elseif ( $memory > 0 && $memory <= 134217728 ) { $bytes = 1048576; }
		elseif ( $memory > 0 && $memory <= 268435456 ) { $bytes = 2097152; }
		else { $bytes = 4194304; }

		if ( $headroom > 0 ) {
			/* SQL rows are fetched before serialization. Keep a deliberately large
			 * reserve for wpdb, binary/blob expansion and the archive chunk itself. */
			$bytes = min( $bytes, max( self::MIN_STATEMENT_BYTES, (int) floor( $headroom * 0.08 ) ) );
			if ( $headroom < 25165824 ) { $rows = min( $rows, 75 ); $bytes = min( $bytes, 524288 ); }
			elseif ( $headroom < 50331648 ) { $rows = min( $rows, 250 ); $bytes = min( $bytes, 1048576 ); }
		}
		$rows = min( $rows, HostingEnvironment::max_db_rows() );
		$bytes = min( $bytes, HostingEnvironment::max_db_statement_bytes() );
		return array(
			'rows' => max( self::MIN_ROWS, min( self::MAX_ROWS, $rows ) ),
			'statement_bytes' => max( self::MIN_STATEMENT_BYTES, min( self::MAX_STATEMENT_BYTES, $bytes ) ),
		);
	}

	/** @return array<string,mixed> */
	public function learn( array $cursor, $duration, $rows, $bytes, $pressure = false ) {
		$limits = $this->limits( $cursor );
		$duration = max( 0.001, (float) $duration );
		/* A productive DB page can legitimately take several seconds on shared
		 * hosting. Do not self-throttle merely because the query was useful and
		 * slow; reserve time-based pressure for a page that consumes a large part
		 * of a normal request slice. */
		$pressure = (bool) $pressure || $duration > 8.0;
		if ( $pressure ) {
			$limits['rows'] = max( self::MIN_ROWS, (int) floor( $limits['rows'] * 0.65 ) );
			$limits['statement_bytes'] = max( self::MIN_STATEMENT_BYTES, (int) floor( $limits['statement_bytes'] * 0.75 ) );
		} elseif ( $duration < 0.8 && (int) $rows >= $limits['rows'] ) {
			$limits['rows'] = min( self::MAX_ROWS, (int) ceil( $limits['rows'] * 1.6 ) );
			$limits['statement_bytes'] = min( self::MAX_STATEMENT_BYTES, (int) ceil( $limits['statement_bytes'] * 1.35 ) );
		} elseif ( $duration < 1.8 && (int) $rows >= $limits['rows'] ) {
			$limits['rows'] = min( self::MAX_ROWS, (int) ceil( $limits['rows'] * 1.3 ) );
			if ( (int) $bytes < $limits['statement_bytes'] ) {
				$limits['statement_bytes'] = min( self::MAX_STATEMENT_BYTES, (int) ceil( $limits['statement_bytes'] * 1.2 ) );
			}
		}
		$cursor['adaptive_db_rows'] = $limits['rows'];
		$cursor['adaptive_db_statement_bytes'] = $limits['statement_bytes'];
		return $cursor;
	}

	/** @return int */
	private function memory_limit_bytes() {
		$value = trim( (string) ini_get( 'memory_limit' ) );
		if ( '' === $value || '-1' === $value ) { return 0; }
		$unit = strtolower( substr( $value, -1 ) );
		$number = (int) $value;
		if ( 'g' === $unit ) { return $number * 1073741824; }
		if ( 'm' === $unit ) { return $number * 1048576; }
		if ( 'k' === $unit ) { return $number * 1024; }
		return $number;
	}
}

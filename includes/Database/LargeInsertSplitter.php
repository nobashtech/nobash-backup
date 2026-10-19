<?php
/** Safely split oversized multi-row INSERT statements. @package Nobash\Backup */
namespace Nobash\Backup\Database;

defined( 'ABSPATH' ) || exit;

/** Split mysqldump-style VALUES lists without entering quoted values. */
final class LargeInsertSplitter {
	/** @return array<int,string> */
	public function split( $sql, $max_bytes = 4194304 ) {
		$sql = trim( (string) $sql );
		$max_bytes = max( 262144, (int) $max_bytes );
		if ( strlen( $sql ) <= $max_bytes ) { return array( $sql ); }
		if ( ! preg_match( '/^\s*((?:INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+.+?\s+VALUES\s*)/is', $sql, $match ) ) { return array( $sql ); }
		$prefix = (string) $match[1];
		$start = strlen( $match[0] );
		$body = rtrim( substr( $sql, $start ) );
		if ( ';' === substr( $body, -1 ) ) { $body = rtrim( substr( $body, 0, -1 ) ); }
		$tuples = $this->tuples( $body );
		if ( count( $tuples ) < 2 ) { return array( $sql ); }
		$out = array(); $current = '';
		foreach ( $tuples as $tuple ) {
			$candidate = '' === $current ? $tuple : $current . ',' . $tuple;
			if ( '' !== $current && strlen( $prefix ) + strlen( $candidate ) + 1 > $max_bytes ) {
				$out[] = $prefix . $current . ';'; $current = $tuple;
			} else { $current = $candidate; }
		}
		if ( '' !== $current ) { $out[] = $prefix . $current . ';'; }
		return empty( $out ) ? array( $sql ) : $out;
	}

	/** @return array<int,string> */
	private function tuples( $body ) {
		$out = array(); $start = null; $depth = 0; $quote = ''; $escaped = false; $length = strlen( $body );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $body[ $i ];
			if ( '' !== $quote ) {
				if ( $escaped ) { $escaped = false; continue; }
				if ( '\\' === $char ) { $escaped = true; continue; }
				if ( $char === $quote ) {
					if ( $i + 1 < $length && $body[ $i + 1 ] === $quote ) { ++$i; continue; }
					$quote = '';
				}
				continue;
			}
			if ( "'" === $char || '"' === $char || '`' === $char ) { $quote = $char; continue; }
			if ( '(' === $char ) { if ( 0 === $depth ) { $start = $i; } ++$depth; continue; }
			if ( ')' === $char ) {
				--$depth;
				if ( 0 === $depth && null !== $start ) { $out[] = trim( substr( $body, $start, $i - $start + 1 ) ); $start = null; }
				if ( $depth < 0 ) { return array(); }
			}
		}
		return 0 === $depth ? $out : array();
	}
}

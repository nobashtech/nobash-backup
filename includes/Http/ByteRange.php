<?php
/**
 * RFC 7233 single-byte-range parsing.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Http;

defined( 'ABSPATH' ) || exit;

/** Parse one HTTP byte range without integer overflow. */
final class ByteRange {
	/**
	 * Parse a single Range header.
	 *
	 * Multipart ranges are intentionally rejected because the download endpoint
	 * emits one continuous stream.
	 *
	 * @param string $header Range header value.
	 * @param int    $size   Resource size.
	 * @return array{status:int,start:int,end:int,length:int}
	 * @throws \OutOfRangeException For malformed or unsatisfiable ranges.
	 */
	public static function parse( $header, $size ) {
		$size   = max( 0, (int) $size );
		$header = trim( (string) $header );
		if ( '' === $header ) {
			return array(
				'status' => 200,
				'start'  => 0,
				'end'    => max( 0, $size - 1 ),
				'length' => $size,
			);
		}

		if ( false !== strpos( $header, ',' ) || ! preg_match( '/^bytes=(\d*)-(\d*)$/D', $header, $matches ) || ( '' === $matches[1] && '' === $matches[2] ) ) {
			throw new \OutOfRangeException( 'Invalid byte range.' );
		}
		if ( $size < 1 ) {
			throw new \OutOfRangeException( 'The empty resource has no satisfiable byte range.' );
		}

		if ( '' === $matches[1] ) {
			$suffix = self::decimal( $matches[2] );
			if ( $suffix < 1 ) {
				throw new \OutOfRangeException( 'Invalid suffix byte range.' );
			}
			$start = max( 0, $size - $suffix );
			$end   = $size - 1;
		} else {
			$start = self::decimal( $matches[1] );
			if ( $start >= $size ) {
				throw new \OutOfRangeException( 'Byte range starts beyond the resource.' );
			}
			$end = '' === $matches[2] ? $size - 1 : min( $size - 1, self::decimal( $matches[2] ) );
			if ( $end < $start ) {
				throw new \OutOfRangeException( 'Byte range end precedes its start.' );
			}
		}

		return array(
			'status' => 206,
			'start'  => $start,
			'end'    => $end,
			'length' => $end - $start + 1,
		);
	}

	/** Convert an unsigned decimal string, saturating instead of overflowing. */
	private static function decimal( $value ) {
		$value = ltrim( (string) $value, '0' );
		if ( '' === $value ) {
			return 0;
		}
		$maximum = (string) PHP_INT_MAX;
		if ( strlen( $value ) > strlen( $maximum ) || ( strlen( $value ) === strlen( $maximum ) && strcmp( $value, $maximum ) > 0 ) ) {
			return PHP_INT_MAX;
		}
		return (int) $value;
	}
}

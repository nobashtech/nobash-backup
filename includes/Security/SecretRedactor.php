<?php
/** Security-safe recursive context redaction. @package Nobash\Backup */
namespace Nobash\Backup\Security;
defined( 'ABSPATH' ) || exit;
class SecretRedactor {
	/** @return mixed */
	public static function redact( $value, $depth = 0 ) {
		if ( $depth > 8 ) return '[depth-limit]';
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( array_slice( $value, 0, 100, true ) as $key => $item ) {
				$name = strtolower( (string) $key );
				if ( preg_match( '/(?:pass|secret|token|credential|authorization|cookie|salt|private[_-]?key|api[_-]?key|access[_-]?key|client[_-]?secret)/i', $name ) ) {
					$out[ $key ] = '[redacted]';
				} else {
					$out[ $key ] = self::redact( $item, $depth + 1 );
				}
			}
			return $out;
		}
		if ( is_object( $value ) ) return '[object]';
		if ( is_resource( $value ) ) return '[resource]';
		if ( is_string( $value ) ) {
			$value = preg_replace( '#(?:[A-Za-z]:[\\\\/]|/)(?:[^\s]+[\\\\/])+[^\s]+#', '[path-redacted]', $value );
			$value = preg_replace( '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/-]+=*/i', '$1 [redacted]', $value );
			return mb_substr( (string) $value, 0, 2000 );
		}
		return is_scalar( $value ) || null === $value ? $value : '[unsupported]';
	}
}

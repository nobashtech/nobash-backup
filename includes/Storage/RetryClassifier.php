<?php
namespace Nobash\Backup\Storage;
defined( 'ABSPATH' ) || exit;
final class RetryClassifier {
	public static function classify( $error ) {
		$code = is_wp_error( $error ) ? (string) $error->get_error_code() : ( $error instanceof \Throwable ? (string) $error->getCode() : '' );
		$msg  = strtolower( is_wp_error( $error ) ? $error->get_error_message() : ( $error instanceof \Throwable ? $error->getMessage() : (string) $error ) );
		if ( preg_match( '/auth|credential|signature|forbidden|unauthor|invalid token|access denied/', $msg ) ) return 'permanent_auth';
		if ( preg_match( '/not found|missing object|404/', $msg ) ) return 'permanent_not_found';
		if ( preg_match( '/quota|capacity|disk full|insufficient storage/', $msg ) ) return 'permanent_capacity';
		if ( preg_match( '/429|rate.?limit|throttl|too many requests/', $msg ) ) return 'retry_rate_limit';
		if ( preg_match( '/timeout|timed out|temporar|connection reset|503|502|504|network|dns/', $msg ) ) return 'retry_transient';
		if ( in_array( $code, array( '429', '500', '502', '503', '504' ), true ) ) return 'retry_transient';
		return 'permanent_unknown';
	}
	public static function retryable( $error ) { return 0 === strpos( self::classify( $error ), 'retry_' ); }
}

<?php
/**
 * Safe error serialization.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Convert internal failures to bounded, redacted job errors.
 */
final class JobError {

	/**
	 * Convert an exception to safe storage fields.
	 *
	 * @param \Throwable $error Error.
	 * @return array{code:string,message:string}
	 */
	public static function from_exception( \Throwable $error ) {
		$short_name = preg_replace( '/(?<!^)[A-Z]/', '_$0', ( new \ReflectionClass( $error ) )->getShortName() );
		$code       = strtolower( is_string( $short_name ) ? $short_name : 'job_error' );
		$clean_code = preg_replace( '/[^a-z0-9_\-]/', '', $code );
		$code       = is_string( $clean_code ) ? $clean_code : 'job_error';
		return array(
			'code'    => substr( $code ? $code : 'job_error', 0, 64 ),
			'message' => self::sanitize_message( $error->getMessage() ),
		);
	}

	/** Return only redacted restore failure details, including previously saved jobs.
	 * @param array<string,mixed> $job Internal job.
	 * @return array<string,string>
	 */
	public static function restore_diagnostics( array $job ) {
		if ( ! in_array( (string) ( $job['type'] ?? '' ), array( 'restore', 'chain_restore' ), true ) ) { return array(); }
		$cursor = isset( $job['cursor'] ) && is_array( $job['cursor'] ) ? $job['cursor'] : array();
		$message = isset( $cursor['failure_message'] ) && is_scalar( $cursor['failure_message'] ) ? self::sanitize_message( (string) $cursor['failure_message'] ) : '';
		if ( '' === $message ) { return array(); }
		return array(
			'failed_phase' => sanitize_key( (string) ( $cursor['failure_phase'] ?? '' ) ),
			'original_error' => $message,
		);
	}

	/**
	 * Redact secrets and local paths from a message.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function sanitize_message( $message ) {
		$message = wp_strip_all_tags( (string) $message );
		$roots = array();
		try { $roots[] = \Nobash\Backup\Compat\Paths::wordpress_root(); } catch ( \Throwable $e ) {}
		try { $roots[] = \Nobash\Backup\Compat\Paths::uploads_root(); } catch ( \Throwable $e ) {}
		$roots[] = \Nobash\Backup\Compat\Paths::plugins_root();
		$roots[] = get_theme_root();
		foreach ( array_filter( $roots ) as $root ) {
			$message = str_replace( array( $root, wp_normalize_path( $root ) ), '[site-path]/', $message );
		}
		$redacted    = preg_replace( '/\b(password|passwd|secret|token|api[_-]?key)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $message );
		$message     = is_string( $redacted ) ? $redacted : $message;
		$single_line = preg_replace( '/[\r\n\t]+/', ' ', $message );
		$message     = is_string( $single_line ) ? $single_line : $message;
		$collapsed   = preg_replace( '/\s{2,}/', ' ', $message );
		$message     = trim( is_string( $collapsed ) ? $collapsed : $message );
		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 500 ) : substr( $message, 0, 500 );
	}
}

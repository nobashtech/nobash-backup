<?php
/**
 * Retry classification and bounded backoff.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Classify failures and calculate bounded retry delays.
 */
final class RetryPolicy {

	const DEFAULT_MAX_ATTEMPTS = 5;
	const BASE_DELAY           = 5;
	const MAX_DELAY            = 300;

	/**
	 * Whether an exception is safe to retry.
	 *
	 * @param \Throwable $error Error.
	 * @return bool
	 */
	public function is_retryable( \Throwable $error ) {
		if ( $error instanceof PermanentJobException || $error instanceof \InvalidArgumentException || $error instanceof \DomainException ) {
			return false;
		}
		if ( $error instanceof RetryableJobException ) {
			return true;
		}

		$message = strtolower( $error->getMessage() );
		$permanent_patterns = array(
			'unknown backup phase', 'unknown restore phase',
			'unknown job type', 'invalid restore destination', 'path escaped', 'archive not found',
			'unsupported', 'invalid archive', 'incorrect password',
			'checksum mismatch', 'integrity checkpoint is missing', 'integrity checkpoint is invalid',
			'malformed', 'unsafe native archive path', 'prohibited administrative sql',
			'permission denied', 'read-only file system', 'no space left on device', 'disk quota exceeded',
		);
		foreach ( $permanent_patterns as $pattern ) {
			if ( false !== strpos( $message, $pattern ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Calculate exponential backoff with bounded jitter.
	 *
	 * @param int      $attempt     Failed attempt number (1-based).
	 * @param int|null $jitter_seed Optional deterministic seed for tests.
	 * @return int Seconds.
	 */
	public function delay( $attempt, $jitter_seed = null ) {
		$attempt = max( 1, (int) $attempt );
		$base    = min( self::MAX_DELAY, self::BASE_DELAY * ( 2 ** min( 6, $attempt - 1 ) ) );
		$window  = max( 1, (int) floor( $base * 0.25 ) );
		$jitter  = null === $jitter_seed ? wp_rand( 0, $window ) : abs( (int) $jitter_seed ) % ( $window + 1 );
		return min( self::MAX_DELAY, $base + $jitter );
	}
}

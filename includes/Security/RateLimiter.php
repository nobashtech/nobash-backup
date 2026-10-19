<?php
/**
 * Simple per-user rate limiter (transients).
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Class RateLimiter
 */
class RateLimiter {

	/**
	 * Consume one token from a bucket.
	 *
	 * @param string $action Action key.
	 * @param int    $limit  Max hits.
	 * @param int    $window   Window seconds.
	 * @param string $identity Optional privacy-safe identity for public endpoints.
	 * @return true|\WP_Error
	 */
	public static function attempt( $action, $limit, $window, $identity = '' ) {
		$user_id = get_current_user_id();
		$scope   = '' !== (string) $identity ? substr( hash( 'sha256', (string) $identity ), 0, 20 ) : (string) $user_id;
		$key     = 'nobash_rl_' . sanitize_key( $action ) . '_' . $scope;
		$bucket  = get_transient( $key );
		if ( ! is_array( $bucket ) ) {
			$bucket = array(
				'count' => 0,
				'start' => time(),
			);
		}

		if ( ( time() - (int) $bucket['start'] ) > $window ) {
			$bucket = array(
				'count' => 0,
				'start' => time(),
			);
		}

		if ( (int) $bucket['count'] >= $limit ) {
			$retry = max( 1, $window - ( time() - (int) $bucket['start'] ) );
			return new \WP_Error(
				'nobash_rate_limited',
				__( 'Too many requests. Please wait a moment and try again.', 'nobash-backup' ),
				array(
					'status'       => 429,
					'retry_after'  => $retry,
				)
			);
		}

		$bucket['count'] = (int) $bucket['count'] + 1;
		set_transient( $key, $bucket, $window );
		return true;
	}
}

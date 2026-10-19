<?php
/**
 * Scoped signed transfer tokens.
 *
 * @package Nobash\Backup
 */
namespace Nobash\Backup\Security;

defined( 'ABSPATH' ) || exit;

class TransferToken {
	const KEY_OPTION = 'nobash_backup_transfer_token_key';

	/** @return string */
	public static function issue( $transfer_id, $scope, $ttl = 1800 ) {
		$now = time();
		$payload = array(
			'tid' => sanitize_key( (string) $transfer_id ),
			'scp' => sanitize_key( (string) $scope ),
			'iat' => $now,
			'nbf' => $now - 5,
			'exp' => $now + max( 60, min( DAY_IN_SECONDS, (int) $ttl ) ),
			'bid' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
			'jti' => wp_generate_uuid4(),
		);
		$body = self::b64( wp_json_encode( $payload ) );
		$sig  = self::b64( hash_hmac( 'sha256', $body, self::key(), true ) );
		return $body . '.' . $sig;
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function verify( $token, $transfer_id, $scope, $consume = false ) {
		$parts = explode( '.', (string) $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return new \WP_Error( 'nobash_transfer_token', __( 'Transfer authorization is missing or invalid.', 'nobash-backup' ), array( 'status' => 403 ) );
		}
		try {
			$expected = self::b64( hash_hmac( 'sha256', $parts[0], self::key(), true ) );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'nobash_transfer_token', __( 'Transfer authorization could not be verified safely.', 'nobash-backup' ), array( 'status' => 500 ) );
		}
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return new \WP_Error( 'nobash_transfer_token', __( 'Transfer authorization signature is invalid.', 'nobash-backup' ), array( 'status' => 403 ) );
		}
		$decoded = self::unb64( $parts[0] );
		$data    = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
		$now     = time();
		if (
			! is_array( $data )
			|| empty( $data['exp'] )
			|| empty( $data['iat'] )
			|| (int) $data['exp'] < $now
			|| (int) ( $data['nbf'] ?? 0 ) > $now
			|| (int) $data['exp'] - (int) $data['iat'] > DAY_IN_SECONDS + 10
		) {
			return new \WP_Error( 'nobash_transfer_token', __( 'Transfer authorization has expired.', 'nobash-backup' ), array( 'status' => 403 ) );
		}
		$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		if (
			(string) ( $data['tid'] ?? '' ) !== sanitize_key( (string) $transfer_id )
			|| (string) ( $data['scp'] ?? '' ) !== sanitize_key( (string) $scope )
			|| (int) ( $data['bid'] ?? 0 ) !== $blog
		) {
			return new \WP_Error( 'nobash_transfer_token', __( 'Transfer authorization does not match this operation.', 'nobash-backup' ), array( 'status' => 403 ) );
		}
		if ( $consume ) {
			$jti = sanitize_key( (string) ( $data['jti'] ?? '' ) );
			if ( '' === $jti ) {
				return new \WP_Error( 'nobash_transfer_token', __( 'Transfer authorization identifier is invalid.', 'nobash-backup' ), array( 'status' => 403 ) );
			}
			$key = 'nobash_tjti_' . hash( 'sha256', $jti );
			if ( get_transient( $key ) ) {
				return new \WP_Error( 'nobash_transfer_token', __( 'Transfer authorization has already been used.', 'nobash-backup' ), array( 'status' => 409 ) );
			}
			set_transient( $key, 1, max( 60, (int) $data['exp'] - $now ) );
		}
		return $data;
	}

	/** Return a plugin-owned 256-bit signing key, independent of WordPress auth salts. */
	private static function key() {
		$encoded = get_option( self::KEY_OPTION, '' );
		if ( is_string( $encoded ) && '' !== $encoded ) {
			$key = base64_decode( $encoded, true );
			if ( is_string( $key ) && 32 === strlen( $key ) ) {
				return $key;
			}
			throw new \RuntimeException( esc_html__( 'The Nobash Backup transfer signing key is invalid.', 'nobash-backup' ) );
		}
		$key = random_bytes( 32 );
		if ( add_option( self::KEY_OPTION, base64_encode( $key ), '', 'no' ) ) {
			return $key;
		}
		$encoded  = get_option( self::KEY_OPTION, '' );
		$existing = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
		if ( ! is_string( $existing ) || 32 !== strlen( $existing ) ) {
			throw new \RuntimeException( esc_html__( 'Nobash Backup could not initialize transfer signing.', 'nobash-backup' ) );
		}
		return $existing;
	}

	/** @return string */
	private static function b64( $value ) {
		return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' );
	}

	/** @return string|false */
	private static function unb64( $value ) {
		$value = strtr( (string) $value, '-_', '+/' );
		return base64_decode( $value . str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 ), true );
	}
}

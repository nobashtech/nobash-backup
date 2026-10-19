<?php
/**
 * Short-lived restore confirmation tokens.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Class RestoreToken
 */
class RestoreToken {

	const LOCK_PREFIX = 'nobash_rt_lock_';

	/**
	 * Issue a token after preflight succeeds.
	 *
	 * @param string $filename Archive filename.
	 * @return string
	 */
	public static function issue( $filename ) {
		$token    = wp_generate_password( 32, false, false );
		$filename = basename( $filename );
		$key      = self::key( $token );
		set_transient(
			$key,
			array(
				'user_id'  => get_current_user_id(),
				'filename' => $filename,
				'blog_id'  => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1,
			),
			5 * MINUTE_IN_SECONDS
		);
		return $token;
	}

	/**
	 * Consume/validate token.
	 *
	 * @param string $token    Token.
	 * @param string $filename Filename.
	 * @return true|\WP_Error
	 */
	public static function consume( $token, $filename ) {
		$token    = (string) $token;
		$filename = basename( $filename );
		if ( '' === $token || strlen( $token ) < 16 ) {
			return new \WP_Error( 'nobash_restore_token', __( 'Restore confirmation expired or missing. Run the safety check again.', 'nobash-backup' ), array( 'status' => 400 ) );
		}

		$key  = self::key( $token );
		$lock = self::LOCK_PREFIX . substr( hash( 'sha256', $token ), 0, 32 );
		if ( ! self::acquire_lock( $lock ) ) {
			return new \WP_Error( 'nobash_restore_token', __( 'Restore confirmation is already being used. Wait for the current request to finish.', 'nobash-backup' ), array( 'status' => 409 ) );
		}
		try {
			$data = get_transient( $key );
			/* Consume while holding a database-backed lock so double-clicks or
			 * concurrent REST requests cannot start the same restore twice. */
			delete_transient( $key );
		} finally {
			delete_option( $lock );
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'nobash_restore_token', __( 'Restore confirmation expired. Please try again.', 'nobash-backup' ), array( 'status' => 400 ) );
		}
		if ( (int) $data['user_id'] !== get_current_user_id() ) {
			return new \WP_Error( 'nobash_restore_token', __( 'Restore confirmation is not valid for this user.', 'nobash-backup' ), array( 'status' => 403 ) );
		}
		if ( (string) $data['filename'] !== $filename ) {
			return new \WP_Error( 'nobash_restore_token', __( 'Restore confirmation does not match this archive.', 'nobash-backup' ), array( 'status' => 400 ) );
		}
		$blog = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
		if ( (int) $data['blog_id'] !== (int) $blog ) {
			return new \WP_Error( 'nobash_restore_token', __( 'Restore confirmation is not valid for this site.', 'nobash-backup' ), array( 'status' => 400 ) );
		}

		return true;
	}


	/** Acquire a short one-time-token lock. */
	private static function acquire_lock( $name ) {
		$now = time();
		if ( add_option( $name, $now, '', false ) ) { return true; }
		$existing = (int) get_option( $name, 0 );
		if ( $existing > 0 && $existing < ( $now - 60 ) ) {
			delete_option( $name );
			return add_option( $name, $now, '', false );
		}
		return false;
	}

	/**
	 * @param string $token Token.
	 * @return string
	 */
	private static function key( $token ) {
		return 'nobash_rt_' . hash( 'sha256', $token );
	}
}

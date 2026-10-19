<?php
/**
 * Authenticated local storage for provider/webhook credentials.
 *
 * @package Nobash\Backup
 */
namespace Nobash\Backup\Storage;

defined( 'ABSPATH' ) || exit;

final class CredentialVault {
	const OPTION     = 'nobash_backup_storage_credentials';
	const KEY_OPTION = 'nobash_backup_credential_master_key';

	/** Return a plugin-specific 256-bit key without touching WordPress auth salts. */
	private static function key() {
		$encoded = get_option( self::KEY_OPTION, '' );
		if ( is_string( $encoded ) && '' !== $encoded ) {
			$key = base64_decode( $encoded, true );
			if ( is_string( $key ) && 32 === strlen( $key ) ) {
				return $key;
			}
			throw new \RuntimeException( esc_html__( 'The Nobash Backup credential master key is invalid.', 'nobash-backup' ) );
		}

		$key = random_bytes( 32 );
		$stored = add_option( self::KEY_OPTION, base64_encode( $key ), '', 'no' );
		if ( ! $stored ) {
			// Another request may have created the key first. Always use the persisted
			// winner so concurrent saves cannot encrypt with different ephemeral keys.
			$encoded = get_option( self::KEY_OPTION, '' );
			$existing = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
			if ( ! is_string( $existing ) || 32 !== strlen( $existing ) ) {
				throw new \RuntimeException( esc_html__( 'Nobash Backup could not initialize secure credential storage.', 'nobash-backup' ) );
			}
			return $existing;
		}
		return $key;
	}

	/** @return string */
	public static function seal( array $value ) {
		$plain = wp_json_encode( $value );
		if ( false === $plain ) {
			throw new \RuntimeException( esc_html__( 'Could not encode storage credentials.', 'nobash-backup' ) );
		}
		$key = self::key();
		if ( function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
			$cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plain, 'nbh-storage-v1', $nonce, $key );
			return 's1:' . base64_encode( $nonce . $cipher );
		}
		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'nbh-storage-v1' );
			if ( false !== $cipher ) {
				return 'o1:' . base64_encode( $iv . $tag . $cipher );
			}
		}
		throw new \RuntimeException( esc_html__( 'Secure credential storage requires libsodium or OpenSSL AES-256-GCM.', 'nobash-backup' ) );
	}

	/** @return array<string,mixed> */
	public static function open( $sealed ) {
		if ( ! is_string( $sealed ) || strlen( $sealed ) < 8 ) {
			return array();
		}
		$raw = base64_decode( substr( $sealed, 3 ), true );
		if ( false === $raw ) {
			return array();
		}
		try {
			$key = self::key();
		} catch ( \Throwable $e ) {
			return array();
		}
		$plain = false;
		if ( 0 === strpos( $sealed, 's1:' ) && function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' ) ) {
			$n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
			if ( strlen( $raw ) > $n ) {
				$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( substr( $raw, $n ), 'nbh-storage-v1', substr( $raw, 0, $n ), $key );
			}
		} elseif ( 0 === strpos( $sealed, 'o1:' ) && function_exists( 'openssl_decrypt' ) && strlen( $raw ) > 28 ) {
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), 'nbh-storage-v1' );
		}
		$data = is_string( $plain ) ? json_decode( $plain, true ) : null;
		return is_array( $data ) ? $data : array();
	}

	/** @return void */
	public static function save( $provider, array $credentials ) {
		$provider = sanitize_key( $provider );
		if ( '' === $provider ) {
			throw new \InvalidArgumentException( esc_html__( 'Credential provider ID is required.', 'nobash-backup' ) );
		}
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ $provider ] = self::seal( $credentials );
		update_option( self::OPTION, $all, false );
	}

	/** @return array<string,mixed> */
	public static function load( $provider ) {
		$all = get_option( self::OPTION, array() );
		$key = sanitize_key( $provider );
		return is_array( $all ) && isset( $all[ $key ] ) ? self::open( $all[ $key ] ) : array();
	}

	/** @return void */
	public static function delete( $provider ) {
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) ) {
			return;
		}
		unset( $all[ sanitize_key( $provider ) ] );
		if ( $all ) {
			update_option( self::OPTION, $all, false );
		} else {
			delete_option( self::OPTION );
		}
	}
}

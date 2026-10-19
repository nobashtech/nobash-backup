<?php
/** Bounded resumable chunk-chain fingerprints. @package Nobash\Backup */
namespace Nobash\Backup\Filesystem;

defined( 'ABSPATH' ) || exit;

/** Build SHA-256 fingerprints without serializing PHP hash contexts. */
final class ChunkFingerprint {
	const FILE_SCHEME = 'chunk-chain-v1';
	const ARCHIVE_SCHEME = 'record-chain-v1';

	/** @return string */
	public static function seed( $domain ) {
		return hash( 'sha256', "NBH-CHAIN-V1\0" . sanitize_key( (string) $domain ) );
	}

	/** @return string */
	public static function advance( $previous, $chunk_sha256, $offset, $length, $index ) {
		$previous = strtolower( (string) $previous );
		$chunk_sha256 = strtolower( (string) $chunk_sha256 );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $previous ) || ! preg_match( '/^[a-f0-9]{64}$/', $chunk_sha256 ) ) {
			throw new \InvalidArgumentException( 'Invalid chunk-chain digest.' );
		}
		$material = hex2bin( $previous ) . hex2bin( $chunk_sha256 ) . '|' . (string) (int) $offset . '|' . (string) (int) $length . '|' . (string) (int) $index;
		return hash( 'sha256', $material );
	}

	/** @return string */
	public static function finalize( $chain, $total_bytes, $chunks, $domain ) {
		$chain = strtolower( (string) $chain );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $chain ) ) {
			throw new \InvalidArgumentException( 'Invalid final chunk-chain digest.' );
		}
		return hash( 'sha256', "NBH-FINGERPRINT-V1\0" . sanitize_key( (string) $domain ) . "\0" . hex2bin( $chain ) . '|' . (string) (int) $total_bytes . '|' . (string) (int) $chunks );
	}
}

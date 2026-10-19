<?php
/** Logical archive fingerprints that avoid rescanning very large native archives. @package Nobash\Backup */
namespace Nobash\Backup\Archive;

use Nobash\Backup\Filesystem\ChunkFingerprint;
use Nobash\Backup\Security\PasswordArchive;

defined( 'ABSPATH' ) || exit;

final class ArchiveFingerprint {
	/** @return array{fingerprint:string,checksum_scheme:string} */
	public static function inspect( $path ) {
		if ( ! is_readable( $path ) ) { throw new \RuntimeException( esc_html__( 'Archive is not readable.', 'nobash-backup' ) ); }
		if ( PasswordArchive::is_password_protected( $path ) ) {
			PasswordArchive::inspect( $path );
			return array(
				'fingerprint' => strtolower( PasswordArchive::fingerprint( $path ) ),
				'checksum_scheme' => PasswordArchive::CHECKSUM_SCHEME,
			);
		}
		if ( NativeArchiveReader::is_native( $path ) ) {
			$reader = new NativeArchiveReader( $path );
			$fingerprint = strtolower( $reader->fingerprint() );
			return array(
				'fingerprint' => $fingerprint,
				'checksum_scheme' => ChunkFingerprint::ARCHIVE_SCHEME,
			);
		}
		throw new \RuntimeException( esc_html__( 'This is not a supported .nbh archive.', 'nobash-backup' ) );
	}

	/** @return string */
	public static function value( $path ) { return (string) self::inspect( $path )['fingerprint']; }
}

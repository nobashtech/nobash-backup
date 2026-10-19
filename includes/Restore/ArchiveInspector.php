<?php
/**
 * Archive metadata and restore-readiness inspection.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Restore;

use Nobash\Backup\Archive\NativeArchiveReader;
use Nobash\Backup\Security\PasswordArchive;
use Nobash\Backup\Storage\LocalStorage;

defined( 'ABSPATH' ) || exit;

/** Build privacy-safe archive details without extracting archive contents. */
final class ArchiveInspector {

	/** @var LocalStorage */
	private $storage;

	public function __construct( ?LocalStorage $storage = null ) {
		$this->storage = $storage ? $storage : new LocalStorage();
	}

	/** @return array<string,mixed> */
	public function inspect( $filename ) {
		$filename = basename( (string) $filename );
		$path     = $this->storage->resolve_archive( $filename );
		if ( ! $path ) {
			throw new \RuntimeException( esc_html__( 'Backup archive not found.', 'nobash-backup' ) );
		}
		$index     = $this->storage->read_archive_index( $filename );
		$password  = PasswordArchive::is_password_protected( $path );
		$native    = NativeArchiveReader::is_native( $path );
		$encrypted = $password;
		$format    = $password ? 'password-protected .nbh' : ( $native ? 'native .nbh' : 'unsupported' );
		$integrity = sanitize_key( (string) ( $index['integrity_status'] ?? '' ) );
		if ( '' === $integrity ) {
			$integrity = ! empty( $index['verified_at'] ) ? 'verified' : ( $encrypted ? 'password_required' : 'ready_for_verification' );
		}
		$profile    = sanitize_key( (string) ( $index['profile'] ?? '' ) );
		$components = isset( $index['components'] ) && is_array( $index['components'] )
			? array_values( array_intersect( array( 'database', 'files', 'uploads', 'plugins', 'themes' ), array_map( 'sanitize_key', $index['components'] ) ) )
			: $this->components_for_profile( $profile );
		if ( empty( $components ) ) {
			$components = $this->components_for_profile( $profile );
		}
		$created    = isset( $index['created'] ) ? (int) $index['created'] : (int) filemtime( $path );

		return array(
			'filename'          => $filename,
			'size'              => (int) filesize( $path ),
			'modified'          => (int) filemtime( $path ),
			'created'           => $created,
			'format'            => $format,
			'native'            => $native,
			'encrypted'         => $encrypted,
			'password_required' => $password,
			'profile'           => $profile,
			'profile_label'     => LocalStorage::profile_label( $profile ),
			'components'        => $components,
			'backup_mode'       => sanitize_key( (string) ( $index['backup_mode'] ?? 'full' ) ),
			'chain_id'          => sanitize_text_field( (string) ( $index['chain_id'] ?? '' ) ),
			'point_id'          => sanitize_text_field( (string) ( $index['point_id'] ?? '' ) ),
			'parent_point_id'   => sanitize_text_field( (string) ( $index['parent_point_id'] ?? '' ) ),
			'source'            => sanitize_key( (string) ( $index['source'] ?? 'local' ) ),
			'original_name'     => sanitize_file_name( (string) ( $index['original_name'] ?? '' ) ),
			'archive_type'      => sanitize_key( (string) ( $index['archive_type'] ?? '' ) ),
			'source_network_id' => max( 0, (int) ( $index['source_network_id'] ?? 0 ) ),
			'source_blog_id'    => max( 0, (int) ( $index['source_blog_id'] ?? 0 ) ),
			'source_domain'     => sanitize_text_field( (string) ( $index['domain'] ?? '' ) ),
			'source_path'       => sanitize_text_field( (string) ( $index['path'] ?? '' ) ),
			'uploads_relative'  => trim( str_replace( '\\', '/', sanitize_text_field( (string) ( $index['uploads_relative'] ?? '' ) ) ), '/' ),
			'site_users_total'  => max( 0, (int) ( $index['site_users_total'] ?? 0 ) ),
			'site_users_truncated' => ! empty( $index['site_users_truncated'] ),
			'sha256'            => preg_match( '/^[a-f0-9]{64}$/i', (string) ( $index['sha256'] ?? '' ) ) ? strtolower( (string) $index['sha256'] ) : '',
			'integrity_status'  => $integrity,
			'verified_at'       => sanitize_text_field( (string) ( $index['verified_at'] ?? '' ) ),
			'restore_ready'     => $native || $password,
			'verification_note' => $this->verification_note( $integrity, $password ),
		);
	}

	/** Persist verification state after a successful restore verification/deployment. */
	public function mark_verified( $filename, array $extra = array() ) {
		$filename          = basename( (string) $filename );
		$index             = $this->storage->read_archive_index( $filename );
		$path              = $this->storage->resolve_archive( $filename );
		$password_required = $path ? PasswordArchive::is_password_protected( $path ) : false;
		$this->storage->write_archive_index(
			$filename,
			array_merge(
				$index,
				$extra,
				array(
					'integrity_status'  => 'verified',
					'verified_at'       => gmdate( 'c' ),
					'password_required' => $password_required,
				)
			)
		);
	}

	/** @return array<int,string> */
	private function components_for_profile( $profile ) {
		switch ( $profile ) {
			case 'database':
				return array( 'database' );
			case 'uploads':
				return array( 'uploads' );
			case 'plugins':
				return array( 'plugins' );
			case 'themes':
				return array( 'themes' );
			case 'files':
				return array( 'files' );
			default:
				return array( 'database', 'files' );
		}
	}

	private function verification_note( $integrity, $password_required ) {
		if ( 'verified' === $integrity ) {
			return __( 'Archive integrity was verified successfully.', 'nobash-backup' );
		}
		if ( $password_required ) {
			return __( 'The password and every archive record will be verified before restore changes are applied.', 'nobash-backup' );
		}
		return __( 'Every archive record and checksum will be verified inside quarantine before restore changes are applied.', 'nobash-backup' );
	}
}

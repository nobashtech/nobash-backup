<?php
/** Restore planning, component selection and transactional recovery sizing. @package Nobash\Backup */
namespace Nobash\Backup\Restore;

use Nobash\Backup\Archive\NativeArchiveReader;
use Nobash\Backup\Security\PasswordArchive;
use Nobash\Backup\Storage\LocalStorage;

defined( 'ABSPATH' ) || exit;

class RestorePlan {
	/** @return array<string,mixed> */
	public function build( LocalStorage $storage, $filename, array $components = array(), $path_override = '' ) {
		$path = $path_override ? (string) $path_override : $storage->resolve_archive( basename( (string) $filename ) );
		if ( null === $path || ! is_readable( $path ) ) { throw new \RuntimeException( esc_html__( 'Backup archive not found.', 'nobash-backup' ) ); }
		if ( ! NativeArchiveReader::is_native( $path ) && ! PasswordArchive::is_password_protected( $path ) ) { throw new \RuntimeException( esc_html__( 'This restore workflow requires an native .nbh archive.', 'nobash-backup' ) ); }
		$index = $storage->read_archive_index( basename( (string) $filename ) );
		$size = (int) filesize( $path );
		if ( PHP_INT_SIZE < 8 && $size > 2147483647 ) { throw new \RuntimeException( esc_html__( 'Restore blocked: archives larger than 2 GB require 64-bit PHP.', 'nobash-backup' ) ); }
		$allowed = array( 'database', 'files', 'uploads', 'plugins', 'themes' );
		$profile = sanitize_key( (string) ( $index['profile'] ?? '' ) );
		$available = isset( $index['components'] ) && is_array( $index['components'] )
			? array_values( array_intersect( $allowed, array_map( 'sanitize_key', $index['components'] ) ) )
			: $this->components_for_profile( $profile );
		if ( empty( $available ) ) { $available = $this->components_for_profile( $profile ); }
		if ( empty( $available ) ) { $available = array( 'database', 'files' ); }

		$requested = array_values( array_intersect( $allowed, array_map( 'sanitize_key', $components ) ) );
		$selected = empty( $requested ) ? $available : $this->normalize_requested_components( $requested, $available );
		if ( empty( $selected ) ) {
			throw new \RuntimeException( esc_html__( 'The selected restore content is not present in this backup archive.', 'nobash-backup' ) );
		}
		$database_selected = in_array( 'database', $selected, true );
		$files_selected = (bool) array_intersect( array( 'files', 'uploads', 'plugins', 'themes' ), $selected );
		$multiplier = 1.25 + ( $files_selected ? 1.25 : 0.0 ) + ( $database_selected ? 1.35 : 0.0 );
		$required = max( 268435456, (int) ceil( $size * $multiplier ) );
		$free = @disk_free_space( LocalStorage::ensure_directory() );
		if ( false !== $free && $free < $required ) {
			/* translators: 1: required space (formatted) 2: free space (formatted) */
			throw new \RuntimeException( sprintf( esc_html__( 'Restore blocked: approximately %1$s is required for quarantine, shadow database and rollback data, but only %2$s is available.', 'nobash-backup' ), esc_html( size_format( $required ) ), esc_html( size_format( $free ) ) ) );
		}
		return array(
			'filename' => basename( (string) $filename ), 'path' => $path, 'archive_size' => $size,
			'archive_sha256' => isset( $index['sha256'] ) ? (string) $index['sha256'] : '',
			'components' => $selected, 'available_components' => $available, 'created_at' => gmdate( 'c' ), 'estimated_workspace_bytes' => $required,
			'database_strategy' => $database_selected ? 'shadow import, validation, atomic publish and preserved rollback tables' : 'not selected',
			'filesystem_strategy' => $files_selected ? 'verified temporary copy, append-only action journal and atomic rename' : 'not selected',
			'rollback_guarantee' => 'automatic database and filesystem rollback before a failed restore becomes terminal',
		);
	}

	/** @return array<int,string> */
	private function components_for_profile( $profile ) {
		switch ( sanitize_key( (string) $profile ) ) {
			case 'database': return array( 'database' );
			case 'uploads': return array( 'uploads' );
			case 'plugins': return array( 'plugins' );
			case 'themes': return array( 'themes' );
			case 'files': return array( 'files' );
			case 'full': return array( 'database', 'files' );
			default: return array();
		}
	}

	/**
	 * Keep the requested scope inside what the archive can actually supply.
	 * A broad `files` request maps to a narrower files-only archive (themes/plugins/uploads),
	 * while a full files archive may still be narrowed to a specific file component.
	 * @return array<int,string>
	 */
	private function normalize_requested_components( array $requested, array $available ) {
		$out = array();
		$file_components = array( 'files', 'uploads', 'plugins', 'themes' );
		if ( in_array( 'database', $requested, true ) && in_array( 'database', $available, true ) ) { $out[] = 'database'; }
		foreach ( $file_components as $component ) {
			if ( ! in_array( $component, $requested, true ) ) { continue; }
			if ( 'files' === $component ) {
				foreach ( $file_components as $available_component ) {
					if ( in_array( $available_component, $available, true ) ) { $out[] = $available_component; }
				}
			} elseif ( in_array( $component, $available, true ) || in_array( 'files', $available, true ) ) {
				$out[] = $component;
			}
		}
		return array_values( array_unique( $out ) );
	}

}

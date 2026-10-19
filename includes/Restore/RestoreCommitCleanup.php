<?php
/** Post-terminal cleanup for a committed restore. @package Nobash\Backup */
namespace Nobash\Backup\Restore;

use Nobash\Backup\Database\ShadowDatabaseManager;
use Nobash\Backup\Security\SafeTreeRemoval;
use Nobash\Backup\Storage\LocalStorage;

defined( 'ABSPATH' ) || exit;

/** Delete rollback material only after the job's successful terminal state is durable. */
class RestoreCommitCleanup {
	/** @return array{success:bool,errors:array<int,string>} */
	public static function finalize( array $cursor, LocalStorage $storage ) {
		$errors = array();
		try {
			if ( ! empty( $cursor['database_map'] ) || ! empty( $cursor['database_tables'] ) ) {
				$db = new ShadowDatabaseManager();
				if ( ! empty( $cursor['database_map'] ) ) {
					$db->cleanup( (array) $cursor['database_map'] );
				}
				// Imported Nobash/Action Scheduler operational tables are excluded
				// from publication, so they are absent from database_map. Remove the
				// remaining shadow-prefixed imports after the commit is durable.
				$db->drop_named( (array) ( $cursor['database_tables'] ?? array() ) );
				foreach ( (array) $cursor['database_map'] as $row ) {
					foreach ( array( 'shadow', 'rollback' ) as $key ) {
						if ( ! empty( $row[ $key ] ) && $db->table_exists( (string) $row[ $key ] ) ) {
							$errors[] = sprintf( /* translators: %s table */ __( 'Could not remove committed restore table %s.', 'nobash-backup' ), (string) $row[ $key ] );
						}
					}
				}
				foreach ( (array) ( $cursor['database_tables'] ?? array() ) as $table ) {
					if ( $db->table_exists( (string) $table ) ) {
						$errors[] = sprintf( /* translators: %s table */ __( 'Could not remove excluded restore table %s.', 'nobash-backup' ), (string) $table );
					}
				}
			}
		} catch ( \Throwable $e ) {
			$errors[] = sanitize_text_field( $e->getMessage() );
		}

		try {
			self::remove_tree( (string) ( $cursor['rollback'] ?? '' ) );
			if ( ! empty( $cursor['rollback'] ) && is_dir( (string) $cursor['rollback'] ) ) {
				$errors[] = __( 'Could not remove the committed filesystem rollback directory.', 'nobash-backup' );
			}
		} catch ( \Throwable $e ) {
			$errors[] = sanitize_text_field( $e->getMessage() );
		}

		if ( empty( $errors ) ) {
			try { $storage->cleanup_job_temp( (int) ( $cursor['job_id'] ?? 0 ) ); }
			catch ( \Throwable $e ) { $errors[] = sanitize_text_field( $e->getMessage() ); }
		}
		if ( empty( $errors ) ) { EmergencyRecovery::clear( (int) ( $cursor['job_id'] ?? 0 ) ); }
		return array( 'success' => empty( $errors ), 'errors' => array_values( array_unique( $errors ) ) );
	}

	/** @return void */
	private static function remove_tree( $path ) {
		$path = untrailingslashit( (string) $path );
		if ( '' === $path || ( ! file_exists( $path ) && ! is_link( $path ) ) ) { return; }
		SafeTreeRemoval::remove( $path, dirname( $path ) );
	}
}

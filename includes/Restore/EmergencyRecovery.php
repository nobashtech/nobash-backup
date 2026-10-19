<?php
/** Emergency restore recovery marker and status service. @package Nobash\Backup */
namespace Nobash\Backup\Restore;

defined( 'ABSPATH' ) || exit;

/** Keep a small option outside restored plugin tables so interrupted recovery remains discoverable. */
class EmergencyRecovery {
	const OPTION = 'nobash_backup_active_restore';

	/** @param array<string,mixed> $cursor Cursor. @return void */
	public static function mark( $job_id, array $cursor, $state = 'running' ) {
		$is_commit = 0 === strpos( sanitize_key( (string) $state ), 'commit_' );
		$data = array(
			'job_id' => absint( $job_id ),
			'state' => sanitize_key( (string) $state ),
			'phase' => sanitize_key( (string) ( $cursor['phase'] ?? 'init' ) ),
			'filename' => basename( (string) ( $cursor['filename'] ?? '' ) ),
			'database_published' => ! empty( $cursor['database_published'] ),
			'filesystem_started' => ! empty( $cursor['filesystem_started'] ),
			'rollback_available' => ! $is_commit && ( ! empty( $cursor['database_published'] ) || ! empty( $cursor['filesystem_started'] ) ),
			'cleanup_pending' => $is_commit,
			'updated_at' => gmdate( 'c' ),
		);
		update_option( self::OPTION, $data, false );
	}

	/** @return array<string,mixed> */
	public static function status() {
		$data = get_option( self::OPTION, array() );
		return is_array( $data ) ? $data : array();
	}

	/** @return void */
	public static function clear( $job_id = 0 ) {
		$current = self::status();
		if ( $job_id > 0 && ! empty( $current['job_id'] ) && absint( $current['job_id'] ) !== absint( $job_id ) ) { return; }
		delete_option( self::OPTION );
	}
}

<?php
/**
 * Privacy-safe local protection reporting.
 *
 * @package Nobash\Backup
 */
namespace Nobash\Backup\Reporting;

use Nobash\Backup\Jobs\JobRepository;
use Nobash\Backup\Jobs\JobError;
use Nobash\Backup\Schedule\ScheduleRepository;
use Nobash\Backup\Security\AuditLog;
use Nobash\Backup\Storage\LocalStorage;

defined( 'ABSPATH' ) || exit;

final class ReportService {
	/** @return array<string,mixed> */
	public function summary() {
		$storage   = new LocalStorage();
		$backups   = $storage->list_archives();
		$jobs      = ( new JobRepository() )->recent( 75 );
		$schedules = ( new ScheduleRepository() )->all();
		$settings  = get_option( 'nobash_backup_settings', array() );

		$completed = 0;
		$failed    = 0;
		$warnings  = 0;
		$running   = 0;
		$cancelled = 0;
		$durations = array();
		$type_counts = array();
		$last_success = null;
		$last_failure = null;

		foreach ( $jobs as $job ) {
			$type = sanitize_key( (string) ( $job['type'] ?? 'unknown' ) );
			$type_counts[ $type ] = isset( $type_counts[ $type ] ) ? $type_counts[ $type ] + 1 : 1;
			$status = $this->display_status( $job );
			if ( 'completed' === $status ) {
				++$completed;
				if ( null === $last_success ) { $last_success = $job; }
			} elseif ( 'completed_with_warnings' === $status ) {
				++$completed;
				++$warnings;
				if ( null === $last_success ) { $last_success = $job; }
			} elseif ( 'failed' === $status ) {
				++$failed;
				if ( null === $last_failure ) { $last_failure = $job; }
			} elseif ( 'cancelled' === $status ) {
				++$cancelled;
			} elseif ( ! in_array( $status, array( 'paused' ), true ) ) {
				++$running;
			}
			$started = ! empty( $job['started'] ) ? strtotime( (string) $job['started'] . ' UTC' ) : false;
			$ended   = ! empty( $job['completed'] ) ? strtotime( (string) $job['completed'] . ' UTC' ) : false;
			if ( false !== $started && false !== $ended && $ended >= $started ) {
				$durations[] = $ended - $started;
			}
		}

		$latest   = ! empty( $backups[0] ) ? $backups[0] : null;
		$last_age = $latest ? max( 0, time() - (int) $latest['modified'] ) : null;
		$enabled_schedules = count( array_filter( $schedules, static function ( $schedule ) { return ! empty( $schedule['enabled'] ); } ) );
		$verified_count = count( array_filter( $backups, static function ( $backup ) { return 'verified' === (string) ( $backup['integrity_status'] ?? '' ); } ) );
		$encrypted_count = count( array_filter( $backups, static function ( $backup ) { return ! empty( $backup['encrypted'] ); } ) );
		$pending_verification = max( 0, count( $backups ) - $verified_count );
		$backup_bytes = array_sum( array_map( static function ( $item ) { return (int) ( $item['size'] ?? 0 ); }, $backups ) );

		$score    = 100;
		$findings = array();
		if ( empty( $backups ) ) {
			$score -= 50;
			$findings[] = $this->finding( 'no_restore_point', 'critical', __( 'No restore point available', 'nobash-backup' ), __( 'Create a full-site backup so this site has a recoverable local restore point.', 'nobash-backup' ), __( 'Create backup', 'nobash-backup' ), 'backup' );
		} elseif ( $last_age > 7 * DAY_IN_SECONDS ) {
			$score -= 25;
			$findings[] = $this->finding( 'backup_older_than_week', 'warning', __( 'Latest backup is outdated', 'nobash-backup' ), __( 'The latest local backup is older than seven days.', 'nobash-backup' ), __( 'Create fresh backup', 'nobash-backup' ), 'backup' );
		} elseif ( $last_age > DAY_IN_SECONDS ) {
			$score -= 10;
			$findings[] = $this->finding( 'backup_older_than_day', 'notice', __( 'Backup freshness needs review', 'nobash-backup' ), __( 'The latest local backup is older than one day.', 'nobash-backup' ), __( 'Review backups', 'nobash-backup' ), 'backups' );
		}
		if ( ! empty( $backups ) && 0 === $verified_count ) {
			$score -= 10;
			$findings[] = $this->finding( 'no_verified_restore_point', 'warning', __( 'No verified restore point', 'nobash-backup' ), __( 'Existing archives will be verified before restore, but none is currently marked verified.', 'nobash-backup' ), __( 'Open backups', 'nobash-backup' ), 'backups' );
		}
		if ( $failed > 0 ) {
			$score -= min( 30, $failed * 5 );
			$findings[] = $this->finding( 'recent_failed_jobs', 'warning', __( 'Recent jobs need attention', 'nobash-backup' ), sprintf( /* translators: %d: Number of failed jobs. */ _n( '%d recent job failed.', '%d recent jobs failed.', $failed, 'nobash-backup' ), $failed ), __( 'Review failed jobs', 'nobash-backup' ), 'reports' );
		}
		if ( 0 === $enabled_schedules ) {
			$score -= 10;
			$findings[] = $this->finding( 'no_automatic_schedule', 'notice', __( 'Automatic backups are off', 'nobash-backup' ), __( 'No enabled local backup schedule is protecting this site automatically.', 'nobash-backup' ), __( 'Create schedule', 'nobash-backup' ), 'automation' );
		}
		$free    = $storage->free_space();
		$reserve = max( 0, absint( $settings['retention_min_free_mb'] ?? 1024 ) ) * MB_IN_BYTES;
		if ( $free > 0 && $reserve > 0 && $free < $reserve ) {
			$score -= 20;
			$findings[] = $this->finding( 'low_free_space', 'critical', __( 'Storage safety reserve is low', 'nobash-backup' ), __( 'Free disk space is below the configured retention safety reserve.', 'nobash-backup' ), __( 'Review retention', 'nobash-backup' ), 'automation' );
		}
		$score = max( 0, min( 100, $score ) );
		$terminal = $completed + $failed;
		$success_rate = $terminal > 0 ? (int) round( ( $completed / $terminal ) * 100 ) : null;
		$average_duration = ! empty( $durations ) ? (int) round( array_sum( $durations ) / count( $durations ) ) : null;

		return array(
			'generated_at'          => current_time( 'mysql', true ),
			'health_score'          => $score,
			'health_label'          => $score >= 90 ? 'Protected' : ( $score >= 70 ? 'Needs attention' : 'At risk' ),
			'findings'              => $findings,
			'backup_count'          => count( $backups ),
			'backup_bytes'          => $backup_bytes,
			'verified_count'        => $verified_count,
			'encrypted_count'       => $encrypted_count,
			'pending_verification'  => $pending_verification,
			'latest_backup'         => $latest ? array( 'filename' => $latest['filename'], 'modified' => (int) $latest['modified'], 'size' => (int) $latest['size'], 'integrity_status' => (string) ( $latest['integrity_status'] ?? '' ) ) : null,
			'jobs'                  => array( 'completed' => $completed, 'failed' => $failed, 'warnings' => $warnings, 'active' => $running, 'cancelled' => $cancelled, 'success_rate' => $success_rate, 'average_duration' => $average_duration, 'type_counts' => $type_counts ),
			'last_successful_job'   => $last_success ? $this->public_job( $last_success ) : null,
			'last_failed_job'       => $last_failure ? $this->public_job( $last_failure ) : null,
			'schedules'             => array( 'total' => count( $schedules ), 'enabled' => $enabled_schedules ),
			'storage'               => array( 'path' => LocalStorage::get_directory(), 'used' => $backup_bytes, 'free_space' => $free, 'reserve' => $reserve ),
			'notifications'         => array(
				'email'              => sanitize_email( (string) ( $settings['notify_email'] ?? '' ) ),
				'webhook_configured' => ! empty( $settings['webhook_url'] ),
				'on_failure'         => false !== ( $settings['notify_on_failure'] ?? true ),
				'on_success'         => ! empty( $settings['notify_on_success'] ),
			),
			'recent_jobs'           => array_map( array( $this, 'public_job' ), $jobs ),
			'recent_audit'          => array_map( array( $this, 'public_audit' ), AuditLog::recent( 100 ) ),
		);
	}

	/** @return array<string,mixed> */
	private function finding( $code, $level, $title, $message, $action_label = '', $action_page = '' ) {
		return array(
			'code'         => sanitize_key( (string) $code ),
			'level'        => sanitize_key( (string) $level ),
			'title'        => sanitize_text_field( (string) $title ),
			'message'      => sanitize_text_field( (string) $message ),
			'action_label' => sanitize_text_field( (string) $action_label ),
			'action_page'  => sanitize_key( (string) $action_page ),
		);
	}

	/** @param array<string,mixed> $job Job. @return array<string,mixed> */
	private function public_job( array $job ) {
		$cursor = isset( $job['cursor'] ) && is_array( $job['cursor'] ) ? $job['cursor'] : array();
		$started = (string) ( $job['started'] ?? '' );
		$completed = (string) ( $job['completed'] ?? '' );
		$duration = null;
		if ( $started && $completed ) {
			$start_ts = strtotime( $started . ' UTC' );
			$end_ts   = strtotime( $completed . ' UTC' );
			if ( false !== $start_ts && false !== $end_ts && $end_ts >= $start_ts ) { $duration = $end_ts - $start_ts; }
		}
		return array(
			'id'              => (int) $job['id'],
			'type'            => (string) $job['type'],
			'status'          => $this->display_status( $job ),
			'step'            => (string) ( $job['step'] ?? '' ),
			'phase'           => sanitize_key( (string) ( $cursor['phase'] ?? '' ) ),
			'progress'        => (int) $job['progress'],
			'attempt'         => (int) ( $job['attempt'] ?? 0 ),
			'max_attempts'    => (int) ( $job['max_attempts'] ?? 0 ),
			'processed_items' => (int) ( $job['processed_items'] ?? 0 ),
			'total_items'     => (int) ( $job['total_items'] ?? 0 ),
			'processed_bytes' => (int) ( $job['processed_bytes'] ?? 0 ),
			'total_bytes'     => (int) ( $job['total_bytes'] ?? 0 ),
			'created'         => (string) ( $job['created'] ?? '' ),
			'started'         => $started,
			'completed'       => $completed,
			'duration'        => $duration,
			'error_code'      => (string) ( $job['error_code'] ?? '' ),
			'error'           => (string) ( $job['error'] ?? '' ),
			'restore_diagnostics' => JobError::restore_diagnostics( $job ),
		);
	}

	/** @param array<string,mixed> $job Job row. @return string */
	private function display_status( array $job ) {
		$status = sanitize_key( (string) ( $job['status'] ?? '' ) );
		if ( 'restore' !== (string) ( $job['type'] ?? '' ) || 'completed_with_warnings' !== $status ) {
			return $status;
		}
		$result = isset( $job['result'] ) && is_array( $job['result'] ) ? $job['result'] : array();
		foreach ( (array) ( $result['warnings'] ?? array() ) as $warning ) {
			if ( is_scalar( $warning ) && '' !== trim( (string) $warning ) ) {
				return $status;
			}
			if ( is_array( $warning ) ) {
				foreach ( array( 'message', 'warning', 'text', 'description' ) as $key ) {
					if ( isset( $warning[ $key ] ) && is_scalar( $warning[ $key ] ) && '' !== trim( (string) $warning[ $key ] ) ) {
						return $status;
					}
				}
			}
		}
		return 'completed';
	}

	/** @param array<string,mixed> $row Audit row. @return array<string,mixed> */
	private function public_audit( array $row ) {
		return array(
			'id'          => (int) ( $row['id'] ?? 0 ),
			'action'      => sanitize_key( (string) ( $row['action'] ?? '' ) ),
			'object_type' => sanitize_key( (string) ( $row['object_type'] ?? '' ) ),
			'object_id'   => sanitize_text_field( (string) ( $row['object_id'] ?? '' ) ),
			'user_id'     => (int) ( $row['user_id'] ?? 0 ),
			'created_at'  => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
		);
	}
}

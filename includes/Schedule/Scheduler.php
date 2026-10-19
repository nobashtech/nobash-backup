<?php
/**
 * Scheduled backups via WP-Cron (Action Scheduler compatible hooks).
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Schedule;

use Nobash\Backup\Container\Container;
use Nobash\Backup\Jobs\JobEngine;
use Nobash\Backup\Storage\LocalStorage;

defined( 'ABSPATH' ) || exit;

/**
 * Class Scheduler
 */
class Scheduler {

	const CRON_HOOK     = 'nobash_backup_run_scheduled';
	const RETENTION_HOOK = 'nobash_backup_run_retention';
	const JOB_HOOK      = 'nobash_backup_run_job_slice';
	const RECOVERY_HOOK = 'nobash_backup_recover_stale_jobs';

	/**
	 * Container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedules' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled' ) );
		add_action( self::RETENTION_HOOK, array( $this, 'run_retention' ) );
		add_action( self::JOB_HOOK, array( $this, 'run_job_slice' ), 10, 1 );
		add_action( self::RECOVERY_HOOK, array( $this, 'recover_stale_jobs' ) );
		add_action( 'action_scheduler_init', array( $this, 'ensure_events' ), 20 );

		// Schedule after init so recurrence labels do not load translations early.
		add_action( 'init', array( $this, 'ensure_events' ), 20 );
	}

	/**
	 * Custom cron schedules.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_cron_schedules( $schedules = array() ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		$schedules['nobash_every_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every Minute (Nobash Backup Job Recovery)', 'nobash-backup' ),
		);
		$schedules['nobash_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly (Nobash Backup)', 'nobash-backup' ),
		);
		$schedules['nobash_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once Monthly (Nobash Backup)', 'nobash-backup' ),
		);
		return $schedules;
	}

	/**
	 * Whether Action Scheduler is available.
	 *
	 * @return bool
	 */
	private function has_action_scheduler() {
		return function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_unschedule_all_actions' )
			&& function_exists( 'as_next_scheduled_action' )
			&& did_action( 'action_scheduler_init' );
	}

	/**
	 * Ensure cron events exist based on settings.
	 *
	 * @return void
	 */
	public function ensure_events() {
		$repository = new ScheduleRepository();
		$enabled = false;
		foreach ( $repository->all() as $schedule ) {
			if ( ! empty( $schedule['enabled'] ) ) {
				$enabled = true;
				break;
			}
		}

		if ( $this->has_action_scheduler() ) {
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
				wp_clear_scheduled_hook( self::RETENTION_HOOK );
				wp_clear_scheduled_hook( self::RECOVERY_HOOK );
			}
			if ( $enabled ) {
				if ( ! as_next_scheduled_action( self::CRON_HOOK ) ) {
					as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, MINUTE_IN_SECONDS, self::CRON_HOOK, array(), 'nobash-backup' );
				}
			} else {
				as_unschedule_all_actions( self::CRON_HOOK );
			}
			if ( ! as_next_scheduled_action( self::RETENTION_HOOK ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::RETENTION_HOOK, array(), 'nobash-backup' );
			}
			if ( ! as_next_scheduled_action( self::RECOVERY_HOOK ) ) {
				as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, MINUTE_IN_SECONDS, self::RECOVERY_HOOK, array(), 'nobash-backup' );
			}
			return;
		}

		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( $enabled && ! $next ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'nobash_every_minute', self::CRON_HOOK );
		} elseif ( ! $enabled && $next ) {
			wp_unschedule_event( $next, self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::RETENTION_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::RETENTION_HOOK );
		}
		if ( ! wp_next_scheduled( self::RECOVERY_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'nobash_every_minute', self::RECOVERY_HOOK );
		}
	}

	/**
	 * Map recurrence key to seconds.
	 *
	 * @param string $recurrence Recurrence.
	 * @return int
	 */
	private function recurrence_interval( $recurrence ) {
		switch ( $recurrence ) {
			case 'hourly':
				return HOUR_IN_SECONDS;
			case 'nobash_weekly':
				return WEEK_IN_SECONDS;
			case 'nobash_monthly':
				return 30 * DAY_IN_SECONDS;
			case 'daily':
			default:
				return DAY_IN_SECONDS;
		}
	}

	/**
	 * Clear scheduled events (deactivation).
	 *
	 * @return void
	 */
	public static function clear_scheduled_events() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CRON_HOOK );
			as_unschedule_all_actions( self::RETENTION_HOOK );
			as_unschedule_all_actions( self::JOB_HOOK );
			as_unschedule_all_actions( self::RECOVERY_HOOK );
		}

		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( self::CRON_HOOK );
			wp_unschedule_hook( self::RETENTION_HOOK );
			wp_unschedule_hook( self::JOB_HOOK );
			wp_unschedule_hook( self::RECOVERY_HOOK );
			return;
		}

		foreach ( array( self::CRON_HOOK, self::RETENTION_HOOK, self::RECOVERY_HOOK ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			while ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
				$timestamp = wp_next_scheduled( $hook );
			}
		}
	}

	/**
	 * Dispatch every due durable schedule without duplicates.
	 *
	 * @return void
	 */
	public function run_scheduled() {
		$repository = new ScheduleRepository();
		foreach ( $repository->due( 20 ) as $schedule ) {
			$this->dispatch_schedule( $schedule, true );
		}
	}

	/** Dispatch one schedule through the Core job engine. @return array<string,mixed>|null */
	public function dispatch_schedule( array $schedule, $claim = false, $advance_schedule = true ) {
		$repository = new ScheduleRepository();
		if ( $claim && ! $repository->claim( (int) $schedule['id'], (string) $schedule['next_run_at'] ) ) {
			return null;
		}
		if ( 'skip' === ( $schedule['overlap_policy'] ?? 'skip' ) && ! empty( $schedule['last_job_id'] ) ) {
			$previous = ( new \Nobash\Backup\Jobs\JobRepository() )->find( (int) $schedule['last_job_id'] );
			if ( $previous && ! \Nobash\Backup\Jobs\JobState::is_terminal( $previous['status'] ) ) {
				$repository->mark_status( (int) $schedule['id'], 'skipped_overlap', __( 'The previous scheduled job is still active.', 'nobash-backup' ) );
				$repository->mark_dispatched( $schedule, (int) $previous['id'] );
				return null;
			}
		}

		$mode = \Nobash\Backup\Incremental\BackupMode::normalize( $schedule['backup_mode'] ?? 'full' );
		$chain_id = sanitize_text_field( (string) ( $schedule['chain_id'] ?? '' ) );
		if ( \Nobash\Backup\Incremental\BackupMode::FULL !== $mode && '' === $chain_id ) {
			$chain_profile = (string) $schedule['profile'];
			if ( ! empty( $schedule['profile_id'] ) ) {
				$selected_profile = ( new \Nobash\Backup\BackupProfile\ProfileRepository() )->find( (int) $schedule['profile_id'] );
				if ( $selected_profile ) { $chain_profile = (string) $selected_profile['profile_key']; }
			}
			$chain = ( new \Nobash\Backup\Incremental\ChainRepository() )->latest_active_chain( $chain_profile );
			if ( $chain ) { $chain_id = (string) $chain['chain_id']; }
			else { $mode = \Nobash\Backup\Incremental\BackupMode::FULL; }
		}
		$args = array(
			'profile' => $schedule['profile'],
			'profile_id' => (int) ( $schedule['profile_id'] ?? 0 ),
			'backup_mode' => $mode,
			'chain_id' => $chain_id,
			'schedule_id' => (int) $schedule['id'],
			'schedule_key' => $schedule['schedule_key'],
			'storage_provider' => 'local',
		);
		/** @var JobEngine $engine */
		$engine = $this->container->get( JobEngine::class );
		try {
			$job = $engine->create_backup( $args );
		} catch ( \Throwable $error ) {
			$repository->mark_status( (int) $schedule['id'], 'dispatch_failed', $error->getMessage() );
			return null;
		}
		if ( empty( $job['id'] ) ) {
			$repository->mark_status( (int) $schedule['id'], 'dispatch_failed', __( 'The scheduled backup job could not be created.', 'nobash-backup' ) );
			return null;
		}
		if ( $advance_schedule ) {
			$repository->mark_dispatched( $schedule, (int) $job['id'] );
		} else {
			$repository->mark_manual_dispatched( (int) $schedule['id'], (int) $job['id'] );
		}
		\Nobash\Backup\Security\AuditLog::record( 'scheduled_backup_dispatched', 'job', (string) $job['id'], array( 'schedule_key' => $schedule['schedule_key'], 'manual' => ! $advance_schedule ) );
		return $job;
	}

	/**
	 * Execute one server-side job slice. The engine schedules the next slice.
	 *
	 * @param int $job_id Job ID.
	 * @return void
	 */
	public function run_job_slice( $job_id ) {
		/** @var JobEngine $engine */
		$engine = $this->container->get( JobEngine::class );
		$engine->run_slice( absint( $job_id ), 'scheduler' );
	}

	/**
	 * Recover leases left behind by terminated PHP workers.
	 *
	 * @return void
	 */
	public function recover_stale_jobs() {
		/** @var JobEngine $engine */
		$engine = $this->container->get( JobEngine::class );
		$engine->recover_stale_jobs( 25 );
	}

	/**
	 * Apply retention policies.
	 *
	 * @return void
	 */
	public function run_retention() {
		( new RetentionManager() )->run();
	}

	/**
	 * Update schedule settings and reschedule.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return array<string, mixed>
	 */
	public function update_schedule( array $data ) {
		$repository = new ScheduleRepository();
		$existing = $repository->find_by_key( 'default' );
		$saved = $repository->save( array(
			'id' => $existing ? (int) $existing['id'] : 0,
			'schedule_key' => 'default',
			'name' => __( 'Default backup schedule', 'nobash-backup' ),
			'enabled' => ! empty( $data['enabled'] ),
			'recurrence' => $data['recurrence'] ?? 'daily',
			'profile' => $data['profile'] ?? 'full',
			'profile_id' => absint( $data['profile_id'] ?? 0 ),
			'backup_mode' => $data['backup_mode'] ?? 'full',
			'run_time' => $data['run_time'] ?? $data['time'] ?? '02:00',
			'weekday' => absint( $data['weekday'] ?? 0 ),
			'monthday' => absint( $data['monthday'] ?? 1 ),
			'overlap_policy' => $data['overlap_policy'] ?? 'skip',
			'storage_provider' => 'local',
		) );
		self::clear_scheduled_events();
		$this->ensure_events();
		return $saved;
	}
}

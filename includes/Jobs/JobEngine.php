<?php
/**
 * Durable, leased and resumable job engine.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Jobs;

use Nobash\Backup\Addon\Registry as AddonRegistry;
use Nobash\Backup\Backup\BackupJob;
use Nobash\Backup\Container\Container;
use Nobash\Backup\Notifications\Notifier;
use Nobash\Backup\Migration\RemoteImportJob;
use Nobash\Backup\Restore\RestoreJob;
use Nobash\Backup\Restore\RestoreCommitCleanup;
use Nobash\Backup\Restore\EmergencyRecovery;
use Nobash\Backup\Schedule\Scheduler;
use Nobash\Backup\Storage\LocalStorage;
use Nobash\Backup\Incremental\ChainRestoreJob;
use Nobash\Backup\Compat\HostingEnvironment;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinate durable job creation, execution, control and recovery.
 */
class JobEngine {

	const TIME_BUDGET = 20.0;
	const LEASE_TTL   = 180;

	/** @var Container */
	private $container;
	/** @var JobRepository */
	private $repo;
	/** @var RetryPolicy */
	private $retry;

	public function __construct( Container $container, ?JobRepository $repo = null, ?RetryPolicy $retry = null ) {
		$this->container = $container;
		$this->repo      = $repo ? $repo : new JobRepository();
		$this->retry     = $retry ? $retry : new RetryPolicy();
	}

	/** @return JobRepository */
	public function repository() {
		return $this->repo;
	}

	/** Finish rollback-data cleanup after a successful restore is durably terminal. @return array<string,mixed>|null */
	public function finalize_restore_commit( $job_id ) {
		$job = $this->repo->find( $job_id );
		if ( ! $job || 'restore' !== $job['type'] || ! in_array( $job['status'], array( JobState::COMPLETED, JobState::COMPLETED_WITH_WARNINGS ), true ) ) { return $job; }
		$cursor = is_array( $job['cursor'] ) ? $job['cursor'] : array();
		if ( 'commit_cleanup_pending' !== (string) ( $cursor['phase'] ?? '' ) ) { return $job; }
		$cleanup = RestoreCommitCleanup::finalize( $cursor, new LocalStorage() );
		if ( ! empty( $cleanup['success'] ) ) {
			$cursor['phase'] = 'completed';
			$cursor['commit_cleanup_complete'] = true;
			unset( $cursor['database_map'], $cursor['database_tables'], $cursor['rollback'], $cursor['quarantine'], $cursor['temp'], $cursor['restore_journal'], $cursor['deployment_plan'], $cursor['database_state_file'] );
			$this->repo->update( $job_id, array( 'cursor' => $cursor ) );
			$this->repo->events()->append( $job_id, 'restore_commit_cleanup_completed', array( 'status' => $job['status'], 'step' => 'completed' ) );
		} else {
			$cursor['commit_cleanup_errors'] = array_values( (array) ( $cleanup['errors'] ?? array() ) );
			$this->repo->update( $job_id, array( 'cursor' => $cursor ) );
			EmergencyRecovery::mark( $job_id, $cursor, 'commit_cleanup_failed' );
			$this->repo->events()->append( $job_id, 'restore_commit_cleanup_failed', array( 'status' => $job['status'], 'step' => 'commit_cleanup_pending' ) );
		}
		return $this->repo->find( $job_id ) ?: $job;
	}


	/** Finish deployment rollback-data cleanup after success is durably terminal. @return array<string,mixed>|null */

	/** @param array<string, mixed> $args Args. @return array<string, mixed> */
	public function create_backup( array $args = array() ) {
		$profile_id  = absint( $args['profile_id'] ?? 0 );
		$profile_row = null;
		if ( $profile_id > 0 ) {
			$profile_row = ( new \Nobash\Backup\BackupProfile\ProfileRepository() )->find( $profile_id );
			if ( ! $profile_row || empty( $profile_row['enabled'] ) ) {
				throw new PermanentJobException( esc_html__( 'Choose an enabled backup profile.', 'nobash-backup' ) );
			}
		}
		$profile = $profile_row ? sanitize_key( (string) $profile_row['content_type'] ) : sanitize_key( (string) ( $args['profile'] ?? 'full' ) );
		if ( ! in_array( $profile, array( 'full', 'database', 'files', 'uploads', 'plugins', 'themes', 'custom' ), true ) ) {
			$profile = 'full';
		}
		$requested_scope = isset( $args['scope'] ) ? sanitize_key( (string) $args['scope'] ) : 'site';
		$scope = \Nobash\Backup\Compat\Multisite::normalize_scope( $requested_scope );
		$requested_mode = isset( $args['backup_mode'] ) && '' !== (string) $args['backup_mode']
			? $args['backup_mode']
			: ( $profile_row['backup_mode'] ?? 'full' );
		$profile_key = $profile_row ? sanitize_key( (string) $profile_row['profile_key'] ) : $profile;
		$backup_mode = \Nobash\Backup\Incremental\BackupMode::normalize( $requested_mode );
		$chain_id    = isset( $args['chain_id'] ) ? sanitize_text_field( (string) $args['chain_id'] ) : '';
		$created_safe_baseline = false;
		if ( \Nobash\Backup\Incremental\BackupMode::FULL !== $backup_mode && '' === $chain_id ) {
			$chain = ( new \Nobash\Backup\Incremental\ChainRepository() )->latest_active_chain( $profile_key );
			if ( $chain && ! empty( $chain['chain_id'] ) ) {
				$chain_id = sanitize_text_field( (string) $chain['chain_id'] );
			} else {
				$backup_mode = \Nobash\Backup\Incremental\BackupMode::FULL;
				$created_safe_baseline = true;
			}
		}
		$cursor = array(
			'phase' => 'init', 'profile' => $profile, 'scope' => $scope, 'tables' => array(),
			'profile_id' => $profile_id,
			'profile_key' => $profile_key,
			'profile_name' => $profile_row ? sanitize_text_field( (string) $profile_row['name'] ) : ucfirst( $profile ),
			'profile_include_paths' => $profile_row ? array_values( (array) $profile_row['include_paths'] ) : array_values( array_filter( array_map( 'strval', (array) ( $args['include_paths'] ?? array() ) ) ) ),
			'profile_include_tables' => $profile_row ? array_values( (array) $profile_row['include_tables'] ) : array_values( array_filter( array_map( 'strval', (array) ( $args['include_tables'] ?? array() ) ) ) ),
			'profile_exclusions' => $profile_row ? array_values( (array) $profile_row['exclusions'] ) : array_values( array_filter( array_map( 'strval', (array) ( $args['exclusions'] ?? array() ) ) ) ),
			'target_blog_id' => isset( $args['target_blog_id'] ) ? absint( $args['target_blog_id'] ) : 0,
			'include_database_objects' => ! empty( $args['include_database_objects'] ),
			'multisite_meta' => isset( $args['multisite_meta'] ) && is_array( $args['multisite_meta'] ) ? $this->sanitize_cursor_array( $args['multisite_meta'], 0, 5000 ) : array(),
			'schedule_id' => isset( $args['schedule_id'] ) ? absint( $args['schedule_id'] ) : 0,
			'schedule_key' => isset( $args['schedule_key'] ) ? sanitize_key( $args['schedule_key'] ) : '',
			'cloud_destination_id' => isset( $args['cloud_destination_id'] ) ? absint( $args['cloud_destination_id'] ) : 0,
			'keep_local_after_upload' => ! empty( $args['cloud_destination_id'] ) && ! empty( $args['keep_local_after_upload'] ),
			'storage_provider' => ! empty( $args['cloud_destination_id'] ) ? 'cloud' : 'local',
			'backup_mode' => $backup_mode,
			'requested_backup_mode' => \Nobash\Backup\Incremental\BackupMode::normalize( $requested_mode ),
			'created_safe_baseline' => $created_safe_baseline,
			'chain_id' => $chain_id,
			'table_idx' => 0, 'row_offset' => 0, 'archive' => '', 'temp' => '',
			'password_protected' => ! empty( $args['password_protected'] ),
			'password_sealed' => ! empty( $args['password'] ) ? \Nobash\Backup\Storage\CredentialVault::seal( array( 'password' => (string) $args['password'] ) ) : '',
			'extension_parent_job_id' => isset( $args['extension_parent_job_id'] ) ? absint( $args['extension_parent_job_id'] ) : 0,
			'extension_defer_finalize' => ! empty( $args['extension_defer_finalize'] ),
		);
		do_action( 'nobash_backup_before_backup', $cursor, $args );
		return $this->create_job( 'backup', $cursor );
	}

	/** @param array<string, mixed> $args Args. @return array<string, mixed> */

	/** @param array<string, mixed> $args Args. @return array<string, mixed> */

	/** @param array<string, mixed> $args Args. @return array<string, mixed> */
	public function create_restore( array $args = array() ) {
		$cursor = array(
			'phase'    => 'init',
			'filename' => isset( $args['filename'] ) ? basename( (string) $args['filename'] ) : '',
			'search'   => isset( $args['search'] ) ? (string) $args['search'] : '',
			'replace'  => isset( $args['replace'] ) ? (string) $args['replace'] : '',
			'temp'     => '',
			'components' => isset( $args['components'] ) && is_array( $args['components'] ) ? array_values( array_intersect( array( 'database', 'files', 'uploads', 'plugins', 'themes' ), $args['components'] ) ) : array( 'database', 'files' ),
			'skip_safety_backup' => ! empty( $args['skip_safety_backup'] ),
			'target_home' => isset($args['target_home']) ? esc_url_raw((string)$args['target_home']) : '',
			'target_siteurl' => isset($args['target_siteurl']) ? esc_url_raw((string)$args['target_siteurl']) : '',
			'target_table_prefix' => isset($args['target_table_prefix']) ? preg_replace('/[^A-Za-z0-9_]/','',(string)$args['target_table_prefix']) : '',
			'target_blog_id' => isset( $args['target_blog_id'] ) ? absint( $args['target_blog_id'] ) : 0,
			'file_path_map' => isset( $args['file_path_map'] ) && is_array( $args['file_path_map'] ) ? $this->sanitize_cursor_array( $args['file_path_map'] ) : array(),
			'network_restore' => ! empty( $args['network_restore'] ),
			'password_sealed' => ! empty( $args['password'] ) ? \Nobash\Backup\Storage\CredentialVault::seal( array( 'password' => (string) $args['password'] ) ) : '',
			'extension_parent_job_id' => isset( $args['extension_parent_job_id'] ) ? absint( $args['extension_parent_job_id'] ) : 0,
			'extension_defer_finalize' => ! empty( $args['extension_defer_finalize'] ),
		);
		if ( ! empty( $args['restore_point_id'] ) ) {
			$cursor['restore_point_id'] = sanitize_text_field( (string) $args['restore_point_id'] );
			$cursor['phase'] = 'init';
			return $this->create_job( 'chain_restore', $cursor );
		}
		return $this->create_job( 'restore', $cursor );
	}


	/** @param array<string,mixed> $args Args. @return array<string,mixed> */
	public function create_remote_import( array $args = array() ) {
		$cursor = array(
			'phase'  => 'init',
			'url'    => isset( $args['url'] ) ? esc_url_raw( (string) $args['url'] ) : '',
			'sha256' => isset( $args['sha256'] ) ? strtolower( sanitize_text_field( (string) $args['sha256'] ) ) : '',
		);
		return $this->create_job( 'remote_import', $cursor );
	}

	/** Create a registered add-on job with bounded serializable arguments.
	 * @param string $type Job type. @param array<string,mixed> $args Arguments. @return array<string,mixed>
	 */
	public function create_addon_job( $type, array $args = array() ) {
		$type=sanitize_key($type); $registry=$this->container->get(AddonRegistry::class); if(!$registry->get_job_type($type)){throw new PermanentJobException(esc_html__('Unknown add-on job type.','nobash-backup'));}
		$safe=array(); foreach($args as $key=>$value){$key=sanitize_key($key);if(!$key)continue;if(is_scalar($value)||null===$value){$safe[$key]=is_string($value)?sanitize_text_field($value):$value;}}
		return $this->create_job($type,array('phase'=>'init','addon_args'=>$safe));
	}

	/**
	 * Compatibility alias for all existing callers.
	 *
	 * @param int $job_id Job ID.
	 * @return array<string, mixed>
	 */
	public function step( $job_id ) {
		return $this->run_slice( $job_id, 'browser' );
	}

	/**
	 * Run one bounded, idempotent slice under an exclusive database lease.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $runner Runner name.
	 * @return array<string, mixed>
	 */
	public function run_slice( $job_id, $runner = 'manual' ) {
		$job = $this->repo->find( $job_id );
		if ( ! $job ) {
			throw new PermanentJobException( esc_html__( 'Job not found.', 'nobash-backup' ) );
		}
		if ( JobState::is_terminal( $job['status'] ) || JobState::PAUSED === $job['status'] ) {
			if ( 'restore' === $job['type'] && in_array( $job['status'], array( JobState::COMPLETED, JobState::COMPLETED_WITH_WARNINGS ), true ) && 'commit_cleanup_pending' === (string) ( $job['cursor']['phase'] ?? '' ) ) {
				return $this->finalize_restore_commit( $job_id ) ?: $job;
			}
			return $job;
		}
		if ( ! $this->is_due( $job ) ) {
			return $job;
		}

		$owner = $this->worker_id();
		if ( ! $this->repo->acquire_lease( $job_id, $owner, self::LEASE_TTL, $runner ) ) {
			return $this->repo->find( $job_id ) ?: $job;
		}

		try {
			$job = $this->repo->find( $job_id );
			if ( ! $job ) {
				throw new PermanentJobException( __( 'Job not found after lease acquisition.', 'nobash-backup' ) );
			}

			if ( $job['cancel_requested'] ) {
				return $this->finish_cancel( $job, $owner );
			}
			if ( $job['pause_requested'] ) {
				return $this->finish_pause( $job, $owner );
			}

			if ( JobState::RUNNING !== $job['status'] ) {
				if ( ! $this->repo->transition(
					$job_id,
					JobState::RUNNING,
					array(
						'heartbeat_at' => current_time( 'mysql', true ),
						'available_at' => current_time( 'mysql', true ),
						'error_code'   => '',
						'error'        => null,
					),
					$owner
				) ) {
					return $this->repo->find( $job_id ) ?: $job;
				}
				$job = $this->repo->find( $job_id );
			}

			$this->repo->renew_lease( $job_id, $owner, self::LEASE_TTL );
			$this->repo->events()->append(
				$job_id,
				'slice_started',
				array( 'status' => JobState::RUNNING, 'step' => $job['step'], 'attempt' => $job['attempt'], 'runner' => $runner )
			);

			$result = $this->process_handler( $job );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( $result->get_error_message() );
			}
			if ( ! is_array( $result ) ) {
				throw new PermanentJobException( __( 'Job handler returned an invalid response.', 'nobash-backup' ) );
			}

			$current = $this->repo->find( $job_id );
			if ( ! $current || ! $this->repo->owns_lease( $job_id, $owner ) ) {
				return $current ?: $job;
			}

			$cursor = isset( $result['cursor'] ) && is_array( $result['cursor'] ) ? $result['cursor'] : $current['cursor'];
			$step   = isset( $cursor['phase'] ) ? sanitize_key( (string) $cursor['phase'] ) : $current['step'];
			$status = $this->normalize_handler_status( isset( $result['status'] ) ? $result['status'] : JobState::RUNNING );
			if ( 'restore' === $current['type'] && JobState::COMPLETED_WITH_WARNINGS === $status ) {
				$warning_source = array_merge(
					(array) ( $result['result']['warnings'] ?? array() ),
					(array) ( $cursor['warnings'] ?? array() )
				);
				if ( ! $this->has_warning_messages( $warning_source ) ) {
					$status = JobState::COMPLETED;
				}
			}
			$update = array(
				'cursor'       => $cursor,
				'step'         => $step,
				'progress'     => isset( $result['progress'] ) ? $result['progress'] : $current['progress'],
				'heartbeat_at' => current_time( 'mysql', true ),
				'available_at' => current_time( 'mysql', true ),
				'error_code'   => '',
				'error'        => isset( $result['error'] ) ? $result['error'] : null,
			);
			foreach ( array( 'processed_items', 'total_items', 'processed_bytes', 'total_bytes' ) as $counter ) {
				if ( isset( $result[ $counter ] ) ) {
					$update[ $counter ] = $result[ $counter ];
				}
			}
			if ( array_key_exists( 'result', $result ) ) {
				$update['result'] = $result['result'];
			}

			$latest = $this->repo->find( $job_id );
			if ( $latest && ( $latest['cancel_requested'] || $latest['pause_requested'] ) ) {
				if ( ! $this->repo->transition( $job_id, $latest['status'], $update, $owner ) ) {
					return $this->repo->find( $job_id ) ?: $job;
				}
				$latest = $this->repo->find( $job_id );
				if ( ! $latest ) {
					return $job;
				}
				if ( $latest['cancel_requested'] ) {
					return $this->finish_cancel( $latest, $owner );
				}
				return $this->finish_pause( $latest, $owner );
			}

			/* Most slices stay in the same state. Commit those with a single
			 * lease-guarded checkpoint instead of a full transition lookup/event.
			 * Besides reducing shared-hosting DB traffic, this prevents a stale
			 * worker from publishing its cursor after the lease was reassigned. */
			if ( $status === $current['status'] ) {
				if ( ! $this->repo->checkpoint( $job_id, $owner, $current['status'], $update, self::LEASE_TTL ) ) {
					$latest = $this->repo->find( $job_id );
					if ( $latest && $latest['cancel_requested'] ) {
						return $this->finish_cancel( $latest, $owner );
					}
					if ( $latest && $latest['pause_requested'] ) {
						return $this->finish_pause( $latest, $owner );
					}
					return $latest ?: $job;
				}
			} elseif ( ! $this->repo->transition( $job_id, $status, $update, $owner ) ) {
				return $this->repo->find( $job_id ) ?: $job;
			}
			$fresh = $this->repo->find( $job_id );
			$this->repo->events()->append(
				$job_id,
				'slice_completed',
				array( 'status' => $status, 'step' => $step, 'attempt' => $fresh ? $fresh['attempt'] : 0, 'runner' => $runner )
			);

			if ( $fresh && JobState::is_terminal( $fresh['status'] ) ) {
				if ( 'restore' === $fresh['type'] && in_array( $fresh['status'], array( JobState::COMPLETED, JobState::COMPLETED_WITH_WARNINGS ), true ) && 'commit_cleanup_pending' === (string) ( $fresh['cursor']['phase'] ?? '' ) ) {
					$fresh = $this->finalize_restore_commit( $job_id ) ?: $fresh;
				}
				$this->notify_terminal( $fresh );
			} elseif ( $fresh ) {
				$this->dispatch( $job_id, time() + 1 );
			}
			return $fresh ?: $job;
		} catch ( \Throwable $error ) {
			return $this->handle_failure( $job_id, $owner, $error );
		} finally {
			$this->repo->release_lease( $job_id, $owner );
		}
	}

	/** @param int $job_id Job ID. @return array<string, mixed>|null */
	public function pause( $job_id ) {
		$job = $this->repo->find( $job_id );
		if ( ! $job || JobState::is_terminal( $job['status'] ) || JobState::PAUSED === $job['status'] ) {
			return $job;
		}
		$this->repo->request_pause( $job_id );
		$this->repo->events()->append( $job_id, 'pause_requested', array( 'status' => $job['status'], 'step' => $job['step'] ) );
		if ( empty( $job['lease_owner'] ) || $this->repo->lease_expired( $job ) ) {
			$owner = $this->worker_id();
			if ( $this->repo->acquire_lease( $job_id, $owner, self::LEASE_TTL, 'control', false ) ) {
				try {
					$fresh = $this->repo->find( $job_id );
					return $fresh ? $this->finish_pause( $fresh, $owner ) : $job;
				} finally {
					$this->repo->release_lease( $job_id, $owner );
				}
			}
		}
		return $this->repo->find( $job_id );
	}

	/** @param int $job_id Job ID. @return array<string, mixed>|null */
	public function resume( $job_id ) {
		$job = $this->repo->find( $job_id );
		if ( ! $job || ( JobState::is_terminal( $job['status'] ) && JobState::FAILED !== $job['status'] ) ) {
			return $job;
		}
		$this->repo->clear_pause( $job_id );
		$this->repo->clear_cancel( $job_id );
		if ( in_array( $job['status'], array( JobState::PAUSED, JobState::PAUSING, JobState::RETRYING, JobState::FAILED ), true ) ) {
			$changed = $this->repo->transition(
				$job_id,
				JobState::QUEUED,
				array(
					'available_at'  => current_time( 'mysql', true ),
					'attempt_count' => JobState::FAILED === $job['status'] ? 0 : $job['attempt'],
					'error_code'    => '',
					'error'         => null,
				)
			);
			if ( ! $changed ) {
				return $this->repo->find( $job_id ) ?: $job;
			}
		}
		$fresh = $this->repo->find( $job_id );
		if ( ! $fresh ) {
			return $job;
		}
		$this->repo->events()->append( $job_id, 'resumed', array( 'status' => $fresh['status'], 'step' => $fresh['step'] ) );
		$this->dispatch( $job_id, time() + 1 );
		return $fresh;
	}

	/** @param int $job_id Job ID. @return array<string, mixed>|null */
	public function cancel( $job_id ) {
		$job = $this->repo->find( $job_id );
		if ( ! $job || JobState::is_terminal( $job['status'] ) ) {
			return $job;
		}
		$this->repo->request_cancel( $job_id );
		$this->repo->events()->append( $job_id, 'cancel_requested', array( 'status' => $job['status'], 'step' => $job['step'] ) );
		if ( empty( $job['lease_owner'] ) || $this->repo->lease_expired( $job ) ) {
			$owner = $this->worker_id();
			if ( $this->repo->acquire_lease( $job_id, $owner, self::LEASE_TTL, 'control', false, true ) ) {
				try {
					$fresh = $this->repo->find( $job_id );
					return $fresh ? $this->finish_cancel( $fresh, $owner ) : $job;
				} finally {
					$this->repo->release_lease( $job_id, $owner );
				}
			}
		}
		return $this->repo->find( $job_id );
	}

	/**
	 * Recover dead workers without permanent ownership.
	 *
	 * @param int $limit Limit.
	 * @return int Number recovered.
	 */
	public function recover_stale_jobs( $limit = 20 ) {
		$recovered = 0;
		foreach ( $this->repo->stale( $limit ) as $candidate ) {
			$owner = $this->worker_id();
			if ( ! $this->repo->acquire_lease( $candidate['id'], $owner, self::LEASE_TTL, 'recovery' ) ) {
				continue;
			}

			try {
				$job = $this->repo->find( $candidate['id'] );
				if ( ! $job ) {
					continue;
				}
				if ( $job['cancel_requested'] ) {
					$this->finish_cancel( $job, $owner );
					++$recovered;
					continue;
				}
				if ( $job['pause_requested'] ) {
					$this->finish_pause( $job, $owner );
					++$recovered;
					continue;
				}

				$attempt = $job['attempt'] + 1;
				$target  = $attempt >= $job['max_attempts'] ? JobState::FAILED : JobState::RETRYING;
				$delay   = JobState::RETRYING === $target ? $this->retry->delay( $attempt ) : 0;
				$message = JobState::FAILED === $target
					? __( 'The worker stopped repeatedly and the retry limit was reached. Completed checkpoints were preserved.', 'nobash-backup' )
					: __( 'A worker stopped before finishing this step. Nobash Backup will resume from the last checkpoint.', 'nobash-backup' );
				$previous_recoveries = (int) ( $job['cursor']['worker_recovery_count'] ?? 0 );
				$recovery_cursor = $this->worker_recovery_cursor( (array) ( $job['cursor'] ?? array() ), (string) ( $job['type'] ?? '' ) );
				$recovery_cursor['worker_recovery_count'] = max( 1, $previous_recoveries + 1 );
				$changed = $this->repo->transition(
					$job['id'],
					$target,
					array(
						'cursor'        => $recovery_cursor,
						'attempt_count' => $attempt,
						'available_at'  => JobState::RETRYING === $target ? gmdate( 'Y-m-d H:i:s', time() + $delay ) : current_time( 'mysql', true ),
						'heartbeat_at'  => current_time( 'mysql', true ),
						'error_code'    => 'worker_terminated',
						'error'         => $message,
					),
					$owner
				);
				if ( ! $changed ) {
					continue;
				}

				$this->repo->events()->append(
					$job['id'],
					'stale_worker_recovered',
					array( 'status' => $target, 'step' => $job['step'], 'attempt' => $attempt, 'delay' => $delay )
				);
				if ( JobState::RETRYING === $target ) {
					$this->dispatch( $job['id'], time() + $delay );
				} else {
					$fresh = $this->repo->find( $job['id'] );
					if ( $fresh ) {
						$this->notify_terminal( $fresh );
					}
				}
				++$recovered;
			} finally {
				$this->repo->release_lease( $candidate['id'], $owner );
			}
		}
		return $recovered;
	}

	/**
	 * API-safe serializer. Internal cursors, leases and secrets are excluded.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return array<string, mixed>
	 */
	public function public_job( array $job ) {
		$cursor = array();
		foreach ( array( 'phase', 'profile', 'scope', 'name', 'slug', 'site_id', 'refresh_site_id', 'failure_phase', 'db_current_table', 'db_table_index', 'db_table_total', 'db_rows', 'url_table_idx', 'url_rows_processed' ) as $key ) {
			if ( isset( $job['cursor'][ $key ] ) && is_scalar( $job['cursor'][ $key ] ) ) {
				$cursor[ $key ] = $job['cursor'][ $key ];
			}
		}
		if ( isset( $job['cursor']['components'] ) && is_array( $job['cursor']['components'] ) ) {
			$cursor['components'] = array_values( array_intersect( array( 'database', 'files', 'uploads', 'plugins', 'themes' ), array_map( 'sanitize_key', $job['cursor']['components'] ) ) );
		}
		$public_result = $this->public_result( $job );
		$public_status = (string) $job['status'];
		if ( 'restore' === (string) $job['type'] && JobState::COMPLETED_WITH_WARNINGS === $public_status && ! $this->has_warning_messages( (array) ( $public_result['warnings'] ?? array() ) ) ) {
			$public_status = JobState::COMPLETED;
		}
		$out = array(
			'id' => $job['id'], 'operation_id' => $job['operation_id'], 'type' => $job['type'], 'status' => $public_status,
			'step' => $job['step'], 'progress' => $job['progress'], 'attempt' => $job['attempt'], 'max_attempts' => $job['max_attempts'],
			'available_at' => $job['available_at'], 'started' => $job['started'], 'completed' => $job['completed'], 'heartbeat' => $job['heartbeat'],
			'pause_requested' => $job['pause_requested'], 'cancel_requested' => $job['cancel_requested'],
			'processed_items' => $job['processed_items'], 'total_items' => $job['total_items'], 'processed_bytes' => $job['processed_bytes'], 'total_bytes' => $job['total_bytes'],
			'cursor' => $cursor, 'result' => $public_result, 'error_code' => $job['error_code'], 'error' => $job['error'],
			'restore_diagnostics' => JobError::restore_diagnostics( $job ),
		);
		return $out;
	}

	/**
	 * Filter result data by job type before returning it through REST.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return array<string,mixed>
	 */
	private function public_result( array $job ) {
		$result = isset( $job['result'] ) && is_array( $job['result'] ) ? $job['result'] : array();
		$keys   = array();
		switch ( $job['type'] ) {
			case 'backup':
				$keys = array( 'filename', 'sha256', 'size' );
				break;
			case 'restore':
				$keys = array( 'filename', 'rollback_completed', 'database_rolled_back', 'filesystem_rolled_back', 'safety_backup_preserved', 'failed_phase', 'database_strategy', 'database_tables', 'rollback_committed' );
				break;
			case 'remote_import':
				$keys = array( 'filename', 'sha256', 'size', 'status' );
				break;
			default:
				/**
				 * Filter public result keys for an add-on job type.
				 *
				 * @param array<int,string>   $keys Result keys.
				 * @param array<string,mixed> $job  Internal job.
				 */
				$keys = $this->container->get( AddonRegistry::class )->public_result_keys( $job['type'] );
				$keys = apply_filters( 'nobash_backup_public_job_result_keys', $keys, $job );
		}
		$out = array();
		foreach ( is_array( $keys ) ? $keys : array() as $key ) {
			if ( array_key_exists( $key, $result ) && ( is_scalar( $result[ $key ] ) || null === $result[ $key ] ) ) {
				$out[ $key ] = $result[ $key ];
			}
		}
		if ( 'restore' === (string) $job['type'] ) {
			$warnings = $this->warning_messages( (array) ( $result['warnings'] ?? array() ) );
			if ( ! empty( $warnings ) ) {
				$out['warnings'] = $warnings;
			}
		}
		return $out;
	}

	/**
	 * Return bounded, human-readable warning text.
	 *
	 * @param array<int,mixed> $warnings Raw warning values.
	 * @return array<int,string>
	 */
	private function warning_messages( array $warnings ) {
		$out = array();
		foreach ( $warnings as $warning ) {
			$message = '';
			if ( is_scalar( $warning ) ) {
				$message = (string) $warning;
			} elseif ( is_array( $warning ) ) {
				foreach ( array( 'message', 'warning', 'text', 'description' ) as $key ) {
					if ( isset( $warning[ $key ] ) && is_scalar( $warning[ $key ] ) ) {
						$message = (string) $warning[ $key ];
						break;
					}
				}
			}
			$message = sanitize_text_field( trim( $message ) );
			if ( '' !== $message ) {
				$out[ strtolower( $message ) ] = $message;
			}
			if ( count( $out ) >= 25 ) {
				break;
			}
		}
		return array_values( $out );
	}

	/** @param array<int,mixed> $warnings Raw warning values. @return bool */
	private function has_warning_messages( array $warnings ) {
		return ! empty( $this->warning_messages( $warnings ) );
	}

	/** Keep extension-supplied cursor metadata serializable and bounded.
	 * @param array<string,mixed> $value Input.
	 * @param int $depth Current depth.
	 * @return array<string,mixed>
	 */
	private function sanitize_cursor_array( array $value, $depth = 0, $limit = 250 ) {
		if ( $depth > 4 ) { return array(); }
		$limit = max( 1, min( 5000, (int) $limit ) );
		$out = array();
		foreach ( array_slice( $value, 0, $limit, true ) as $key => $item ) {
			$safe_key = is_int( $key ) ? $key : sanitize_key( (string) $key );
			if ( '' === (string) $safe_key && ! is_int( $key ) ) { continue; }
			if ( is_array( $item ) ) { $out[ $safe_key ] = $this->sanitize_cursor_array( $item, $depth + 1, $limit ); }
			elseif ( is_bool( $item ) || is_int( $item ) || is_float( $item ) || null === $item ) { $out[ $safe_key ] = $item; }
			elseif ( is_scalar( $item ) ) { $out[ $safe_key ] = substr( sanitize_text_field( (string) $item ), 0, 2048 ); }
		}
		return $out;
	}

	/** @param string $type Type. @param array<string,mixed> $cursor Cursor. @return array<string,mixed> */
	private function create_job( $type, array $cursor ) {
		$cursor = (array) apply_filters( 'nobash_backup_job_cursor_pre_create', $cursor, sanitize_key( (string) $type ) );
		$id = $this->repo->create( $type, $cursor );
		if ( $id < 1 ) {
			return array();
		}
		$job = $this->repo->find( $id ) ?: array();
		try {
			if ( $job ) { do_action( 'nobash_backup_job_created', $job ); }
		} catch ( \Throwable $e ) {
			$this->repo->transition( $id, JobState::CANCELLED, array( 'error_code' => 'extension_initialization_failed', 'error' => $e->getMessage(), 'step' => 'extension_initialization' ) );
			$cancelled = $this->repo->find( $id );
			if ( $cancelled ) { try { do_action( 'nobash_backup_job_terminal', $cancelled ); } catch ( \Throwable $ignored ) {} }
			throw new PermanentJobException( sprintf( /* translators: %s: Extension initialization error. */ esc_html__( 'A required protection extension could not initialize: %s', 'nobash-backup' ), esc_html( $e->getMessage() ) ) );
		}
		$this->repo->transition( $id, JobState::QUEUED, array( 'available_at' => current_time( 'mysql', true ) ) );
		$this->dispatch( $id, time() + 1 );
		return $this->repo->find( $id ) ?: $job;
	}

	/** @param array<string,mixed> $job Job. @return array<string,mixed>|\WP_Error */
	private function process_handler( array $job ) {
		$budget = $this->time_budget();
		/* A prior unclean exit is stronger evidence than the static server profile.
		 * Resume with shorter durable slices so PHP-FPM/proxy limits or a slow disk
		 * flush cannot repeat until the retry limit is exhausted. */
		if ( (int) ( $job['attempt'] ?? 0 ) > 0 ) {
			$job['cursor'] = $this->worker_recovery_cursor( (array) ( $job['cursor'] ?? array() ), (string) ( $job['type'] ?? '' ) );
			$budget = min( $budget, 10.0 );
		}
		if ( 'backup' === $job['type'] ) {
			return ( new BackupJob( new LocalStorage(), $this->repo ) )->process( $job, $budget );
		}
		if ( 'chain_restore' === $job['type'] ) {
			return ( new ChainRestoreJob( $this, new LocalStorage() ) )->process( $job, $budget );
		}
		if ( 'restore' === $job['type'] ) {
			return ( new RestoreJob( new LocalStorage(), $this->repo, $this ) )->process( $job, $budget );
		}
		if ( 'remote_import' === $job['type'] ) {
			return ( new RemoteImportJob() )->process( $job, $budget );
		}
		/** @var AddonRegistry $registry */
		$registry = $this->container->get( AddonRegistry::class );
		$custom   = $registry->get_job_type( $job['type'] );
		if ( ! $custom ) {
			throw new PermanentJobException( esc_html__( 'Unknown job type.', 'nobash-backup' ) );
		}
		return call_user_func( $custom, $job, $this );
	}

	/** Apply bounded self-tuning after an unclean worker exit. @return array<string,mixed> */
	private function worker_recovery_cursor( array $cursor, $type ) {
		$cursor['worker_safe_mode'] = true;
		$cursor['worker_recovery_count'] = max( 1, (int) ( $cursor['worker_recovery_count'] ?? 0 ) );
		if ( 'backup' === (string) $type && 'files_pack' === (string) ( $cursor['phase'] ?? '' ) ) {
			$cursor['adaptive_bytes'] = min( 67108864, max( 33554432, (int) ( $cursor['adaptive_bytes'] ?? 67108864 ) ) );
			$cursor['adaptive_files'] = min( 250, max( 50, (int) ( $cursor['adaptive_files'] ?? 250 ) ) );
			$cursor['adaptive_chunk_bytes'] = min( 8388608, max( 4194304, (int) ( $cursor['adaptive_chunk_bytes'] ?? 8388608 ) ) );
		}
		return $cursor;
	}


	/**
	 * Choose a safe request budget from the host execution limit.
	 *
	 * @return float
	 */
	private function time_budget() {
		$settings = get_option( 'nobash_backup_settings', array() );
		$requested = isset( $settings['job_slice_seconds'] ) ? max( 5.0, min( 120.0, (float) $settings['job_slice_seconds'] ) ) : self::TIME_BUDGET;
		$maximum = (int) ini_get( 'max_execution_time' );
		$requested = HostingEnvironment::recommended_time_budget( $requested, $maximum );
		return max( 2.0, (float) apply_filters( 'nobash_backup_job_time_budget', $requested, $maximum, $settings ) );
	}

	/** @param mixed $status Handler status. @return string */
	private function normalize_handler_status( $status ) {
		$status = sanitize_key( (string) $status );
		if ( in_array( $status, array( 'pending', 'running', 'queued' ), true ) ) {
			return JobState::RUNNING;
		}
		if ( 'completed' === $status ) {
			return JobState::COMPLETED;
		}
		if ( 'completed_with_warnings' === $status ) {
			return JobState::COMPLETED_WITH_WARNINGS;
		}
		if ( 'verifying' === $status ) {
			return JobState::VERIFYING;
		}
		if ( 'cancelled' === $status ) {
			return JobState::CANCELLED;
		}
		if ( 'failed' === $status ) {
			return JobState::FAILED;
		}
		throw new PermanentJobException( esc_html__( 'Job handler returned an invalid state.', 'nobash-backup' ) );
	}

	/** @param int $job_id Job ID. @param string $owner Owner. @param \Throwable $error Error. @return array<string,mixed> */
	private function handle_failure( $job_id, $owner, \Throwable $error ) {
		$job = $this->repo->find( $job_id );
		if ( ! $job ) {
			throw $error;
		}
		$safe    = JobError::from_exception( $error );
		$attempt = $job['attempt'] + 1;
		$retry   = $this->retry->is_retryable( $error ) && $attempt < $job['max_attempts'];
		$target  = $retry ? JobState::RETRYING : JobState::FAILED;
		$delay   = $retry ? $this->retry->delay( $attempt ) : 0;
		$changed = $this->repo->transition(
			$job_id,
			$target,
			array(
				'attempt_count' => $attempt,
				'available_at'  => $retry ? gmdate( 'Y-m-d H:i:s', time() + $delay ) : current_time( 'mysql', true ),
				'heartbeat_at'  => current_time( 'mysql', true ),
				'error_code'    => $safe['code'],
				'error'         => $safe['message'],
			),
			$owner
		);
		if ( ! $changed ) {
			return $this->repo->find( $job_id ) ?: $job;
		}
		$this->repo->events()->append(
			$job_id,
			$retry ? 'retry_scheduled' : 'failed',
			array( 'status' => $target, 'step' => $job['step'], 'attempt' => $attempt, 'message' => $safe['message'], 'delay' => $delay )
		);
		$fresh = $this->repo->find( $job_id );
		if ( $retry ) {
			$this->dispatch( $job_id, time() + $delay );
		} elseif ( $fresh ) {
			$this->notify_terminal( $fresh );
		}
		return $fresh ?: $job;
	}

	/** @param array<string,mixed> $job Job. @param string $owner Owner. @return array<string,mixed> */
	private function finish_pause( array $job, $owner ) {
		if ( JobState::PAUSING !== $job['status'] && ! $this->repo->transition( $job['id'], JobState::PAUSING, array(), $owner ) ) {
			return $this->repo->find( $job['id'] ) ?: $job;
		}
		if ( ! $this->repo->transition( $job['id'], JobState::PAUSED, array( 'heartbeat_at' => current_time( 'mysql', true ) ), $owner ) ) {
			return $this->repo->find( $job['id'] ) ?: $job;
		}
		$this->repo->events()->append( $job['id'], 'paused', array( 'status' => JobState::PAUSED, 'step' => $job['step'] ) );
		return $this->repo->find( $job['id'] ) ?: $job;
	}

	/** @param array<string,mixed> $job Job. @param string $owner Owner. @return array<string,mixed> */
	private function finish_cancel( array $job, $owner ) {
		if ( 'chain_restore' === $job['type'] ) {
			$cursor = is_array( $job['cursor'] ) ? $job['cursor'] : array();
			$has_safety = ! empty( $cursor['safety_backup']['filename'] );
			$may_have_changed = $has_safety && (
				! empty( $cursor['child_restore_id'] ) ||
				! empty( $cursor['rollback_restore_id'] ) ||
				(int) ( $cursor['chain_index'] ?? 0 ) > 0 ||
				in_array( (string) ( $cursor['phase'] ?? '' ), array( 'apply', 'rollback_prepare', 'rollback_apply', 'extension_finalize', 'finalize' ), true )
			);
			if ( $may_have_changed ) {
				$cursor['chain_failure'] = __( 'Backup-chain restore cancelled by an administrator. The pre-restore site is being recovered before the operation closes.', 'nobash-backup' );
				$cursor['chain_cancelled'] = true;
				$cursor['chain_recovery'] = true;
				$cursor['phase'] = ! empty( $cursor['child_restore_id'] ) ? 'cancel_child' : 'rollback_prepare';
				$this->repo->clear_cancel( $job['id'] );
				$target = JobState::PAUSED === $job['status'] ? JobState::QUEUED : JobState::RUNNING;
				if ( ! $this->repo->transition( $job['id'], $target, array( 'cursor' => $cursor, 'step' => $cursor['phase'], 'cancel_requested' => 0, 'available_at' => current_time( 'mysql', true ) ), $owner ) ) {
					return $this->repo->find( $job['id'] ) ?: $job;
				}
				$this->repo->events()->append( $job['id'], 'chain_restore_cancel_recovery_started', array( 'status' => $target, 'step' => $cursor['phase'] ) );
				$this->dispatch( $job['id'], time() + 1 );
				return $this->repo->find( $job['id'] ) ?: $job;
			}
		}
		if ( 'restore' === $job['type'] ) {
			$cursor = is_array( $job['cursor'] ) ? $job['cursor'] : array();
			$destructive = ! empty( $cursor['database_published'] ) || ! empty( $cursor['filesystem_started'] );
			if ( $destructive ) {
				$cursor['failure_message'] = __( 'Restore cancelled by an administrator. The previous site state is being restored.', 'nobash-backup' );
				$cursor['failure_phase'] = sanitize_key( (string) ( $cursor['phase'] ?? 'unknown' ) );
				$cursor['rollback_offset'] = 0;
				unset( $cursor['rollback_index_offset'] );
				$cursor['phase'] = ! empty( $cursor['filesystem_started'] ) ? 'rollback_files' : 'rollback_db';
				$this->repo->clear_cancel( $job['id'] );
				$target = JobState::PAUSED === $job['status'] ? JobState::QUEUED : JobState::RUNNING;
				if ( ! $this->repo->transition( $job['id'], $target, array( 'cursor' => $cursor, 'step' => $cursor['phase'], 'cancel_requested' => 0, 'available_at' => current_time( 'mysql', true ) ), $owner ) ) {
					return $this->repo->find( $job['id'] ) ?: $job;
				}
				\Nobash\Backup\Restore\EmergencyRecovery::mark( $job['id'], $cursor, 'recovering' );
				$this->repo->events()->append( $job['id'], 'restore_cancel_rollback_started', array( 'status' => $target, 'step' => $cursor['phase'] ) );
				$this->dispatch( $job['id'], time() + 1 );
				return $this->repo->find( $job['id'] ) ?: $job;
			}
		}
		if ( JobState::CLEANING !== $job['status'] && ! $this->repo->transition( $job['id'], JobState::CLEANING, array(), $owner ) ) {
			return $this->repo->find( $job['id'] ) ?: $job;
		}
		( new LocalStorage() )->cleanup_job_temp( $job['id'] );
		$cursor = $job['cursor'];
		if ( ! $this->repo->transition(
			$job['id'],
			JobState::CANCELLED,
			array(
				'heartbeat_at' => current_time( 'mysql', true ),
				'cursor'       => $cursor,
			),
			$owner
		) ) {
			return $this->repo->find( $job['id'] ) ?: $job;
		}
		$this->repo->events()->append( $job['id'], 'cancelled', array( 'status' => JobState::CANCELLED, 'step' => $job['step'] ) );
		$fresh = $this->repo->find( $job['id'] );
		if ( $fresh ) {
			$this->notify_terminal( $fresh );
		}
		return $fresh ?: $job;
	}

	/** @param array<string,mixed> $job Job. @return bool */
	private function is_due( array $job ) {
		return empty( $job['available_at'] ) || strtotime( (string) $job['available_at'] . ' UTC' ) <= time();
	}

	/** @return string */
	private function worker_id() {
		return substr( hash( 'sha256', wp_generate_password( 32, false, false ) . '|' . microtime( true ) ), 0, 48 );
	}

	/** @param int $job_id Job ID. @param int $timestamp Unix timestamp. @return void */
	private function dispatch( $job_id, $timestamp ) {
		$timestamp = max( time() + 1, (int) $timestamp );
		$args      = array( absint( $job_id ) );
		if ( function_exists( 'as_schedule_single_action' ) && ( ! function_exists( 'did_action' ) || did_action( 'action_scheduler_init' ) ) ) {
			if ( ! function_exists( 'as_next_scheduled_action' ) || ! as_next_scheduled_action( Scheduler::JOB_HOOK, $args, 'nobash-backup' ) ) {
				as_schedule_single_action( $timestamp, Scheduler::JOB_HOOK, $args, 'nobash-backup', true );
			}
			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( Scheduler::JOB_HOOK, $args ) ) {
			wp_schedule_single_event( $timestamp, Scheduler::JOB_HOOK, $args, true );
		}
	}

	/** @param array<string,mixed> $job Job. @return void */
	private function notify_terminal( array $job ) {
		if ( 'backup' === $job['type'] ) {
			/* A terminal failure/cancel can occur while a MyISAM/MEMORY shadow is
			 * still present. Remove only manager-owned snapshot tables here; normal
			 * successful jobs already cleaned them before packaging. */
			\Nobash\Backup\Database\BackupSnapshotManager::cleanup_cursor( is_array( $job['cursor'] ?? null ) ? $job['cursor'] : array() );
			do_action( 'nobash_backup_after_backup', $job );
			$schedule_id = absint( $job['cursor']['schedule_id'] ?? 0 );
			if ( $schedule_id > 0 ) {
				( new \Nobash\Backup\Schedule\ScheduleRepository() )->mark_status(
					$schedule_id,
					(string) $job['status'],
					(string) ( $job['error'] ?? '' )
				);
			}
		}
		( new Notifier() )->notify_job( $job );
		do_action( 'nobash_backup_job_terminal', $job );
	}
}

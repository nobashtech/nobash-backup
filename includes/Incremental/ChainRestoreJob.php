<?php
/**
 * Transactional backup-chain restore orchestrator.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Incremental;

use Nobash\Backup\Jobs\JobEngine;
use Nobash\Backup\Jobs\JobState;
use Nobash\Backup\Storage\LocalStorage;

defined( 'ABSPATH' ) || exit;

/**
 * Apply a full base plus incremental/differential layers under one parent
 * safety boundary. Registered extension providers may finalize once after every
 * layer has been published.
 */
class ChainRestoreJob {

	/** @var JobEngine */
	private $engine;

	/** @var LocalStorage */
	private $storage;

	public function __construct( JobEngine $engine, LocalStorage $storage ) {
		$this->engine  = $engine;
		$this->storage = $storage;
	}

	/**
	 * Process one bounded chain-restore slice.
	 *
	 * @param array<string,mixed> $job    Parent job.
	 * @param float               $budget Slice budget.
	 * @return array<string,mixed>
	 */
	public function process( array $job, $budget ) {
		$cursor = is_array( $job['cursor'] ?? null ) ? $job['cursor'] : array();
		$phase  = (string) ( $cursor['phase'] ?? 'init' );

		if ( 'init' === $phase ) {
			$plan = ( new ChainRepository() )->restore_plan( (string) ( $cursor['restore_point_id'] ?? '' ), $this->storage );
			$cursor['chain_plan'] = array_map(
				static function ( $point ) {
					return array(
						'point_id' => (string) $point['point_id'],
						'type'     => (string) $point['point_type'],
						'filename' => basename( (string) $point['filename'] ),
					);
				},
				$plan
			);
			$cursor['chain_index']  = 0;
			$cursor['layer_results'] = array();
			$cursor['phase']        = 'safety_backup';
			return $this->running( $cursor, 2 );
		}

		if ( 'safety_backup' === $phase ) {
			if ( empty( $cursor['safety_job_id'] ) ) {
				$child = $this->engine->create_backup( array( 'profile' => 'full', 'scope' => 'site' ) );
				$cursor['safety_job_id'] = (int) $child['id'];
				return $this->running( $cursor, 4 );
			}
			$child = $this->engine->run_slice( (int) $cursor['safety_job_id'], 'chain-restore-safety' );
			if ( in_array( $child['status'], array( JobState::FAILED, JobState::CANCELLED ), true ) ) {
				throw new \RuntimeException( esc_html__( 'Chain restore blocked because the safety backup failed.', 'nobash-backup' ) );
			}
			if ( ! JobState::is_terminal( $child['status'] ) ) {
				return $this->running( $cursor, 4 + (int) floor( (float) $child['progress'] * 0.10 ) );
			}
			$cursor['safety_backup'] = is_array( $child['result'] ?? null ) ? $child['result'] : array();
			$cursor['phase']         = 'apply';
			return $this->running( $cursor, 15 );
		}

		if ( 'apply' === $phase ) {
			$plan  = (array) ( $cursor['chain_plan'] ?? array() );
			$index = (int) ( $cursor['chain_index'] ?? 0 );
			$total = max( 1, count( $plan ) );

			if ( $index >= count( $plan ) ) {
				$cursor['phase'] = 'extension_finalize';
				return $this->running( $cursor, 98 );
			}

			if ( empty( $cursor['child_restore_id'] ) ) {
				$point = $plan[ $index ];
				$child = $this->engine->create_restore(
					array(
						'filename'                    => $point['filename'],
						'components'                  => $cursor['components'] ?? array( 'database', 'files' ),
						'skip_safety_backup'          => true,
						'search'                      => $cursor['search'] ?? '',
						'replace'                     => $cursor['replace'] ?? '',
						'target_home'                 => $cursor['target_home'] ?? '',
						'target_siteurl'              => $cursor['target_siteurl'] ?? '',
						'target_table_prefix'         => $cursor['target_table_prefix'] ?? '',
						'target_blog_id'              => $cursor['target_blog_id'] ?? 0,
						'file_path_map'               => $cursor['file_path_map'] ?? array(),
						'network_restore'             => ! empty( $cursor['network_restore'] ),
						'extension_parent_job_id'     => (int) $job['id'],
						'extension_defer_finalize'    => true,
					)
				);
				$cursor['child_restore_id'] = (int) $child['id'];
				return $this->running( $cursor, 15 + (int) floor( ( $index / $total ) * 80 ) );
			}

			$child = $this->engine->run_slice( (int) $cursor['child_restore_id'], 'chain-restore-layer' );
			if ( in_array( $child['status'], array( JobState::FAILED, JobState::CANCELLED ), true ) ) {
				$cursor['chain_failure'] = sprintf(
					/* translators: %d backup-chain layer number. */
					__( 'Backup-chain layer %d failed. The pre-restore site is being recovered.', 'nobash-backup' ),
					$index + 1
				);
				$cursor['failed_child_result'] = is_array( $child['result'] ?? null ) ? $child['result'] : array();
				unset( $cursor['child_restore_id'] );
				$cursor['phase'] = 'rollback_prepare';
				return $this->running( $cursor, 96 );
			}
			if ( ! JobState::is_terminal( $child['status'] ) ) {
				return $this->running( $cursor, 15 + (int) floor( ( ( $index + ( (float) $child['progress'] / 100 ) ) / $total ) * 80 ) );
			}

			$cursor['layer_results'][] = is_array( $child['result'] ?? null ) ? $child['result'] : array();
			$cursor['chain_index']      = $index + 1;
			unset( $cursor['child_restore_id'] );
			return $this->running( $cursor, 15 + (int) floor( ( $cursor['chain_index'] / $total ) * 80 ) );
		}

		if ( 'cancel_child' === $phase ) {
			$child_id = (int) ( $cursor['child_restore_id'] ?? 0 );
			if ( $child_id > 0 ) {
				$child = $this->engine->cancel( $child_id );
				if ( $child && ! JobState::is_terminal( (string) $child['status'] ) ) {
					$child = $this->engine->run_slice( $child_id, 'chain-restore-cancel-child' );
				}
				if ( $child && ! JobState::is_terminal( (string) $child['status'] ) ) {
					return $this->running( $cursor, 96 );
				}
			}
			unset( $cursor['child_restore_id'] );
			$cursor['phase'] = 'rollback_prepare';
			return $this->running( $cursor, 96 );
		}

		if ( 'rollback_prepare' === $phase ) {
			$safety = is_array( $cursor['safety_backup'] ?? null ) ? $cursor['safety_backup'] : array();
			$name   = basename( (string) ( $safety['filename'] ?? '' ) );
			if ( '' === $name || null === $this->storage->resolve_archive( $name ) ) {
				return $this->terminal_failure(
					$cursor,
					__( 'Chain restore failed and its safety archive is unavailable. Manual emergency recovery is required.', 'nobash-backup' ),
					false
				);
			}
			if ( empty( $cursor['rollback_restore_id'] ) ) {
				$child = $this->engine->create_restore(
					array(
						'filename'                 => $name,
						'components'               => array( 'database', 'files' ),
						'skip_safety_backup'       => true,
						'extension_parent_job_id'  => (int) $job['id'],
						'extension_defer_finalize' => true,
					)
				);
				$cursor['rollback_restore_id'] = (int) $child['id'];
			}
			$cursor['chain_recovery'] = true;
			$cursor['phase']          = 'rollback_apply';
			return $this->running( $cursor, 97 );
		}

		if ( 'rollback_apply' === $phase ) {
			$child = $this->engine->run_slice( (int) $cursor['rollback_restore_id'], 'chain-restore-parent-rollback' );
			if ( in_array( $child['status'], array( JobState::FAILED, JobState::CANCELLED ), true ) ) {
				return $this->terminal_failure(
					$cursor,
					__( 'The backup-chain restore failed and automatic restoration of the safety archive also failed. Manual emergency recovery is required.', 'nobash-backup' ),
					false
				);
			}
			if ( ! JobState::is_terminal( $child['status'] ) ) {
				return $this->running( $cursor, 97 );
			}
			$cursor['rollback_result'] = is_array( $child['result'] ?? null ) ? $child['result'] : array();
			$cursor['phase']           = 'extension_finalize';
			return $this->running( $cursor, 98 );
		}

		if ( 'extension_finalize' === $phase ) {
			$default = array( 'done' => true, 'state' => array(), 'message' => '' );
			$extension = apply_filters( 'nobash_backup_chain_restore_extension_slice', $default, $cursor, $job, $budget );
			if ( ! is_array( $extension ) || empty( $extension['done'] ) ) {
				$cursor['extension_state'] = is_array( $extension['state'] ?? null ) ? $extension['state'] : array();
				$cursor['extension_message'] = sanitize_text_field( (string) ( $extension['message'] ?? '' ) );
				return $this->running( $cursor, 99 );
			}
			$cursor['extension_state'] = is_array( $extension['state'] ?? null ) ? $extension['state'] : array();
			$cursor['phase']           = 'finalize';
			return $this->running( $cursor, 99 );
		}

		if ( 'finalize' === $phase ) {
			$result = array(
				'restore_point_id'     => (string) ( $cursor['restore_point_id'] ?? '' ),
				'layers_planned'       => count( (array) ( $cursor['chain_plan'] ?? array() ) ),
				'layers_applied'       => (int) ( $cursor['chain_index'] ?? 0 ),
				'safety_backup'        => $cursor['safety_backup'] ?? array(),
				'layer_results'        => $cursor['layer_results'] ?? array(),
				'extension_state'      => $cursor['extension_state'] ?? array(),
				'automatic_recovery'   => ! empty( $cursor['chain_recovery'] ),
				'rollback_result'      => $cursor['rollback_result'] ?? array(),
			);
			if ( ! empty( $cursor['chain_failure'] ) ) {
				$result['failure'] = (string) $cursor['chain_failure'];
				return array(
					'cursor'   => $cursor,
					'progress' => 100,
					'status'   => ! empty( $cursor['chain_cancelled'] ) ? 'cancelled' : 'failed',
					'error'    => (string) $cursor['chain_failure'],
					'result'   => $result,
				);
			}
			return array( 'cursor' => $cursor, 'progress' => 100, 'status' => 'completed', 'result' => $result );
		}

		throw new \RuntimeException( esc_html__( 'Unknown chain restore phase.', 'nobash-backup' ) );
	}

	/** @return array<string,mixed> */
	private function running( array $cursor, $progress ) {
		return array(
			'cursor'   => $cursor,
			'progress' => max( 0, min( 100, (int) $progress ) ),
			'status'   => 'running',
		);
	}

	/** @return array<string,mixed> */
	private function terminal_failure( array $cursor, $message, $recovered ) {
		$cursor['phase'] = 'finalize';
		return array(
			'cursor'   => $cursor,
			'progress' => 100,
			'status'   => 'failed',
			'error'    => (string) $message,
			'result'   => array(
				'restore_point_id'   => (string) ( $cursor['restore_point_id'] ?? '' ),
				'layers_applied'     => (int) ( $cursor['chain_index'] ?? 0 ),
				'automatic_recovery' => (bool) $recovered,
				'failure'            => (string) $message,
			),
		);
	}
}

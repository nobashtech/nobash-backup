<?php
/**
 * WP-CLI commands.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Cli;

use Nobash\Backup\Plugin;
use Nobash\Backup\Jobs\JobEngine;
use Nobash\Backup\Storage\LocalStorage;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Class Commands
 */
class Commands {

	/**
	 * Register WP-CLI commands.
	 *
	 * @return void
	 */
	public static function register() {
		WP_CLI::add_command( 'nobash-backup', __CLASS__ );
	}

	/**
	 * Create a backup.
	 *
	 * ## OPTIONS
	 *
	 * [--profile=<profile>]
	 * : Backup profile: full, database, files, uploads, plugins, themes.
	 * ---
	 * default: full
	 * ---
	 *
	 * [--scope=<scope>]
	 * : Multisite scope: site or network.
	 * ---
	 * default: site
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp nobash-backup backup
	 *     wp nobash-backup backup --profile=database
	 *     wp nobash-backup backup --scope=network
	 *
	 * @param array<int, string>   $args       Args.
	 * @param array<string, mixed> $assoc_args Assoc.
	 * @return void
	 */
	public function backup( $args, $assoc_args ) {
		$profile = isset( $assoc_args['profile'] ) ? $assoc_args['profile'] : 'full';
		$scope   = isset( $assoc_args['scope'] ) ? $assoc_args['scope'] : 'site';
		/** @var JobEngine $engine */
		$engine = Plugin::instance()->container()->get( JobEngine::class );
		$job    = $engine->create_backup(
			array(
				'profile' => $profile,
				'scope'   => $scope,
			)
		);
		WP_CLI::log( sprintf( 'Created backup job #%d', $job['id'] ) );

		$job = $this->run_to_completion( $engine, $job );

		if ( in_array( $job['status'], array( 'completed', 'completed_with_warnings' ), true ) ) {
			WP_CLI::success( isset( $job['result']['filename'] ) ? $job['result']['filename'] : 'Backup completed.' );
		} else {
			WP_CLI::error( $job['error'] ? $job['error'] : 'Backup failed.' );
		}
	}

	/**
	 * List local backups.
	 *
	 * @param array<int, string>   $args       Args.
	 * @param array<string, mixed> $assoc_args Assoc.
	 * @return void
	 */
	public function list( $args, $assoc_args ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.listFound
		$storage = new LocalStorage();
		$items   = $storage->list_archives();
		if ( empty( $items ) ) {
			WP_CLI::log( 'No backups found.' );
			return;
		}
		$table = array();
		foreach ( $items as $item ) {
			$table[] = array(
				'filename' => $item['filename'],
				'size'     => size_format( $item['size'] ),
				'modified' => gmdate( 'Y-m-d H:i:s', $item['modified'] ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $table, array( 'filename', 'size', 'modified' ) );
	}

	/**
	 * Restore a backup archive.
	 *
	 * ## OPTIONS
	 *
	 * <filename>
	 * : Archive filename in local storage.
	 *
	 * [--search=<url>]
	 * : URL to search (defaults to archive home).
	 *
	 * [--replace=<url>]
	 * : Replacement URL (defaults to current home).
	 *
	 * @param array<int, string>   $args       Args.
	 * @param array<string, mixed> $assoc_args Assoc.
	 * @return void
	 */
	public function restore( $args, $assoc_args ) {
		$filename = $args[0];

		$preflight = new \Nobash\Backup\System\Preflight();
		$report    = $preflight->run(
			'import',
			array(
				'filename' => $filename,
			)
		);
		if ( empty( $report['can_proceed'] ) ) {
			foreach ( $report['checks'] as $check ) {
				if ( 'fail' === $check['status'] ) {
					WP_CLI::warning( $check['label'] . ': ' . $check['message'] );
				}
			}
			WP_CLI::error( 'Preflight checks failed.' );
		}

		WP_CLI::confirm( 'All current site data will be replaced by the backup data. Continue?' );

		/** @var JobEngine $engine */
		$engine = Plugin::instance()->container()->get( JobEngine::class );
		$job    = $engine->create_restore(
			array(
				'filename' => $filename,
				'search'   => isset( $assoc_args['search'] ) ? $assoc_args['search'] : '',
				'replace'  => isset( $assoc_args['replace'] ) ? $assoc_args['replace'] : home_url(),
			)
		);
		WP_CLI::log( sprintf( 'Created restore job #%d', $job['id'] ) );

		$job = $this->run_to_completion( $engine, $job );

		if ( in_array( $job['status'], array( 'completed', 'completed_with_warnings' ), true ) ) {
			WP_CLI::success( 'Restore completed.' );
		} else {
			WP_CLI::error( $job['error'] ? $job['error'] : 'Restore failed.' );
		}
	}
	/**
	 * Show a durable job state.
	 *
	 * ## OPTIONS
	 * <id>
	 * : Job ID.
	 *
	 * @param array<int, string>   $args Args.
	 * @param array<string, mixed> $assoc_args Assoc args.
	 * @return void
	 */
	public function job_status( $args, $assoc_args ) {
		unset( $assoc_args );
		/** @var JobEngine $engine */
		$engine = Plugin::instance()->container()->get( JobEngine::class );
		$job    = $engine->repository()->find( absint( $args[0] ) );
		if ( ! $job ) {
			WP_CLI::error( 'Job not found.' );
		}
		WP_CLI\Utils\format_items( 'table', array( $engine->public_job( $job ) ), array( 'id', 'operation_id', 'type', 'status', 'step', 'progress', 'attempt', 'max_attempts', 'heartbeat', 'error' ) );
	}

	/** @param array<int,string> $args Args. @param array<string,mixed> $assoc_args Assoc. @return void */
	public function job_pause( $args, $assoc_args ) {
		unset( $assoc_args );
		$this->change_job_state( 'pause', absint( $args[0] ) );
	}

	/** @param array<int,string> $args Args. @param array<string,mixed> $assoc_args Assoc. @return void */
	public function job_resume( $args, $assoc_args ) {
		unset( $assoc_args );
		$this->change_job_state( 'resume', absint( $args[0] ) );
	}

	/** @param array<int,string> $args Args. @param array<string,mixed> $assoc_args Assoc. @return void */
	public function job_cancel( $args, $assoc_args ) {
		unset( $assoc_args );
		$this->change_job_state( 'cancel', absint( $args[0] ) );
	}

	/**
	 * Run a job through the same slices used by browser and cron runners.
	 *
	 * @param JobEngine           $engine Engine.
	 * @param array<string,mixed> $job Job.
	 * @return array<string,mixed>
	 */
	private function run_to_completion( JobEngine $engine, array $job ) {
		while ( ! \Nobash\Backup\Jobs\JobState::is_terminal( $job['status'] ) && 'paused' !== $job['status'] ) {
			if ( 'retrying' === $job['status'] && ! empty( $job['available_at'] ) ) {
				$wait = max( 1, min( 30, strtotime( $job['available_at'] . ' UTC' ) - time() ) );
				sleep( $wait );
			}
			$job = $engine->run_slice( (int) $job['id'], 'wp-cli' );
			WP_CLI::log( sprintf( 'Progress: %d%% (%s, step: %s)', $job['progress'], $job['status'], $job['step'] ) );
		}
		return $job;
	}

	/** @param string $action Action. @param int $job_id Job ID. @return void */
	private function change_job_state( $action, $job_id ) {
		/** @var JobEngine $engine */
		$engine = Plugin::instance()->container()->get( JobEngine::class );
		$job    = call_user_func( array( $engine, $action ), $job_id );
		if ( ! $job ) {
			WP_CLI::error( 'Job not found.' );
		}
		WP_CLI::success( sprintf( 'Job #%d is %s.', $job_id, $job['status'] ) );
	}

}

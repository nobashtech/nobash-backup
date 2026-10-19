<?php
/**
 * REST API controller.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Rest;

use Nobash\Backup\Archive\ArchiveFingerprint;
use Nobash\Backup\Addon\Registry as AddonRegistry;
use Nobash\Backup\Compat\Environment;
use Nobash\Backup\Compat\HostingEnvironment;
use Nobash\Backup\Container\Container;
use Nobash\Backup\Jobs\JobEngine;
use Nobash\Backup\Jobs\JobRepository;
use Nobash\Backup\Migration\TransferRepository;
use Nobash\Backup\Security\TransferToken;
use Nobash\Backup\Schedule\Scheduler;
use Nobash\Backup\Security\AuditLog;
use Nobash\Backup\Security\Capabilities;
use Nobash\Backup\Security\PasswordArchive;
use Nobash\Backup\Security\RateLimiter;
use Nobash\Backup\Security\RestoreToken;
use Nobash\Backup\Security\UrlGuard;
use Nobash\Backup\Restore\ArchiveInspector;
use Nobash\Backup\Restore\EmergencyRecovery;
use Nobash\Backup\Restore\RestorePlan;
use Nobash\Backup\Storage\LocalStorage;
use Nobash\Backup\Storage\StorageManager;
use Nobash\Backup\Storage\CredentialVault;
use Nobash\Backup\System\Preflight;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Class RestController
 */
class RestController {

	const NAMESPACE = 'nobash-backup/v1';

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
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/backups',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_backups' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/backups/(?P<filename>[a-zA-Z0-9._-]+\.nbh)',
			array(
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_backup' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/backups/(?P<filename>[a-zA-Z0-9._-]+\.nbh)/details',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'backup_details' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/restore-plan',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'restore_plan' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/restore-recovery',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'restore_recovery_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/restore-recovery/(?P<id>\d+)/resume',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resume_restore_recovery' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/restore-recovery/(?P<id>\d+)/reset',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reset_restore_recovery' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/restore-recovery/(?P<id>\d+)/delete',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_restore_recovery' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/backups/(?P<filename>[a-zA-Z0-9._-]+\.nbh)/download',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'download_backup' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_job' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/history',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'clear_job_history' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_job' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_job_history_item' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>\d+)/events',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_job_events' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>\d+)/step',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'step_job' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>\d+)/pause',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'pause_job' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>\d+)/resume',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resume_job' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel_job' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/jobs/(?P<id>\d+)/delete',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_job_record' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route( self::NAMESPACE, '/addons', array( 'methods'=>'GET', 'callback'=>array($this,'addons'), 'permission_callback'=>array($this,'can_manage') ) );
		register_rest_route( self::NAMESPACE, '/features', array( 'methods'=>'GET', 'callback'=>array($this,'features'), 'permission_callback'=>array($this,'can_manage') ) );
		register_rest_route( self::NAMESPACE, '/storage', array( 'methods'=>'GET', 'callback'=>array($this,'storage_providers'), 'permission_callback'=>array($this,'can_manage') ) );
		register_rest_route( self::NAMESPACE, '/storage/(?P<provider>[a-z0-9_-]+)/test', array( 'methods'=>'POST', 'callback'=>array($this,'test_storage'), 'permission_callback'=>array($this,'can_manage') ) );
		register_rest_route( self::NAMESPACE, '/storage/(?P<provider>[a-z0-9_-]+)/credentials', array( 'methods'=>'POST', 'callback'=>array($this,'save_storage_credentials'), 'permission_callback'=>array($this,'can_manage') ) );

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/upload',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_backup' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/transfers',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_transfer' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/transfers/(?P<id>[a-f0-9]{32})',
			array(
				array( 'methods'=>'GET', 'callback'=>array($this,'get_transfer'), 'permission_callback'=>array($this,'can_manage') ),
				array( 'methods'=>'PUT', 'callback'=>array($this,'upload_transfer_chunk'), 'permission_callback'=>array($this,'can_transfer') ),
				array( 'methods'=>'DELETE', 'callback'=>array($this,'cancel_transfer'), 'permission_callback'=>array($this,'can_manage') ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/transfers/(?P<id>[a-f0-9]{32})/finalize',
			array(
				'methods'=>'POST','callback'=>array($this,'finalize_transfer'),'permission_callback'=>array($this,'can_finalize_transfer')
			)
		);






		register_rest_route(
			self::NAMESPACE,
			'/import-url',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_from_url' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'url' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
					),
					'sha256' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/preflight',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'preflight' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'context'  => array(
						'type'              => 'string',
						'enum'              => array( 'export', 'import' ),
						'default'           => 'export',
						'sanitize_callback' => 'sanitize_key',
					),
					'filename' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_file_name',
					),
				),
			)
		);


		register_rest_route(
			self::NAMESPACE,
			'/reset',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reset_data' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);


		register_rest_route(
			self::NAMESPACE,
			'/audit',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_audit' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'clear_audit' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);


		register_rest_route( self::NAMESPACE, '/profiles', array(
			array( 'methods'=>'GET', 'callback'=>array($this,'list_profiles'), 'permission_callback'=>array($this,'can_manage') ),
			array( 'methods'=>'POST', 'callback'=>array($this,'save_profile'), 'permission_callback'=>array($this,'can_manage') ),
		) );
		register_rest_route( self::NAMESPACE, '/profiles/(?P<id>\d+)', array( 'methods'=>'DELETE', 'callback'=>array($this,'delete_profile'), 'permission_callback'=>array($this,'can_manage') ) );
		register_rest_route( self::NAMESPACE, '/schedules', array(
			array( 'methods'=>'GET', 'callback'=>array($this,'list_schedules'), 'permission_callback'=>array($this,'can_manage') ),
			array( 'methods'=>'POST', 'callback'=>array($this,'save_schedule'), 'permission_callback'=>array($this,'can_manage') ),
		) );
		register_rest_route( self::NAMESPACE, '/schedules/(?P<id>\d+)', array( 'methods'=>'DELETE', 'callback'=>array($this,'delete_schedule'), 'permission_callback'=>array($this,'can_manage') ) );
		register_rest_route( self::NAMESPACE, '/schedules/(?P<id>\d+)/run', array( 'methods'=>'POST', 'callback'=>array($this,'run_schedule'), 'permission_callback'=>array($this,'can_manage') ) );
		register_rest_route( self::NAMESPACE, '/reports', array( 'methods'=>'GET', 'callback'=>array($this,'protection_report'), 'permission_callback'=>array($this,'can_manage') ) );
		register_rest_route( self::NAMESPACE, '/retention/preview', array( 'methods'=>'GET', 'callback'=>array($this,'retention_preview'), 'permission_callback'=>array($this,'can_manage') ) );
		register_rest_route( self::NAMESPACE, '/retention/run', array( 'methods'=>'POST', 'callback'=>array($this,'retention_run'), 'permission_callback'=>array($this,'can_manage') ) );
		register_rest_route( self::NAMESPACE, '/notifications/test', array( 'methods'=>'POST', 'callback'=>array($this,'test_notifications'), 'permission_callback'=>array($this,'can_manage') ) );
		register_rest_route( self::NAMESPACE, '/support', array( 'methods'=>'POST', 'callback'=>array($this,'send_support_request'), 'permission_callback'=>array($this,'can_manage') ) );
		register_rest_route( self::NAMESPACE, '/chains/(?P<chain_id>[a-f0-9-]{36})/verify', array( 'methods'=>'POST', 'callback'=>array($this,'verify_chain'), 'permission_callback'=>array($this,'can_manage') ) );
		register_rest_route( self::NAMESPACE, '/chains/(?P<chain_id>[a-f0-9-]{36})/repair', array( 'methods'=>'POST', 'callback'=>array($this,'repair_chain'), 'permission_callback'=>array($this,'can_manage') ) );
	}

	/**
	 * Permission callback.
	 *
	 * @param WP_REST_Request|null $request Current REST request.
	 * @return bool
	 */
	public function can_manage( $request = null ) {

		return Capabilities::current_user_can();
	}

	/**
	 * Preflight checks before export/import.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function preflight( WP_REST_Request $request ) {
		$context  = sanitize_key( (string) $request->get_param( 'context' ) );
		$filename = (string) $request->get_param( 'filename' );
		$runner   = new Preflight();
		$result   = $runner->run(
			$context ? $context : 'export',
			array(
				'filename' => $filename,
			)
		);

		if ( 'import' === $context && ! empty( $result['can_proceed'] ) && $filename ) {
			$result['restore_token'] = RestoreToken::issue( $filename );
		}


		return new WP_REST_Response( $result );
	}


	/**
	 * Status / system info.
	 *
	 * @return WP_REST_Response
	 */
	public function status() {
		$storage = new LocalStorage();
		$last    = get_option( 'nobash_backup_last_run', null );
		/** @var AddonRegistry $registry */
		$registry = $this->container->get( AddonRegistry::class );

		$drivers = array();
		foreach ( $registry->storage_drivers() as $id => $driver ) {
			$drivers[] = array(
				'id'    => $id,
				'label' => $driver->label(),
			);
		}

		return new WP_REST_Response(
			array(
				'version'     => NOBASH_BACKUP_VERSION,
				'last_backup' => $last,
				'storage'     => array(
					'path'      => LocalStorage::get_directory(),
					'free_space'=> $storage->free_space(),
					'archives'  => count( $storage->list_archives() ),
				),
				'system'      => array(
					'php'          => PHP_VERSION,
					'wp'           => get_bloginfo( 'version' ),
					'zip'          => class_exists( 'ZipArchive' ),
					'encryption'   => PasswordArchive::engine(),
					'db_engine'    => Environment::db_engine(),
					'db_version'   => Environment::db_version(),
					'server'       => Environment::server_family(),
					'memory_limit' => ini_get( 'memory_limit' ),
					'max_execution'=> ini_get( 'max_execution_time' ),
					'upload_max'   => ini_get( 'upload_max_filesize' ),
					'post_max'     => ini_get( 'post_max_size' ),
				),
				'encryption'  => PasswordArchive::status(),
				'compatibility' => Environment::report(),
				'storage_drivers' => $drivers,
				'restore_recovery' => $this->restore_recovery_summary(),
			)
		);
	}


	/**
	 * List backups.
	 *
	 * @return WP_REST_Response
	 */
	public function backup_details( WP_REST_Request $request ) {
		try {
			$details = ( new ArchiveInspector( new LocalStorage() ) )->inspect( (string) $request['filename'] );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'nobash_archive_details', sanitize_text_field( $e->getMessage() ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( $details );
	}

	/** Build a complete restore safety plan and issue a short-lived restore token. */
	/** Return the durable emergency recovery marker and its current job state. */
	public function restore_recovery_status() {
		$marker = EmergencyRecovery::status();
		$job = array();
		if ( ! empty( $marker['job_id'] ) ) {
			$engine = $this->container->get( JobEngine::class );
			if ( ! empty( $marker['cleanup_pending'] ) ) { $engine->finalize_restore_commit( absint( $marker['job_id'] ) ); $marker = EmergencyRecovery::status(); }
			$internal = ( new JobRepository() )->find( absint( $marker['job_id'] ?? 0 ) );
			if ( $internal ) { $job = $engine->public_job( $internal ); }
		}
		return new WP_REST_Response( array( 'active' => ! empty( $marker ), 'marker' => $this->restore_recovery_summary( $marker ), 'job' => $job ) );
	}

	/**
	 * Enrich the durable recovery marker with the current job state for the UI.
	 *
	 * @param array<string,mixed>|null $marker Optional marker already loaded.
	 * @return array<string,mixed>
	 */
	private function restore_recovery_summary( ?array $marker = null ) {
		$marker = is_array( $marker ) ? $marker : EmergencyRecovery::status();
		if ( empty( $marker['job_id'] ) ) {
			return $marker;
		}
		$job = ( new JobRepository() )->find( absint( $marker['job_id'] ) );
		if ( ! $job ) {
			$marker['job_status'] = 'missing';
			return $marker;
		}
		$marker['job_status'] = sanitize_key( (string) ( $job['status'] ?? '' ) );
		$marker['last_error'] = sanitize_text_field( (string) ( $job['error'] ?? '' ) );
		return $marker;
	}

	/**
	 * Whether a recovery marker can be cleared safely by the operator.
	 *
	 * @param array<string,mixed>      $marker Recovery marker.
	 * @param array<string,mixed>|null $job    Optional job.
	 * @return bool
	 */
	private function can_clear_restore_recovery( array $marker, ?array $job = null ) {
		$state = sanitize_key( (string) ( $marker['state'] ?? '' ) );
		if ( in_array( $state, array( 'recovery_failed', 'commit_cleanup_failed' ), true ) ) {
			return true;
		}
		$status = $job ? sanitize_key( (string) ( $job['status'] ?? '' ) ) : '';
		return in_array( $status, array( 'failed', 'cancelled' ), true );
	}

	/** Resume an interrupted automatic rollback without starting a new restore. */
	public function resume_restore_recovery( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		$marker = EmergencyRecovery::status();
		if ( empty( $marker['job_id'] ) || absint( $marker['job_id'] ) !== $id ) {
			return new WP_Error( 'nobash_restore_recovery_missing', __( 'No matching restore recovery session is available.', 'nobash-backup' ), array( 'status' => 404 ) );
		}
		$engine = $this->container->get( JobEngine::class );
		if ( ! empty( $marker['cleanup_pending'] ) ) { $job = $engine->finalize_restore_commit( $id ); }
		else { $job = $engine->resume( $id ); }
		if ( ! $job ) { return new WP_Error( 'nobash_restore_recovery_job_missing', __( 'Restore recovery job not found.', 'nobash-backup' ), array( 'status' => 404 ) ); }
		AuditLog::record( 'restore_recovery_resumed', 'job', (string) $id );
		return new WP_REST_Response( $engine->public_job( $job ) );
	}

	/** Reset a failed recovery marker so a new restore can be started cleanly. */
	public function reset_restore_recovery( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		$marker = EmergencyRecovery::status();
		if ( empty( $marker['job_id'] ) || absint( $marker['job_id'] ) !== $id ) {
			return new WP_Error( 'nobash_restore_recovery_missing', __( 'No matching restore recovery session is available.', 'nobash-backup' ), array( 'status' => 404 ) );
		}
		$repo = new JobRepository();
		$job = $repo->find( $id );
		if ( ! $this->can_clear_restore_recovery( $marker, $job ) ) {
			return new WP_Error( 'nobash_restore_recovery_not_resettable', __( 'The recovery job is still active. Resume or cancel it before resetting the state.', 'nobash-backup' ), array( 'status' => 409 ) );
		}
		EmergencyRecovery::clear( $id );
		AuditLog::record( 'restore_recovery_reset', 'job', (string) $id );
		return new WP_REST_Response( array( 'reset' => true, 'id' => $id ) );
	}

	/** Delete a failed recovery job record and clear its marker. */
	public function delete_restore_recovery( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		$marker = EmergencyRecovery::status();
		if ( empty( $marker['job_id'] ) || absint( $marker['job_id'] ) !== $id ) {
			return new WP_Error( 'nobash_restore_recovery_missing', __( 'No matching restore recovery session is available.', 'nobash-backup' ), array( 'status' => 404 ) );
		}
		$repo = new JobRepository();
		$job = $repo->find( $id );
		if ( ! $this->can_clear_restore_recovery( $marker, $job ) ) {
			return new WP_Error( 'nobash_restore_recovery_not_deletable', __( 'The recovery job is still active. Resume or cancel it before deleting the job record.', 'nobash-backup' ), array( 'status' => 409 ) );
		}
		EmergencyRecovery::clear( $id );
		if ( $job ) {
			$repo->delete( $id, true );
		}
		AuditLog::record( 'restore_recovery_deleted', 'job', (string) $id );
		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ) );
	}

	public function restore_plan( WP_REST_Request $request ) {
		$limited = RateLimiter::attempt( 'restore_plan', 20, 15 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}
		$filename   = basename( (string) $request->get_param( 'filename' ) );
		$components = $request->get_param( 'components' );
		$components = is_array( $components ) ? $components : array();
		try {
			$details = ( new ArchiveInspector( new LocalStorage() ) )->inspect( $filename );
			$plan    = ( new RestorePlan() )->build( new LocalStorage(), $filename, $components );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'nobash_restore_plan', sanitize_text_field( $e->getMessage() ), array( 'status' => 400 ) );
		}
		unset( $plan['path'] );
		$preflight = ( new Preflight() )->run( 'import', array( 'filename' => $filename ) );
		$can_proceed = ! empty( $preflight['can_proceed'] ) && ! empty( $details['restore_ready'] );
		return new WP_REST_Response(
			array(
				'can_proceed'      => $can_proceed,
				'details'          => $details,
				'plan'             => $plan,
				'checks'           => isset( $preflight['checks'] ) ? $preflight['checks'] : array(),
				'password_required'=> ! empty( $details['password_required'] ),
				'restore_token'    => $can_proceed ? RestoreToken::issue( $filename ) : '',
			)
		);
	}

	public function list_backups() {
		$storage = new LocalStorage();
		$items   = $storage->list_archives();
		foreach ( $items as &$item ) {
			unset( $item['path'] );
			$item['size_human'] = size_format( $item['size'] );
			$item['modified_human'] = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), gmdate( 'Y-m-d H:i:s', $item['modified'] ), true );
			$item['modified_date_human'] = wp_date( get_option( 'date_format' ), (int) $item['modified'] );
			$item['modified_time_human'] = wp_date( get_option( 'time_format' ), (int) $item['modified'] );
		}
		return new WP_REST_Response( array( 'items' => $items ) );
	}

	/**
	 * Delete backup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_backup( WP_REST_Request $request ) {
		$limited = RateLimiter::attempt( 'delete', 30, 15 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$storage = new LocalStorage();
		$ok      = $storage->delete_archive( $request['filename'] );
		if ( ! $ok ) {
			return new WP_Error( 'nobash_not_found', __( 'Archive not found.', 'nobash-backup' ), array( 'status' => 404 ) );
		}
		AuditLog::record( 'backup_deleted', 'archive', (string) $request['filename'] );
		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * Download backup via REST (streams file).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Error|void
	 */
	public function download_backup( WP_REST_Request $request ) {
		$storage = new LocalStorage();
		$path    = $storage->resolve_archive( $request['filename'] );
		if ( null === $path ) {
			return new WP_Error( 'nobash_not_found', __( 'Archive not found.', 'nobash-backup' ), array( 'status' => 404 ) );
		}

		return \Nobash\Backup\Http\ArchiveDownload::stream( $path, (string) $request->get_header( 'range' ) );
	}

	/**
	 * Create job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_job( WP_REST_Request $request ) {
		$limited = RateLimiter::attempt( 'create_job_v2', 30, 5 * MINUTE_IN_SECONDS );
		if ( is_wp_error( $limited ) ) { return $limited; }
		/** @var JobEngine $engine */
		$engine = $this->container->get( JobEngine::class );
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		$preflight = new Preflight();

		if ( 'backup' === $type ) {
			if ( (bool) $request->get_param( 'password_protected' ) && strlen( (string) $request->get_param( 'password' ) ) < 10 ) {
				return new WP_Error( 'nobash_password_weak', __( 'Use a password with at least 10 characters.', 'nobash-backup' ), array( 'status' => 400 ) );
			}
			$check = $preflight->run( 'export' );
			if ( empty( $check['can_proceed'] ) ) {
				return new WP_Error( 'nobash_preflight_failed', __( 'Export blocked: preflight checks failed.', 'nobash-backup' ), array( 'status'=>400, 'checks'=>$check['checks'] ) );
			}
			$job = $engine->create_backup( array(
				'profile_id' => absint( $request->get_param( 'profile_id' ) ),
				'profile' => $request->get_param( 'profile' ),
				'backup_mode' => $request->get_param( 'backup_mode' ),
				'chain_id' => $request->get_param( 'chain_id' ),
				'password_protected' => (bool) $request->get_param( 'password_protected' ),
				'password' => (string) $request->get_param( 'password' ),
			) );
			AuditLog::record( 'backup_started', 'job', (string) $job['id'], array(
				'profile_id'=>absint($request->get_param('profile_id')),
				'profile'=>$request->get_param('profile'),
				'backup_mode'=>$request->get_param('backup_mode'),
				'chain_id'=>$request->get_param('chain_id'),
				'password_protected'=>(bool)$request->get_param('password_protected'),
			) );
		} elseif ( 'restore' === $type ) {
			$restore_point_id = sanitize_text_field( (string) $request->get_param( 'restore_point_id' ) );
			$filename = basename( (string) $request->get_param( 'filename' ) );
			if ( '' !== $restore_point_id ) {
				$point = ( new \Nobash\Backup\Incremental\ChainRepository() )->get_point( $restore_point_id );
				if ( ! $point ) { return new WP_Error( 'nobash_restore_point_missing', __( 'Restore point not found.', 'nobash-backup' ), array( 'status'=>404 ) ); }
				$filename = basename( (string) $point['filename'] );
			}
			$token = (string) $request->get_param( 'restore_token' );
			$tok = RestoreToken::consume( $token, $filename );
			if ( is_wp_error( $tok ) ) { return $tok; }
			$check = $preflight->run( 'import', array( 'filename'=>$filename ) );
			if ( empty( $check['can_proceed'] ) ) {
				return new WP_Error( 'nobash_preflight_failed', __( 'Import blocked: preflight checks failed.', 'nobash-backup' ), array( 'status'=>400, 'checks'=>$check['checks'] ) );
			}
			$job = $engine->create_restore( array(
				'filename'=>$filename,
				'search'=>$request->get_param('search'),
				'replace'=>$request->get_param('replace'),
				'components'=>is_array($request->get_param('components')) ? $request->get_param('components') : array('database','files'),
				'skip_safety_backup'=>(bool)$request->get_param('skip_safety_backup'),
				'target_home'=>(string)$request->get_param('target_home'),
				'target_siteurl'=>(string)$request->get_param('target_siteurl'),
				'target_table_prefix'=>(string)$request->get_param('target_table_prefix'),
				'restore_point_id'=>(string)$request->get_param('restore_point_id'),
				'password'=>(string)$request->get_param('password'),
			) );
			AuditLog::record( 'restore_started', 'job', (string) $job['id'], array( 'filename'=>$filename ) );
		} else {
			$registry = $this->container->get( \Nobash\Backup\Addon\Registry::class );
			if ( ! $registry->get_job_type( $type ) ) { return new WP_Error( 'nobash_bad_type', __( 'Invalid job type.', 'nobash-backup' ), array( 'status'=>400 ) ); }
			$args = $request->get_param( 'args' );
			$job = $engine->create_addon_job( $type, is_array( $args ) ? $args : array() );
			AuditLog::record( 'addon_job_started', 'job', (string) $job['id'], array( 'type'=>$type ) );
		}
		return new WP_REST_Response( $engine->public_job( $job ), 201 );
	}


	/**
	 * Get job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job( WP_REST_Request $request ) {
		/** @var JobEngine $engine */
		$engine = $this->container->get( JobEngine::class );
		$job    = $engine->repository()->find( (int) $request['id'] );
		if ( ! $job ) {
			return new WP_Error( 'nobash_not_found', __( 'Job not found.', 'nobash-backup' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( $engine->public_job( $job ) );
	}

	/**
	 * Get the bounded, sanitized event history for a job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job_events( WP_REST_Request $request ) {
		/** @var JobEngine $engine */
		$engine = $this->container->get( JobEngine::class );
		$job_id = (int) $request['id'];
		if ( ! $engine->repository()->find( $job_id ) ) {
			return new WP_Error( 'nobash_not_found', __( 'Job not found.', 'nobash-backup' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response(
			array(
				'items' => $engine->repository()->events()->recent( $job_id, 100 ),
			)
		);
	}

	/**
	 * Step job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function step_job( WP_REST_Request $request ) {
		// Long-running jobs may require hundreds of authenticated continuation requests.
		// REST permission checks plus the per-job lease enforce authorization and exclusive
		// execution; a generic request bucket here would throttle legitimate admin work.
		/** @var JobEngine $engine */
		$engine = $this->container->get( JobEngine::class );
		$job    = $engine->run_slice( (int) $request['id'], 'rest' );
		return new WP_REST_Response( $engine->public_job( $job ) );
	}

	/**
	 * Pause job after the current bounded slice.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pause_job( WP_REST_Request $request ) {
		$limited = RateLimiter::attempt( 'job_pause', 30, MINUTE_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		/** @var JobEngine $engine */
		$engine = $this->container->get( JobEngine::class );
		$job    = $engine->pause( (int) $request['id'] );
		if ( ! $job ) {
			return new WP_Error( 'nobash_not_found', __( 'Job not found.', 'nobash-backup' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( $engine->public_job( $job ) );
	}

	/**
	 * Resume a paused or retrying job from its durable checkpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resume_job( WP_REST_Request $request ) {
		$limited = RateLimiter::attempt( 'job_resume', 30, MINUTE_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		/** @var JobEngine $engine */
		$engine = $this->container->get( JobEngine::class );
		$job    = $engine->resume( (int) $request['id'] );
		if ( ! $job ) {
			return new WP_Error( 'nobash_not_found', __( 'Job not found.', 'nobash-backup' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( $engine->public_job( $job ) );
	}

	/**
	 * Cancel job.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_job( WP_REST_Request $request ) {
		$limited = RateLimiter::attempt( 'job_cancel', 30, MINUTE_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		/** @var JobEngine $engine */
		$engine = $this->container->get( JobEngine::class );
		$job    = $engine->cancel( (int) $request['id'] );
		if ( ! $job ) {
			return new WP_Error( 'nobash_not_found', __( 'Job not found.', 'nobash-backup' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response( $engine->public_job( $job ) );
	}

	/**
	 * Delete a terminal job record and its event history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_job_record( WP_REST_Request $request ) {
		$limited = RateLimiter::attempt( 'job_delete', 30, MINUTE_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$id   = absint( $request['id'] );
		$repo = new JobRepository();
		$job  = $repo->find( $id );
		if ( ! $job ) {
			return new WP_Error( 'nobash_not_found', __( 'Job not found.', 'nobash-backup' ), array( 'status' => 404 ) );
		}

		$status = sanitize_key( (string) ( $job['status'] ?? '' ) );
		if ( ! \Nobash\Backup\Jobs\JobState::is_terminal( $status ) ) {
			return new WP_Error( 'nobash_job_not_terminal', __( 'This job is still active. Cancel it first, then remove it after it becomes failed or cancelled.', 'nobash-backup' ), array( 'status' => 409 ) );
		}

		$marker = EmergencyRecovery::status();
		if ( ! empty( $marker['job_id'] ) && absint( $marker['job_id'] ) === $id ) {
			if ( ! $this->can_clear_restore_recovery( $marker, $job ) ) {
				return new WP_Error( 'nobash_restore_recovery_not_deletable', __( 'The recovery job is still active. Resume or cancel it before deleting the job record.', 'nobash-backup' ), array( 'status' => 409 ) );
			}
			EmergencyRecovery::clear( $id );
		}

		try {
			( new \Nobash\Backup\Filesystem\FileInventoryRepository() )->delete_job( $id );
		} catch ( \Throwable $ignored ) {
			// Keep job-history cleanup resilient even when inventory metadata is already missing.
		}

		$repo->delete( $id, true );
		AuditLog::record( 'job_deleted', 'job', (string) $id, array( 'status' => $status, 'type' => sanitize_key( (string) ( $job['type'] ?? '' ) ) ) );
		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ) );
	}

	/**
	 * Get settings.
	 *
	 * @return WP_REST_Response
	 */
	public function storage_providers() {
		/** @var StorageManager $manager */ $manager=$this->container->get(StorageManager::class);
		return new WP_REST_Response(array('providers'=>$manager->providers()));
	}

	public function test_storage( WP_REST_Request $request ) {
		$limited=RateLimiter::attempt('storage_test',10,MINUTE_IN_SECONDS); if(is_wp_error($limited))return $limited;
		try { /** @var StorageManager $manager */ $manager=$this->container->get(StorageManager::class); $driver=$manager->driver((string)$request['provider']); $result=$driver->test_connection(array('credentials'=>CredentialVault::load($driver->id()))); $capacity=$driver->available_capacity(array('credentials'=>CredentialVault::load($driver->id()))); return new WP_REST_Response(array('result'=>$result,'available_capacity'=>$capacity)); } catch(\Throwable $e){ return new WP_Error('nobash_storage_test',sanitize_text_field($e->getMessage()),array('status'=>400)); }
	}

	public function save_storage_credentials( WP_REST_Request $request ) {
		$provider=sanitize_key((string)$request['provider']); $credentials=$request->get_param('credentials'); if(!is_array($credentials))return new WP_Error('nobash_storage_credentials',__('Credentials must be an object.','nobash-backup'),array('status'=>400));
		$clean=array(); foreach($credentials as $key=>$value){$key=sanitize_key((string)$key); if(''!==$key && is_scalar($value))$clean[$key]=(string)$value;}
		try{CredentialVault::save($provider,$clean);}catch(\Throwable $e){return new WP_Error('nobash_storage_credentials',sanitize_text_field($e->getMessage()),array('status'=>500));}
		AuditLog::record('storage_credentials_updated','storage',$provider,array('fields'=>array_keys($clean))); return new WP_REST_Response(array('saved'=>true,'provider'=>$provider));
	}

	public function get_settings() {
		$settings = get_option( 'nobash_backup_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$webhook_credentials = CredentialVault::load( 'webhook' );
		$legacy_secret = isset( $settings['webhook_secret'] ) ? (string) $settings['webhook_secret'] : '';
		$secret_set = ! empty( $webhook_credentials['secret'] ) || '' !== $legacy_secret;
		unset( $settings['webhook_secret'], $settings['acknowledge_storage_exposure'] );
		$settings['webhook_secret_set'] = $secret_set;
		$schedule_repository = new \Nobash\Backup\Schedule\ScheduleRepository();
		$default_schedule    = $schedule_repository->find_by_key( 'default' );

		return new WP_REST_Response(
			array(
				'settings'   => $settings,
				'schedules'  => is_array( $default_schedule ) ? $default_schedule : array(),
				'schedule_status' => $schedule_repository->all(),
				'profiles' => ( new \Nobash\Backup\BackupProfile\ProfileRepository() )->all(),
				'encryption' => PasswordArchive::status(),
			)
		);
	}

	/**
	 * Update settings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ) {
		$settings = $request->get_param( 'settings' );
		if ( is_array( $settings ) ) {
			$current = get_option( 'nobash_backup_settings', array() );
			if ( ! is_array( $current ) ) {
				$current = array();
			}

			$retention_count = isset( $settings['retention_count'] ) ? absint( $settings['retention_count'] ) : ( isset( $current['retention_count'] ) ? absint( $current['retention_count'] ) : 10 );
			$retention_days  = isset( $settings['retention_days'] ) ? absint( $settings['retention_days'] ) : ( isset( $current['retention_days'] ) ? absint( $current['retention_days'] ) : 30 );
			$retention_count = max( 1, min( 500, $retention_count ) );
			$retention_days  = max( 1, min( 3650, $retention_days ) );
			$retention_min_free_mb = isset( $settings['retention_min_free_mb'] ) ? absint( $settings['retention_min_free_mb'] ) : absint( $current['retention_min_free_mb'] ?? 1024 );
			$retention_min_free_mb = max( 0, min( 1048576, $retention_min_free_mb ) );
			$temporary_retention_hours = isset( $settings['temporary_retention_hours'] ) ? absint( $settings['temporary_retention_hours'] ) : absint( $current['temporary_retention_hours'] ?? 48 );
			$temporary_retention_hours = max( 6, min( 720, $temporary_retention_hours ) );

			$webhook = array_key_exists( 'webhook_url', $settings ) ? esc_url_raw( $settings['webhook_url'] ) : ( $current['webhook_url'] ?? '' );
			if ( $webhook ) {
				$safe = UrlGuard::assert_public_url( $webhook, false );
				if ( is_wp_error( $safe ) ) {
					return $safe;
				}
			}

			$notify_email = array_key_exists( 'notify_email', $settings ) ? trim( (string) $settings['notify_email'] ) : (string) ( $current['notify_email'] ?? '' );
			if ( '' !== $notify_email && ! is_email( $notify_email ) ) {
				return new WP_Error( 'nobash_settings_email', __( 'Enter a valid notification email address.', 'nobash-backup' ), array( 'status' => 400 ) );
			}

			$webhook_credentials = CredentialVault::load( 'webhook' );
			$secret = isset( $webhook_credentials['secret'] ) ? (string) $webhook_credentials['secret'] : ( isset( $current['webhook_secret'] ) ? (string) $current['webhook_secret'] : '' );
			if ( ! empty( $settings['webhook_secret_clear'] ) ) {
				$secret = '';
			} elseif ( array_key_exists( 'webhook_secret', $settings ) ) {
				$incoming = trim( (string) $settings['webhook_secret'] );
				if ( strlen( $incoming ) > 512 ) {
					return new WP_Error( 'nobash_settings_webhook_secret', __( 'Webhook secret must be 512 characters or fewer.', 'nobash-backup' ), array( 'status' => 400 ) );
				}
				if ( '' !== $incoming ) {
					$secret = $incoming;
				}
			}

			$profiles = array( 'full', 'database', 'files', 'uploads' );
			$resource_modes = array( 'low', 'balanced', 'high' );
			$storage_defaults = array( 'local' );
			$missed_actions = array( 'run_next', 'skip' );
			$clean_list = static function ( $value, $table_names = false ) {
				if ( ! is_array( $value ) ) {
					return array();
				}
				$items = array();
				foreach ( array_slice( $value, 0, 250 ) as $item ) {
					$item = $table_names ? sanitize_key( (string) $item ) : sanitize_text_field( str_replace( '\\', '/', (string) $item ) );
					$item = trim( $item );
					if ( ! $table_names ) {
						$item = trim( $item, '/' );
						if ( '' === $item || false !== strpos( $item, "\0" ) || preg_match( '#(?:^|/)\.\.(?:/|$)#', $item ) || preg_match( '#^[a-zA-Z]:/#', $item ) ) {
							continue;
						}
					}
					if ( '' !== $item && ! in_array( $item, $items, true ) ) {
						$items[] = $item;
					}
				}
				return $items;
			};

			$merged = array_merge(
				$current,
				array(
					'retention_count'   => $retention_count,
					'retention_days'    => $retention_days,
					'retention_min_free_mb' => $retention_min_free_mb,
					'temporary_retention_hours' => $temporary_retention_hours,
					'notify_email'      => sanitize_email( $notify_email ),
					'notify_on_success' => array_key_exists( 'notify_on_success', $settings ) ? ! empty( $settings['notify_on_success'] ) : ! empty( $current['notify_on_success'] ),
					'notify_on_failure' => array_key_exists( 'notify_on_failure', $settings ) ? ! empty( $settings['notify_on_failure'] ) : ( ! isset( $current['notify_on_failure'] ) || ! empty( $current['notify_on_failure'] ) ),
					'webhook_url'       => $webhook,
					'onboarding_done'   => array_key_exists( 'onboarding_done', $settings ) ? ! empty( $settings['onboarding_done'] ) : ! empty( $current['onboarding_done'] ),
					'default_profile' => in_array( (string) ( $settings['default_profile'] ?? $current['default_profile'] ?? 'full' ), $profiles, true ) ? (string) ( $settings['default_profile'] ?? $current['default_profile'] ?? 'full' ) : 'full',
					'default_storage_provider' => in_array( (string) ( $settings['default_storage_provider'] ?? $current['default_storage_provider'] ?? 'local' ), $storage_defaults, true ) ? (string) ( $settings['default_storage_provider'] ?? $current['default_storage_provider'] ?? 'local' ) : 'local',
					'missed_schedule_action' => in_array( (string) ( $settings['missed_schedule_action'] ?? $current['missed_schedule_action'] ?? 'run_next' ), $missed_actions, true ) ? (string) ( $settings['missed_schedule_action'] ?? $current['missed_schedule_action'] ?? 'run_next' ) : 'run_next',
					'resource_mode' => in_array( (string) ( $settings['resource_mode'] ?? $current['resource_mode'] ?? 'balanced' ), $resource_modes, true ) ? (string) ( $settings['resource_mode'] ?? $current['resource_mode'] ?? 'balanced' ) : 'balanced',
					'worker_memory_mb' => max( 32, min( 2048, absint( $settings['worker_memory_mb'] ?? $current['worker_memory_mb'] ?? 192 ) ) ),
					'job_slice_seconds' => max( 5, min( 120, absint( $settings['job_slice_seconds'] ?? $current['job_slice_seconds'] ?? 25 ) ) ),
					'slice_pause_ms' => max( 0, min( 5000, absint( $settings['slice_pause_ms'] ?? $current['slice_pause_ms'] ?? 50 ) ) ),
					'exclude_paths' => array_key_exists( 'exclude_paths', $settings ) ? $clean_list( $settings['exclude_paths'] ) : (array) ( $current['exclude_paths'] ?? array() ),
					'exclude_tables' => array_key_exists( 'exclude_tables', $settings ) ? $clean_list( $settings['exclude_tables'], true ) : (array) ( $current['exclude_tables'] ?? array() ),
					'auto_verify_archives' => array_key_exists( 'auto_verify_archives', $settings ) ? ! empty( $settings['auto_verify_archives'] ) : ( ! isset( $current['auto_verify_archives'] ) || ! empty( $current['auto_verify_archives'] ) ),
					'show_admin_bar_action' => array_key_exists( 'show_admin_bar_action', $settings ) ? ! empty( $settings['show_admin_bar_action'] ) : ( ! isset( $current['show_admin_bar_action'] ) || ! empty( $current['show_admin_bar_action'] ) ),
					'confirm_destructive_actions' => array_key_exists( 'confirm_destructive_actions', $settings ) ? ! empty( $settings['confirm_destructive_actions'] ) : ( ! isset( $current['confirm_destructive_actions'] ) || ! empty( $current['confirm_destructive_actions'] ) ),
					'protect_manual_backups' => ! empty( $settings['protect_manual_backups'] ),
					'exclude_cache_directories' => ! array_key_exists( 'exclude_cache_directories', $settings ) || ! empty( $settings['exclude_cache_directories'] ),
					'exclude_log_files' => ! array_key_exists( 'exclude_log_files', $settings ) || ! empty( $settings['exclude_log_files'] ),
					'pause_on_high_load' => ! array_key_exists( 'pause_on_high_load', $settings ) || ! empty( $settings['pause_on_high_load'] ),
					'resume_interrupted_jobs' => ! array_key_exists( 'resume_interrupted_jobs', $settings ) || ! empty( $settings['resume_interrupted_jobs'] ),
					'require_restore_backup' => ! array_key_exists( 'require_restore_backup', $settings ) || ! empty( $settings['require_restore_backup'] ),
					'verify_before_restore' => ! array_key_exists( 'verify_before_restore', $settings ) || ! empty( $settings['verify_before_restore'] ),
					'restrict_downloads_to_admins' => ! array_key_exists( 'restrict_downloads_to_admins', $settings ) || ! empty( $settings['restrict_downloads_to_admins'] ),
					'notify_on_warning' => ! array_key_exists( 'notify_on_warning', $settings ) || ! empty( $settings['notify_on_warning'] ),
					'woocommerce_delta_capture' => false,
					'debug_logging' => ! empty( $settings['debug_logging'] ),
					'delete_data_on_uninstall' => ! empty( $settings['delete_data_on_uninstall'] ),
				)
			);
			// Migrate/remove the legacy plaintext secret and keep the signing key in the
			// authenticated credential vault (non-autoloaded encrypted option).
			unset( $merged['webhook_secret'], $merged['acknowledge_storage_exposure'] );
			if ( '' === $secret ) {
				CredentialVault::delete( 'webhook' );
			} else {
				try {
					CredentialVault::save( 'webhook', array( 'secret' => $secret ) );
				} catch ( \Throwable $e ) {
					return new WP_Error( 'nobash_settings_webhook_secret_store', __( 'The webhook signing secret could not be stored securely on this server.', 'nobash-backup' ), array( 'status' => 500 ) );
				}
			}
			update_option( 'nobash_backup_settings', $merged );
			AuditLog::record( 'settings_updated', 'settings', 'nobash_backup_settings' );
		}

		$schedules = $request->get_param( 'schedules' );
		if ( is_array( $schedules ) ) {
			/** @var Scheduler $scheduler */
			$scheduler = $this->container->get( Scheduler::class );
			$scheduler->update_schedule( $schedules );
		}

		return $this->get_settings();
	}

	/**
	 * Import a remote .nbh archive by URL onto local storage.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_from_url( WP_REST_Request $request ) {
		$limited = RateLimiter::attempt( 'import_url', 5, HOUR_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return new WP_Error( 'nobash_import_rate_limited', __( 'Too many URL import attempts were made. Wait a few minutes and try again.', 'nobash-backup' ), array( 'status' => 429 ) );
		}

		$raw_url = trim( (string) $request->get_param( 'url' ) );
		if ( '' === $raw_url ) {
			return new WP_Error( 'import_url_required', __( 'Enter a public HTTPS URL for the .nbh archive.', 'nobash-backup' ), array( 'status' => 400 ) );
		}

		$url   = esc_url_raw( $raw_url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return new WP_Error( 'import_url_required', __( 'Enter a valid public HTTPS archive URL.', 'nobash-backup' ), array( 'status' => 400 ) );
		}
		if ( 'https' !== strtolower( (string) $parts['scheme'] ) ) {
			return new WP_Error( 'import_url_https', __( 'Only secure HTTPS archive URLs are allowed.', 'nobash-backup' ), array( 'status' => 400 ) );
		}

		$sha256 = strtolower( trim( (string) $request->get_param( 'sha256' ) ) );
		if ( '' !== $sha256 && ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			return new WP_Error( 'import_sha256_invalid', __( 'The SHA-256 value must contain exactly 64 hexadecimal characters.', 'nobash-backup' ), array( 'status' => 400 ) );
		}

		$ok = UrlGuard::assert_public_url( $url, false );
		if ( is_wp_error( $ok ) ) {
			return new WP_Error( $ok->get_error_code(), $ok->get_error_message(), array( 'status' => 400 ) );
		}
		/** @var JobEngine $engine */
		$engine = $this->container->get( JobEngine::class );
		$job = $engine->create_remote_import( array( 'url' => $url, 'sha256' => $sha256 ) );
		AuditLog::record( 'remote_import_started', 'job', (string) $job['id'], array( 'url_host' => wp_parse_url( $url, PHP_URL_HOST ) ) );
		return new WP_REST_Response( $engine->public_job( $job ), 202 );
	}


	/**
	 * Upload a native .nbh backup archive.
	 *
	 * The endpoint accepts exactly one native `.nbh` archive. Generic ZIP and
	 * third-party backup formats are rejected before publication.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_backup( WP_REST_Request $request ) {
		$limited = RateLimiter::attempt( 'upload', 10, HOUR_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}
		$uploads = $this->normalize_uploaded_files( $request->get_file_params() );
		if ( 1 !== count( $uploads ) ) {
			return new WP_Error( 'nobash_upload_count', __( 'Choose one .nbh backup archive.', 'nobash-backup' ), array( 'status' => 400 ) );
		}
		$file = $uploads[0];
		$name = basename( (string) $file['name'] );
		if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'nobash_upload', __( 'The .nbh archive could not be uploaded.', 'nobash-backup' ), array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/\.nbh$/i', $name ) ) {
			return new WP_Error( 'nobash_upload_type', __( 'Only .nbh backup archives can be imported.', 'nobash-backup' ), array( 'status' => 400 ) );
		}
		$size = isset( $file['size'] ) ? (int) $file['size'] : (int) filesize( $file['tmp_name'] );
		if ( $size < 1 || $size > 2 * 1024 * 1024 * 1024 ) {
			return new WP_Error( 'nobash_upload_size', __( 'The archive exceeds the safe browser-upload limit. Use resumable upload for larger files.', 'nobash-backup' ), array( 'status' => 400 ) );
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Only the first 9 magic bytes of a possibly multi-gigabyte uploaded file are read; WP_Filesystem::get_contents() has no partial-read primitive and would load the entire upload into memory just to check its signature.
		$fh = fopen( $file['tmp_name'], 'rb' );
		$magic = $fh ? fread( $fh, 9 ) : '';
		if ( $fh ) fclose( $fh );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! PasswordArchive::looks_like_archive( (string) $magic ) ) {
			return new WP_Error( 'nobash_upload_format', __( 'Invalid .nbh archive format.', 'nobash-backup' ), array( 'status' => 400 ) );
		}
		$storage_dir = LocalStorage::ensure_directory();
		$profile = LocalStorage::profile_from_filename( $name );
		try {
			$safe = LocalStorage::allocate_archive_filename( $profile ? $profile : 'full' );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'nobash_upload_store', sanitize_text_field( $e->getMessage() ), array( 'status' => 500 ) );
		}
		$dest = trailingslashit( $storage_dir ) . $safe;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$handled = wp_handle_upload( $file, array( 'test_form' => false, 'test_type' => false ) );
		if ( ! is_array( $handled ) || ! empty( $handled['error'] ) || empty( $handled['file'] ) ) {
			$message = is_array( $handled ) && ! empty( $handled['error'] ) ? sanitize_text_field( (string) $handled['error'] ) : __( 'Could not receive the uploaded .nbh archive.', 'nobash-backup' );
			return new WP_Error( 'nobash_upload_store', $message, array( 'status' => 500 ) );
		}
		$received = (string) $handled['file'];
		$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
		if ( ! $wp_filesystem->move( $received, $dest, true ) ) {
			$wp_filesystem->delete( $received, false, 'f' );
			return new WP_Error( 'nobash_upload_store', __( 'Could not store the uploaded .nbh archive.', 'nobash-backup' ), array( 'status' => 500 ) );
		}
		$wp_filesystem->chmod( $dest, 0640 );
		try {
			$encrypted = \Nobash\Backup\Security\PasswordArchive::is_password_protected( $dest );
			$fingerprint_info = ArchiveFingerprint::inspect( $dest );
			$sha256 = (string) $fingerprint_info['fingerprint'];
			( new LocalStorage() )->write_archive_index(
				$safe,
				array(
					'format'           => 'nbh',
					'profile'          => $profile ? $profile : 'full',
					'source'           => 'upload',
					'original_name'    => $name,
					'encrypted'        => $encrypted,
					'encryption'       => $encrypted ? 'password-aead-v2' : '',
					'format_version'   => $encrypted ? 'password-2' : 3,
					'sha256'           => $sha256,
					'checksum_scheme'  => (string) $fingerprint_info['checksum_scheme'],
					'integrity_status' => $encrypted ? 'password_required' : 'ready_for_verification',
				)
			);
		} catch ( \Throwable $e ) {
			wp_delete_file( $dest );
			$sidecar = $dest . '.json';
			if ( is_link( $sidecar ) || is_file( $sidecar ) ) { wp_delete_file( $sidecar ); }
			return new WP_Error( 'nobash_upload_format', sanitize_text_field( $e->getMessage() ), array( 'status' => 400 ) );
		}
		AuditLog::record( 'backup_uploaded', 'archive', $safe, array( 'source' => 'Nobash Backup', 'encrypted' => $encrypted ) );
		return new WP_REST_Response( array( 'kind' => 'native', 'source_product' => 'Nobash Backup', 'filename' => $safe, 'size' => (int) filesize( $dest ), 'encrypted' => $encrypted, 'sha256' => $sha256, 'checksum_scheme' => (string) $fingerprint_info['checksum_scheme'] ), 201 );
	}


	/** Create a resumable browser/direct-migration transfer. */
	public function create_transfer( WP_REST_Request $request ) {
		$limited = RateLimiter::attempt( 'transfer_create', 20, HOUR_IN_SECONDS );
		if ( is_wp_error( $limited ) ) { return $limited; }
		$size = (int) $request->get_param( 'size' );
		if ( $size < 1 || $size > TransferRepository::MAX_ARCHIVE_SIZE ) {
			return new WP_Error( 'nobash_transfer_size', __( 'Transfer size is invalid.', 'nobash-backup' ), array( 'status' => 400 ) );
		}
		$name = sanitize_file_name( (string) $request->get_param( 'filename' ) );
		$sha  = strtolower( sanitize_text_field( (string) $request->get_param( 'sha256' ) ) );
		if ( $sha && ! preg_match( '/^[a-f0-9]{64}$/', $sha ) ) {
			return new WP_Error( 'nobash_transfer_sha', __( 'Archive SHA-256 is invalid.', 'nobash-backup' ), array( 'status' => 400 ) );
		}
		$ttl = TransferRepository::recommended_ttl( $size, 2 * HOUR_IN_SECONDS );
		try { $row = ( new TransferRepository() )->create( $name, $size, $sha, 'upload', $ttl ); }
		catch ( \Throwable $e ) { return new WP_Error( 'nobash_transfer_create', sanitize_text_field( $e->getMessage() ), array( 'status' => 500 ) ); }
		$token = TransferToken::issue( $row['transfer_id'], 'write', $ttl );
		return new WP_REST_Response( array( 'transfer_id' => $row['transfer_id'], 'offset' => 0, 'chunk_size' => HostingEnvironment::max_transfer_chunk_bytes(), 'expires_at' => $row['expires_at'], 'transfer_token' => $token ), 201 );
	}

	/** Validate cookie auth or a transfer-scoped bearer token. */
	public function can_transfer( WP_REST_Request $request ) {
		if ( Capabilities::current_user_can() ) { return true; }
		$ok = TransferToken::verify( (string) $request->get_header( 'x-nbh-transfer-token' ), (string) $request['id'], 'write' );
		return is_wp_error( $ok ) ? $ok : true;
	}

	public function can_finalize_transfer( WP_REST_Request $request ) {
		if ( Capabilities::current_user_can() ) { return true; }
		$ok = TransferToken::verify( (string) $request->get_header( 'x-nbh-transfer-token' ), (string) $request['id'], 'write', false );
		return is_wp_error( $ok ) ? $ok : true;
	}

	public function get_transfer( WP_REST_Request $request ) {
		$row = ( new TransferRepository() )->find( (string) $request['id'] );
		if ( ! $row ) { return new WP_Error( 'nobash_transfer_missing', __( 'Transfer session not found.', 'nobash-backup' ), array( 'status' => 404 ) ); }
		unset( $row['id'], $row['temp_path'] );
		return new WP_REST_Response( $row );
	}

	public function upload_transfer_chunk( WP_REST_Request $request ) {
		$offset = (int) $request->get_header( 'x-nbh-offset' );
		$sha    = strtolower( (string) $request->get_header( 'x-nbh-chunk-sha256' ) );
		try { $row = ( new TransferRepository() )->append( (string) $request['id'], $offset, $request->get_body(), $sha ); }
		catch ( \Throwable $e ) { return new WP_Error( 'nobash_transfer_chunk', sanitize_text_field( $e->getMessage() ), array( 'status' => 409 ) ); }
		return new WP_REST_Response( array( 'transfer_id' => $row['transfer_id'], 'offset' => (int) $row['received_size'], 'expected_size' => (int) $row['expected_size'] ) );
	}

	public function finalize_transfer( WP_REST_Request $request ) {
		try { $result = ( new TransferRepository() )->finalize( (string) $request['id'] ); }
		catch ( \Throwable $e ) { return new WP_Error( 'nobash_transfer_finalize', sanitize_text_field( $e->getMessage() ), array( 'status' => 400 ) ); }
		AuditLog::record( 'transfer_completed', 'archive', $result['filename'], array( 'size' => $result['size'], 'mode' => 'upload' ) );
		return new WP_REST_Response( $result, 201 );
	}

	public function cancel_transfer( WP_REST_Request $request ) {
		$ok = ( new TransferRepository() )->cancel( (string) $request['id'] );
		if ( ! $ok ) { return new WP_Error( 'nobash_transfer_missing', __( 'Transfer session not found.', 'nobash-backup' ), array( 'status' => 404 ) ); }
		AuditLog::record( 'transfer_cancelled', 'transfer', (string) $request['id'] );
		return new WP_REST_Response( array( 'cancelled' => true ) );
	}


	/** @return array<int,array<string,mixed>> */
	private function normalize_uploaded_files( array $params ) {
		$files = array();
		foreach ( $params as $value ) {
			if ( ! is_array( $value ) || ! isset( $value['tmp_name'] ) ) {
				continue;
			}
			if ( is_array( $value['tmp_name'] ) ) {
				foreach ( $value['tmp_name'] as $index => $tmp_name ) {
					$files[] = array(
						'name'     => isset( $value['name'][ $index ] ) ? (string) $value['name'][ $index ] : '',
						'tmp_name' => (string) $tmp_name,
						'size'     => isset( $value['size'][ $index ] ) ? (int) $value['size'][ $index ] : 0,
						'error'    => isset( $value['error'][ $index ] ) ? (int) $value['error'][ $index ] : UPLOAD_ERR_OK,
						'type'     => isset( $value['type'][ $index ] ) ? (string) $value['type'][ $index ] : '',
					);
				}
			} else {
				$files[] = array(
					'name'     => isset( $value['name'] ) ? (string) $value['name'] : '',
					'tmp_name' => (string) $value['tmp_name'],
					'size'     => isset( $value['size'] ) ? (int) $value['size'] : 0,
					'error'    => isset( $value['error'] ) ? (int) $value['error'] : UPLOAD_ERR_OK,
					'type'     => isset( $value['type'] ) ? (string) $value['type'] : '',
				);
			}
		}
		return $files;
	}



	/** Run an explicitly confirmed Nobash Backup-owned reset operation. */
	public function reset_data( WP_REST_Request $request ) {
		$limited = RateLimiter::attempt( 'reset', 8, HOUR_IN_SECONDS );
		if ( is_wp_error( $limited ) ) { return $limited; }
		$action = sanitize_key( (string) $request->get_param( 'action' ) );
		$confirm = sanitize_text_field( (string) $request->get_param( 'confirmation' ) );
		$required = 'RESET NOBASH BACKUP';
		if ( ! hash_equals( $required, $confirm ) ) {
			return new WP_Error( 'nobash_reset_confirmation', __( 'Type RESET NOBASH BACKUP to confirm this operation.', 'nobash-backup' ), array( 'status' => 400 ) );
		}
		try {
			$result = ( new \Nobash\Backup\System\ResetManager() )->run( $action );
			return new WP_REST_Response( array( 'completed' => true, 'action' => $action, 'result' => $result ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'nobash_reset_failed', sanitize_text_field( $e->getMessage() ), array( 'status' => 500 ) );
		}
	}

	/**
	 * Permanently remove one finished job record.
	 *
	 * Active jobs must be cancelled first. Restore recovery jobs are protected
	 * until their recovery marker has been resolved from the Restore screen.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_job_history_item( WP_REST_Request $request ) {
		$id = absint( $request['id'] );
		if ( $id < 1 ) {
			return new WP_Error( 'nobash_job_invalid', __( 'Invalid job ID.', 'nobash-backup' ), array( 'status' => 400 ) );
		}
		$repo = new JobRepository();
		$job  = $repo->find( $id );
		if ( ! $job ) {
			return new WP_Error( 'nobash_not_found', __( 'Job not found.', 'nobash-backup' ), array( 'status' => 404 ) );
		}
		$status = sanitize_key( (string) ( $job['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'completed', 'completed_with_warnings', 'failed', 'cancelled' ), true ) ) {
			return new WP_Error( 'nobash_job_not_removable', __( 'Only completed, failed or cancelled jobs can be removed. Cancel active jobs first.', 'nobash-backup' ), array( 'status' => 409 ) );
		}
		$recovery = EmergencyRecovery::status();
		if ( ! empty( $recovery['job_id'] ) && absint( $recovery['job_id'] ) === $id ) {
			return new WP_Error( 'nobash_job_recovery_protected', __( 'This restore job still has recovery data. Resolve or delete the recovery session from Restore before removing its job history.', 'nobash-backup' ), array( 'status' => 409 ) );
		}
		if ( ! $repo->delete( $id, true ) ) {
			return new WP_Error( 'nobash_job_delete_failed', __( 'The job record could not be removed.', 'nobash-backup' ), array( 'status' => 500 ) );
		}
		AuditLog::record( 'job_activity_removed', 'job', (string) $id, array( 'status' => $status ) );
		return new WP_REST_Response( array( 'deleted' => true, 'id' => $id ) );
	}

	/**
	 * Permanently clear terminal job activity while preserving active and
	 * recovery-required jobs.
	 *
	 * @return WP_REST_Response
	 */
	public function clear_job_history() {
		$protected_ids = array();
		$recovery = EmergencyRecovery::status();
		if ( ! empty( $recovery['job_id'] ) ) {
			$protected_ids[] = absint( $recovery['job_id'] );
		}
		$result = ( new JobRepository() )->clear_terminal_history( $protected_ids );
		AuditLog::record(
			'job_activity_cleared',
			'job',
			'',
			array(
				'deleted'   => (int) $result['deleted'],
				'preserved' => (int) $result['preserved'],
			)
		);
		return new WP_REST_Response(
			array(
				'cleared'   => true,
				'deleted'   => (int) $result['deleted'],
				'preserved' => (int) $result['preserved'],
			)
		);
	}

	/**
	 * Recent audit events.
	 *
	 * @return WP_REST_Response
	 */
	public function list_audit() {
		return new WP_REST_Response(
			array(
				'items' => AuditLog::recent( 40 ),
			)
		);
	}

	/**
	 * Permanently clear the local Nobash Backup audit trail.
	 *
	 * @return WP_REST_Response
	 */
	public function clear_audit() {
		$deleted = AuditLog::clear();
		return new WP_REST_Response(
			array(
				'cleared' => true,
				'deleted' => $deleted,
			)
		);
	}
	/** Add-on health without exposing internal errors. @return WP_REST_Response */
	public function addons() {
		$registry  = $this->container->get( \Nobash\Backup\Addon\Registry::class );
		$manifests = $registry->manifests();
		$items     = array();
		foreach ( $registry->states() as $state ) {
			$id      = sanitize_key( (string) $state['addon_id'] );
			$items[] = array(
				'id'                => $id,
				'name'              => isset( $manifests[ $id ]['name'] ) ? sanitize_text_field( $manifests[ $id ]['name'] ) : $id,
				'version'           => sanitize_text_field( (string) $state['version'] ),
				'api_version'       => sanitize_text_field( (string) $state['api_version'] ),
				'status'            => sanitize_key( (string) $state['status'] ),
				'migration_version' => sanitize_text_field( (string) $state['migration_version'] ),
				'has_error'         => ! empty( $state['last_error'] ),
			);
		}
		return new WP_REST_Response( array( 'sdk_version' => \Nobash\Backup\Addon\Registry::SDK_VERSION, 'addons' => $items ) );
	}

	/** Registered feature discovery. @return WP_REST_Response */
	public function features() {
		$features = array();
		foreach ( $this->container->get( \Nobash\Backup\Addon\Registry::class )->features() as $id => $row ) {
			$features[] = array( 'id' => $id, 'addon' => sanitize_key( (string) $row['addon'] ), 'meta' => is_array( $row['meta'] ) ? $row['meta'] : array() );
		}
		return new WP_REST_Response( array( 'features' => $features ) );
	}


	/**  */

	/** Core reusable backup profiles. */
	public function list_profiles() {
		$repo = new \Nobash\Backup\BackupProfile\ProfileRepository();
		$repo->ensure_defaults();
		return new WP_REST_Response( array( 'profiles'=>$repo->all() ) );
	}
	public function save_profile( WP_REST_Request $request ) {
		try { $profile=(new \Nobash\Backup\BackupProfile\ProfileRepository())->save((array)$request->get_json_params()); }
		catch(\Throwable $e){ return new WP_Error('nobash_profile_save',$e->getMessage(),array('status'=>400)); }
		AuditLog::record('backup_profile_saved','profile',(string)$profile['id'],array('profile_key'=>$profile['profile_key']));
		return new WP_REST_Response($profile,201);
	}
	public function delete_profile( WP_REST_Request $request ) {
		$id=absint($request['id']);
		foreach((new \Nobash\Backup\Schedule\ScheduleRepository())->all() as $schedule){if((int)$schedule['profile_id']===$id)return new WP_Error('nobash_profile_in_use',__('This profile is used by a backup schedule.','nobash-backup'),array('status'=>409));}
		try{$ok=(new \Nobash\Backup\BackupProfile\ProfileRepository())->delete($id);}catch(\Throwable $e){return new WP_Error('nobash_profile_delete',$e->getMessage(),array('status'=>400));}
		if(!$ok)return new WP_Error('nobash_profile_missing',__('Backup profile not found.','nobash-backup'),array('status'=>404));
		AuditLog::record('backup_profile_deleted','profile',(string)$id); return new WP_REST_Response(array('deleted'=>true));
	}

	/** Multiple local automatic backup schedules. */
	public function list_schedules() { return new WP_REST_Response(array('schedules'=>(new \Nobash\Backup\Schedule\ScheduleRepository())->all())); }
	public function save_schedule( WP_REST_Request $request ) {
		try{$schedule=(new \Nobash\Backup\Schedule\ScheduleRepository())->save((array)$request->get_json_params());$this->container->get(Scheduler::class)->ensure_events();}
		catch(\Throwable $e){return new WP_Error('nobash_schedule_save',$e->getMessage(),array('status'=>400));}
		AuditLog::record('backup_schedule_saved','schedule',(string)$schedule['id'],array('schedule_key'=>$schedule['schedule_key'])); return new WP_REST_Response($schedule,201);
	}
	public function delete_schedule( WP_REST_Request $request ) {
		$id=absint($request['id']);$repo=new \Nobash\Backup\Schedule\ScheduleRepository();
		if(!$repo->delete($id))return new WP_Error('nobash_schedule_missing',__('Backup schedule not found.','nobash-backup'),array('status'=>404));
		$this->container->get(Scheduler::class)->ensure_events(); AuditLog::record('backup_schedule_deleted','schedule',(string)$id); return new WP_REST_Response(array('deleted'=>true));
	}
	public function run_schedule( WP_REST_Request $request ) {
		$limited=RateLimiter::attempt('schedule_run',20,HOUR_IN_SECONDS);if(is_wp_error($limited))return $limited;
		$schedule=(new \Nobash\Backup\Schedule\ScheduleRepository())->find(absint($request['id']));if(!$schedule)return new WP_Error('nobash_schedule_missing',__('Backup schedule not found.','nobash-backup'),array('status'=>404));
		$job=$this->container->get(Scheduler::class)->dispatch_schedule($schedule,false,false);if(!$job)return new WP_Error('nobash_schedule_dispatch',__('The schedule could not start because another run is active or dispatch failed.','nobash-backup'),array('status'=>409));
		return new WP_REST_Response($this->container->get(JobEngine::class)->public_job($job),202);
	}

	public function protection_report(){return new WP_REST_Response((new \Nobash\Backup\Reporting\ReportService())->summary());}
	public function retention_preview(){return new WP_REST_Response((new \Nobash\Backup\Schedule\RetentionManager())->preview());}
	public function retention_run(){
		$limited=RateLimiter::attempt('retention_run',5,HOUR_IN_SECONDS);if(is_wp_error($limited))return $limited;
		$deleted=(new \Nobash\Backup\Schedule\RetentionManager())->run();return new WP_REST_Response(array('deleted'=>$deleted,'deleted_count'=>count($deleted)));
	}
	public function test_notifications(){
		$limited=RateLimiter::attempt('notification_test',5,HOUR_IN_SECONDS);if(is_wp_error($limited))return $limited;
		try{$result=(new \Nobash\Backup\Notifications\Notifier())->send_test();}catch(\Throwable $e){return new WP_Error('nobash_notification_test',$e->getMessage(),array('status'=>400));}
		AuditLog::record('notification_tested','settings','notifications');return new WP_REST_Response($result);
	}
	/**
	 * Send a support request through the site's WordPress mail transport.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_support_request( WP_REST_Request $request ) {
		$limited = RateLimiter::attempt( 'support_request', 5, HOUR_IN_SECONDS );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$name    = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email   = sanitize_email( (string) $request->get_param( 'email' ) );
		$subject = sanitize_text_field( (string) $request->get_param( 'subject' ) );
		$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );

		if ( strlen( $name ) < 2 || strlen( $name ) > 100 ) {
			return new WP_Error( 'nobash_support_name', __( 'Enter a valid name.', 'nobash-backup' ), array( 'status' => 400 ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'nobash_support_email', __( 'Enter a valid reply email address.', 'nobash-backup' ), array( 'status' => 400 ) );
		}
		if ( strlen( $subject ) < 5 || strlen( $subject ) > 160 ) {
			return new WP_Error( 'nobash_support_subject', __( 'Enter a subject between 5 and 160 characters.', 'nobash-backup' ), array( 'status' => 400 ) );
		}
		if ( strlen( $message ) < 20 || strlen( $message ) > 5000 ) {
			return new WP_Error( 'nobash_support_message', __( 'Enter a message between 20 and 5000 characters.', 'nobash-backup' ), array( 'status' => 400 ) );
		}

		$recipient = 'support@nobash.com';
		$mail_subject = sprintf( '[Nobash Backup Support] %s', $subject );
		$body = implode( "\n", array(
			'Nobash Backup Support Request',
			'',
			'Name: ' . $name,
			'Email: ' . $email,
			'Site: ' . home_url(),
			'WordPress: ' . get_bloginfo( 'version' ),
			'PHP: ' . PHP_VERSION,
			'Nobash Backup: ' . NOBASH_BACKUP_VERSION,
			'',
			'Message:',
			$message,
		) );
		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'Reply-To: ' . $name . ' <' . $email . '>',
		);

		$sent = wp_mail( $recipient, $mail_subject, $body, $headers );
		if ( ! $sent ) {
			return new WP_Error(
				'nobash_support_mail_failed',
				__( 'WordPress could not send the support email. Configure a working SMTP or mail service and try again.', 'nobash-backup' ),
				array( 'status' => 502 )
			);
		}

		AuditLog::record( 'support_request_sent', 'support', 'support@nobash.com' );
		return new WP_REST_Response( array(
			'sent'    => true,
			'message' => __( 'Your support request was sent to support@nobash.com.', 'nobash-backup' ),
		) );
	}

	public function verify_chain( WP_REST_Request $request ) {
		try{$result=(new \Nobash\Backup\Incremental\ChainHealthService())->verify((string)$request['chain_id']);}catch(\Throwable $e){return new WP_Error('nobash_chain_verify',$e->getMessage(),array('status'=>404));}
		return new WP_REST_Response($result);
	}
	public function repair_chain( WP_REST_Request $request ) {
		$limited=RateLimiter::attempt('chain_repair',5,HOUR_IN_SECONDS);if(is_wp_error($limited))return $limited;
		try{$result=(new \Nobash\Backup\Incremental\ChainHealthService())->repair((string)$request['chain_id']);}catch(\Throwable $e){return new WP_Error('nobash_chain_repair',$e->getMessage(),array('status'=>400));}
		AuditLog::record('backup_chain_repaired','chain',(string)$request['chain_id'],array('healthy'=>!empty($result['healthy'])));return new WP_REST_Response($result);
	}

}

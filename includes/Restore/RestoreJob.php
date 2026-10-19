<?php
/** Crash-safe, shadow-database, quarantine-first restore job. @package Nobash\Backup */
namespace Nobash\Backup\Restore;

use Nobash\Backup\Archive\NativeArchiveReader;
use Nobash\Backup\Archive\NativeArchiveWriter;
use Nobash\Backup\Database\Importer;
use Nobash\Backup\Compat\DatabaseAdapter;
use Nobash\Backup\Database\ShadowDatabaseManager;
use Nobash\Backup\Filesystem\ChunkFingerprint;
use Nobash\Backup\Filesystem\ServerFilePolicy;
use Nobash\Backup\Jobs\JobEngine;
use Nobash\Backup\Jobs\JobError;
use Nobash\Backup\Jobs\JobRepository;
use Nobash\Backup\Jobs\JobState;
use Nobash\Backup\Migration\SearchReplace;
use Nobash\Backup\Migration\UrlMigrator;
use Nobash\Backup\Security\PasswordArchive;
use Nobash\Backup\Security\PathBoundary;
use Nobash\Backup\Security\SafeTreeRemoval;
use Nobash\Backup\Storage\CredentialVault;
use Nobash\Backup\Storage\LocalStorage;

defined( 'ABSPATH' ) || exit;

/** Restore a verified archive without importing directly into live database tables. */
class RestoreJob {
	/** @var LocalStorage */ private $storage;
	/** @var JobRepository */ private $repo;
	/** @var JobEngine */ private $engine;
	/** @var array<string,mixed> */ private $active_cursor = array();

	public function __construct( LocalStorage $storage, JobRepository $repo, JobEngine $engine ) {
		$this->storage = $storage; $this->repo = $repo; $this->engine = $engine;
	}

	/** @return array<string,mixed> */
	public function process( array $job, $time_budget ) {
		$cursor = is_array( $job['cursor'] ?? null ) ? $job['cursor'] : array();
		$target_blog_id = absint( $cursor['target_blog_id'] ?? 0 );
		$switched = false;
		if ( $target_blog_id > 0 && function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_current_blog_id' ) && $target_blog_id !== (int) get_current_blog_id() ) {
			if ( ! function_exists( 'get_site' ) || ! get_site( $target_blog_id ) ) { throw new \RuntimeException( esc_html__( 'The target multisite blog no longer exists.', 'nobash-backup' ) ); }
			switch_to_blog( $target_blog_id );
			$switched = true;
		}
		try { return $this->process_in_blog_context( $job, $time_budget ); }
		finally { if ( $switched ) { restore_current_blog(); } }
	}

	/** Run the restore inside the selected site context.
	 * @param array<string,mixed> $job Job.
	 * @param float $time_budget Time budget.
	 * @return array<string,mixed>
	 */
	private function process_in_blog_context( array $job, $time_budget ) {
		$this->active_cursor = is_array( $job['cursor'] ) ? $job['cursor'] : array();
		try {
			$result = $this->process_phase( $job, $time_budget );
			$cursor = isset( $result['cursor'] ) && is_array( $result['cursor'] ) ? $result['cursor'] : (array) $job['cursor'];
			$status = (string) ( $result['status'] ?? 'running' );
			if ( in_array( $status, array( 'completed', 'completed_with_warnings' ), true ) ) {
				if ( 'commit_cleanup_pending' === (string) ( $cursor['phase'] ?? '' ) ) { EmergencyRecovery::mark( (int) $job['id'], $cursor, 'commit_pending' ); }
				else { EmergencyRecovery::clear( (int) $job['id'] ); }
			} elseif ( 'failed' !== $status ) { EmergencyRecovery::mark( (int) $job['id'], $cursor, 0 === strpos( (string) ( $cursor['phase'] ?? '' ), 'rollback_' ) ? 'recovering' : 'running' ); }
			return $result;
		} catch ( \Throwable $error ) {
			$c = ! empty( $this->active_cursor ) ? $this->active_cursor : ( is_array( $job['cursor'] ) ? $job['cursor'] : array() );
			$phase = (string) ( $c['phase'] ?? 'init' );
			if ( 0 === strpos( $phase, 'rollback_' ) || 'recovery_finalize' === $phase ) {
				EmergencyRecovery::mark( (int) $job['id'], $c, 'recovery_failed' );
				$reason = JobError::sanitize_message( $error->getMessage() );
				// The previous exception is not included by the job error serializer. Keep
				// its redacted cause in the displayed error so host failures can be diagnosed.
				throw new \RuntimeException( sprintf(
					/* translators: 1: Recovery phase, 2: Redacted recovery error. */
					esc_html__( 'Automatic restore recovery could not complete. The rollback data was preserved. Recovery step: %1$s. Cause: %2$s', 'nobash-backup' ),
					esc_html( sanitize_key( $phase ) ), esc_html( $reason )
				), 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Third argument is the original Throwable, not output; the displayed message and dynamic cause are escaped above.
			}
			$needs_recovery = ! empty( $c['database_published'] ) || ! empty( $c['filesystem_started'] ) || ! empty( $c['database_tables'] ) || ! empty( $c['database_map'] );
			if ( ! $needs_recovery ) { throw $error; }
			$c['failure_message'] = JobError::sanitize_message( $error->getMessage() );
			$c['failure_phase'] = sanitize_key( $phase );
			$c['rollback_offset'] = 0;
			unset( $c['rollback_index_offset'] );
			$c['phase'] = ! empty( $c['filesystem_started'] ) ? 'rollback_files' : 'rollback_objects_drop';
			EmergencyRecovery::mark( (int) $job['id'], $c, 'recovering' );
			return $this->response( $c, max( 1, (int) ( $job['progress'] ?? 1 ) ), 0, 0, 0, 0, __( 'Restore failed. Automatic rollback is running.', 'nobash-backup' ) );
		}
	}

	/** @return array<string,mixed> */
	private function process_phase( array $job, $time_budget ) {
		$c = is_array( $job['cursor'] ) ? $job['cursor'] : array();
		$id = (int) $job['id'];
		$phase = (string) ( $c['phase'] ?? 'init' );

		if ( 'init' === $phase ) {
			$filename = (string) ( $c['filename'] ?? '' );
			$source = $this->storage->resolve_archive( basename( $filename ) );
			if ( ! $source || ! is_readable( $source ) ) { throw new \RuntimeException( esc_html__( 'Backup archive not found.', 'nobash-backup' ) ); }
			$temp = $this->storage->job_temp_dir( $id );
			if ( PasswordArchive::is_password_protected( $source ) ) {
				$sealed = CredentialVault::open( (string) ( $c['password_sealed'] ?? '' ) );
				if ( empty( $sealed['password'] ) ) { throw new \RuntimeException( esc_html__( 'This backup is password protected. Enter its password to continue.', 'nobash-backup' ) ); }
				$plain = trailingslashit( $temp ) . 'decrypted-' . wp_generate_password( 12, false, false ) . '.nbh';
				$c['encrypted_source'] = $source; $c['decrypted_archive'] = $plain;
				$c['decrypt_state'] = PasswordArchive::init_decrypt( $source, $plain ); $c['phase'] = 'decrypt';
				return $this->response( $c, 1 );
			}
			return $this->initialize_plan( $c, $id, $filename, '' );
		}

		if ( 'decrypt' === $phase ) {
			$sealed = CredentialVault::open( (string) ( $c['password_sealed'] ?? '' ) );
			$password = isset( $sealed['password'] ) ? (string) $sealed['password'] : '';
			$r = PasswordArchive::decrypt_slice( (string) $c['encrypted_source'], (string) $c['decrypted_archive'], $password, (array) $c['decrypt_state'], max( 0.5, $time_budget - 0.25 ) );
			$c['decrypt_state'] = $r; $total = max( 1, (int) ( $r['header']['original_size'] ?? 1 ) );
			if ( empty( $r['done'] ) ) { return $this->response( $c, 1 + (int) floor( min( 1, (int) $r['dest_offset'] / $total ) * 10 ), 0, 0, (int) $r['dest_offset'], $total ); }
			$c['password_sealed'] = '';
			return $this->initialize_plan( $c, $id, (string) $c['filename'], (string) $c['decrypted_archive'] );
		}

		if ( 'safety_backup' === $phase ) {
			if ( empty( $c['safety_job_id'] ) ) {
				$c['previous_last_run'] = get_option( 'nobash_backup_last_run', array() );
				$child = $this->engine->create_backup( array( 'profile' => $this->safety_profile( (array) $c['plan']['components'] ), 'scope' => 'site' ) );
				$c['safety_job_id'] = (int) $child['id']; $c['safety_status'] = 'created';
				return $this->response( $c, 5 );
			}
			$child = $this->engine->run_slice( (int) $c['safety_job_id'], 'restore-safety' ); $c['safety_status'] = $child['status'];
			if ( in_array( $child['status'], array( JobState::FAILED, JobState::CANCELLED ), true ) ) {
				$error = strtolower( (string) ( $child['error'] ?? '' ) );
				$repairable = false !== strpos( $error, 'path cannot be empty' ) || false !== strpos( $error, 'archive checkpoint' );
				if ( $repairable && (int) ( $c['safety_repair_attempts'] ?? 0 ) < 1 ) {
					$c['safety_repair_attempts'] = 1;
					unset( $c['safety_job_id'] );
					$c['safety_status'] = 'restarting';
					return $this->response( $c, 5, 0, 0, 0, 0, __( 'Recreating the pre-restore safety backup from a clean checkpoint.', 'nobash-backup' ) );
				}
				throw new \RuntimeException( esc_html__( 'Restore blocked because the pre-restore safety backup failed.', 'nobash-backup' ) );
			}
			if ( ! JobState::is_terminal( $child['status'] ) ) { return $this->response( $c, 5 + (int) floor( ( (int) $child['progress'] ) * 0.10 ) ); }
			$c['safety_backup'] = $child['result']; $c['phase'] = 'extract';
			return $this->response( $c, 15 );
		}

		if ( 'extract' === $phase ) {
			$reader = new NativeArchiveReader( (string) $c['plan']['path'] );
			$r = $reader->extract_slice( $c['quarantine'], (int) $c['archive_offset'], max( 1.0, $time_budget - 0.35 ), 5000, $c['deployment_plan'], (array) ( $c['archive_final'] ?? array() ), (array) ( $c['archive_integrity'] ?? array() ), array_key_exists( 'deployment_plan_offset', $c ) ? (int) $c['deployment_plan_offset'] : null, array( ServerFilePolicy::class, 'retain_archive_path' ) );
			$c['archive_offset'] = $r['offset']; $c['archive_final'] = $r['final']; $c['archive_integrity'] = (array) ( $r['integrity'] ?? array() );
			if ( array_key_exists( 'plan_offset', $r ) && null !== $r['plan_offset'] ) { $c['deployment_plan_offset'] = (int) $r['plan_offset']; }
			$c['extracted_records'] = (int) ( $c['extracted_records'] ?? 0 ) + (int) $r['records'];
			$c['extracted_bytes'] = (int) ( $c['extracted_bytes'] ?? 0 ) + (int) $r['payload_bytes'];
			$progress = 15 + (int) floor( min( 1, $c['archive_offset'] / max( 1, (int) $c['plan']['archive_size'] ) ) * 25 );
			if ( $r['done'] ) { $c['phase'] = 'read_manifest'; }
			return $this->response( $c, $progress, $c['extracted_records'], 0, $c['extracted_bytes'], (int) $c['plan']['archive_size'] );
		}

		if ( 'read_manifest' === $phase ) {
			$manifest = \Nobash\Backup\Backup\Manifest::read( $c['quarantine'] ); $c['manifest'] = $manifest;
			$manifest_components = $this->manifest_components( $manifest );
			$c['plan']['available_components'] = $manifest_components;
			$c['plan']['components'] = $this->reconcile_components( (array) ( $c['plan']['components'] ?? array() ), $manifest_components );
			if ( empty( $c['plan']['components'] ) ) {
				throw new \RuntimeException( esc_html__( 'The selected restore content is not present in this backup archive.', 'nobash-backup' ) );
			}
			$c['search'] = $c['search'] ?: (string) ( $manifest['home'] ?? '' );
			$c['replace'] = $c['replace'] ?: ( (string) ( $c['target_home'] ?? '' ) ?: home_url() );
			$c['search_siteurl'] = (string) ( $manifest['siteurl'] ?? '' );
			$c['replace_siteurl'] = (string) ( $c['target_siteurl'] ?? '' ) ?: site_url();
			global $wpdb;
			$c['source_table_prefix'] = (string) ( $manifest['table_prefix'] ?? '' );
			$c['target_table_prefix'] = (string) ( $c['target_table_prefix'] ?? '' ) ?: $wpdb->prefix;
			if ( empty( $c['file_path_map'] ) && 'multisite_subsite' === (string) ( $manifest['archive_type'] ?? '' ) && ( ! is_multisite() || empty( $c['target_blog_id'] ) ) ) {
				$source_uploads = trim( str_replace( '\\', '/', (string) ( $manifest['uploads_relative'] ?? '' ) ), '/' );
				if ( '' !== $source_uploads ) {
					$target_uploads = \Nobash\Backup\Compat\Paths::uploads_relative();
					if ( '' !== $target_uploads ) { $c['file_path_map'] = array( $source_uploads => $target_uploads ); }
				}
			}
			$db = new ShadowDatabaseManager();
			$c['shadow_table_prefix'] = $db->shadow_prefix( $id ); $c['rollback_table_prefix'] = $db->rollback_prefix( $id ); $c['failed_table_prefix'] = $db->failed_prefix( $id );
			$expected_tables = array();
			foreach ( (array) ( $manifest['tables'] ?? array() ) as $source_table ) {
				$source_table = preg_replace( '/[^A-Za-z0-9_$]/', '', (string) $source_table );
				if ( '' !== $source_table && 0 === strpos( $source_table, (string) $c['source_table_prefix'] ) ) {
					$expected_tables[] = (string) $c['shadow_table_prefix'] . substr( $source_table, strlen( (string) $c['source_table_prefix'] ) );
				}
			}
			$c['database_tables'] = array_values( array_unique( $expected_tables ) ); $c['database_map'] = array(); $c['sql_offset'] = 0; $c['sql_parser_state'] = array( 'delimiter' => ';' );
			$c['phase'] = in_array( 'database', $c['plan']['components'], true ) ? 'import_db_shadow' : 'deploy_files';
			return $this->response( $c, 42 );
		}

		if ( 'import_db_shadow' === $phase ) {
			$sql = trailingslashit( $c['quarantine'] ) . 'database.sql';
			if ( ! is_readable( $sql ) ) {
				$manifest_components = $this->manifest_components( (array) ( $c['manifest'] ?? array() ) );
				if ( ! in_array( 'database', $manifest_components, true ) ) {
					$c['plan']['available_components'] = $manifest_components;
					$c['plan']['components'] = $this->reconcile_components( (array) ( $c['plan']['components'] ?? array() ), $manifest_components );
					$c['phase'] = 'deploy_files';
					return $this->response( $c, 58, 0, 0, 0, 0, __( 'This archive has no database component. Continuing with its available file content.', 'nobash-backup' ) );
				}
				throw new \RuntimeException( esc_html__( 'Database component was selected but database.sql is missing.', 'nobash-backup' ) );
			}
			$r = ( new Importer() )->import_chunk( $sql, (int) $c['sql_offset'], max( 1.0, $time_budget - 0.25 ), (string) $c['source_table_prefix'], (string) $c['shadow_table_prefix'], (array) ( $c['sql_parser_state'] ?? array() ), 'tables', (array) ( $c['database_tables'] ?? array() ) );
			$c['sql_offset'] = $r['offset']; $c['sql_parser_state'] = (array) ( $r['parser_state'] ?? array() ); $c['sql_statements'] = (int) ( $c['sql_statements'] ?? 0 ) + (int) $r['statements'];
			$c['database_tables'] = array_values( array_unique( array_merge( (array) $c['database_tables'], (array) $r['tables'] ) ) );
			$size = max( 1, (int) filesize( $sql ) );
			if ( $r['done'] ) { $c['phase'] = 'verify_db_shadow'; }
			return $this->response( $c, min( 58, 42 + (int) floor( ( $r['offset'] / $size ) * 16 ) ), (int) $c['sql_statements'], 0, (int) $r['offset'], $size );
		}

		if ( 'verify_db_shadow' === $phase ) {
			$db = new ShadowDatabaseManager();
			$c['database_verification'] = $db->verify( (array) $c['database_tables'] );
			$db->preserve_runtime_options( (string) $c['shadow_table_prefix'], (string) $c['target_table_prefix'] );
			$c['database_map'] = $db->build_map( (array) $c['database_tables'], (string) $c['shadow_table_prefix'], (string) $c['target_table_prefix'], (string) $c['rollback_table_prefix'] );
			$c['sr_pairs'] = array(); $c['phase'] = 'search_replace_shadow';
			$this->write_database_state( $c, 'verified' );
			return $this->response( $c, 60 );
		}

		if ( 'search_replace_shadow' === $phase ) {
			if ( empty( $c['sr_pairs'] ) ) {
				$c['sr_pairs'] = UrlMigrator::pairs( (string) $c['search'], (string) $c['replace'], (string) $c['search_siteurl'], (string) $c['replace_siteurl'] );

				// Migrate absolute filesystem paths as well as URLs. This is required when
				// WordPress-managed directories live outside the installation root, and
				// also when a site moves servers without changing its public URL.
				$manifest = (array) ( $c['manifest'] ?? array() );
				$source_site = untrailingslashit( wp_normalize_path( (string) ( $manifest['site_root'] ?? ( $manifest['abspath'] ?? '' ) ) ) );
				$target_site = untrailingslashit( wp_normalize_path( \Nobash\Backup\Compat\Paths::wordpress_root() ) );
				$path_pairs = array();
				$managed_roots = array(
					'uploads_root' => \Nobash\Backup\Compat\Paths::uploads_root(),
					'plugins_root' => \Nobash\Backup\Compat\Paths::plugins_root(),
					'themes_root'  => get_theme_root(),
				);
				foreach ( $managed_roots as $manifest_key => $target_root ) {
					$source_root = untrailingslashit( wp_normalize_path( (string) ( $manifest[ $manifest_key ] ?? '' ) ) );
					$target_root = untrailingslashit( wp_normalize_path( (string) $target_root ) );
					if ( '' !== $source_root && '' !== $target_root && $source_root !== $target_root ) {
						$path_pairs[] = array( $source_root, $target_root );
					}
				}
				if ( '' !== $source_site && '' !== $target_site && $source_site !== $target_site ) {
					$path_pairs[] = array( $source_site, $target_site );
				}
				usort( $path_pairs, static function ( $a, $b ) { return strlen( (string) $b[0] ) <=> strlen( (string) $a[0] ); } );
				$seen_paths = array();
				foreach ( $path_pairs as $pair ) {
					$key = (string) $pair[0];
					if ( '' === $key || isset( $seen_paths[ $key ] ) || $pair[0] === $pair[1] ) { continue; }
					$seen_paths[ $key ] = true;
					$c['sr_pairs'][] = $pair;
				}

				$c['sr_pair_idx'] = 0; $c['sr_idx'] = 0; $c['sr_cursor'] = array();
			}
			if ( empty( $c['sr_pairs'] ) ) { $c['phase'] = 'publish_db'; return $this->response( $c, 68 ); }
			$pairs = (array) $c['sr_pairs']; $pi = (int) $c['sr_pair_idx'];
			if ( $pi >= count( $pairs ) ) { $c['phase'] = 'publish_db'; return $this->response( $c, 68 ); }
			$tables = array_values( array_map( static function ( $row ) { return (string) $row['shadow']; }, (array) $c['database_map'] ) );
			$idx = (int) $c['sr_idx']; $cursor = (array) $c['sr_cursor']; $started = microtime( true ); $replace = new SearchReplace();
			while ( $idx < count( $tables ) && ( microtime( true ) - $started ) < max( 1.0, $time_budget - 0.25 ) ) {
				$r = $replace->process_table_cursor( (string) $pairs[ $pi ][0], (string) $pairs[ $pi ][1], $tables[ $idx ], $cursor, 500 );
				$cursor = $r['cursor']; if ( $r['done'] ) { ++$idx; $cursor = array(); }
				if ( 0 === $r['rows'] && ! $r['done'] ) { break; }
			}
			$c['sr_idx'] = $idx; $c['sr_cursor'] = $cursor;
			if ( $idx >= count( $tables ) ) { $c['sr_pair_idx'] = $pi + 1; $c['sr_idx'] = 0; $c['sr_cursor'] = array(); }
			return $this->response( $c, 60 + (int) floor( ( ( $pi + ( $idx / max( 1, count( $tables ) ) ) ) / max( 1, count( $pairs ) ) ) * 8 ) );
		}

		if ( 'publish_db' === $phase ) {
			$db = new ShadowDatabaseManager();
			$this->active_cursor = $c;
			$database_state = $db->state( (array) $c['database_map'] );
			if ( 'published' === $database_state ) {
				$c['database_published'] = true;
			} elseif ( 'prepared' === $database_state && empty( $c['database_published'] ) ) {
				$this->write_database_state( $c, 'publishing' );
				do_action( 'nobash_backup_restore_before_database_publish', $c, $job );
				$db->publish( (array) $c['database_map'] );
				$c['database_published'] = true;
				do_action( 'nobash_backup_restore_after_database_publish', $c, $job );
			} elseif ( 'rolled_back' === $database_state ) {
				throw new \RuntimeException( esc_html__( 'The database publish was rolled back before the restore could continue.', 'nobash-backup' ) );
			} else {
				throw new \RuntimeException( esc_html__( 'Database table state is inconsistent; automatic recovery is required.', 'nobash-backup' ) );
			}
			$this->active_cursor = $c; $this->write_database_state( $c, 'published' );
			wp_cache_flush();
			$c['phase'] = 'prepare_db_objects';
			return $this->response( $c, 70 );
		}

		if ( 'prepare_db_objects' === $phase ) {
			$objects_file = trailingslashit( (string) $c['quarantine'] ) . 'database-objects.sql';
			if ( ! is_readable( $objects_file ) || 0 === (int) filesize( $objects_file ) ) {
				$c['phase'] = 'deploy_files'; $c['deploy_offset'] = (int) ( $c['deploy_offset'] ?? 0 );
				return $this->response( $c, 72 );
			}
			$adapter = new DatabaseAdapter();
			$c['database_objects_target'] = $this->target_database_objects( (array) ( $c['manifest']['database_objects'] ?? array() ), (string) $c['source_table_prefix'], (string) $c['target_table_prefix'] );
			$c['database_objects_rollback_file'] = trailingslashit( (string) $c['rollback'] ) . 'database-objects.sql';
			$c['database_objects_previous'] = $adapter->write_object_snapshot( (array) $c['database_objects_target'], (string) $c['database_objects_rollback_file'] );
			$c['database_objects_offset'] = 0; $c['database_objects_parser_state'] = array( 'delimiter' => ';' );
			$c['phase'] = 'import_db_objects';
			return $this->response( $c, 71 );
		}

		if ( 'import_db_objects' === $phase ) {
			$objects_file = trailingslashit( (string) $c['quarantine'] ) . 'database-objects.sql';
			$r = ( new Importer() )->import_chunk( $objects_file, (int) ( $c['database_objects_offset'] ?? 0 ), max( 1.0, $time_budget - 0.25 ), (string) $c['source_table_prefix'], (string) $c['target_table_prefix'], (array) ( $c['database_objects_parser_state'] ?? array() ), 'objects', (array) ( $c['database_objects_target'] ?? array() ) );
			$c['database_objects_offset'] = (int) $r['offset']; $c['database_objects_parser_state'] = (array) $r['parser_state'];
			$c['database_object_statements'] = (int) ( $c['database_object_statements'] ?? 0 ) + (int) $r['statements'];
			if ( $r['done'] ) { $c['database_objects_applied'] = true; $c['phase'] = 'deploy_files'; $c['deploy_offset'] = (int) ( $c['deploy_offset'] ?? 0 ); }
			$size = max( 1, (int) filesize( $objects_file ) );
			return $this->response( $c, min( 74, 71 + (int) floor( ( (int) $r['offset'] / $size ) * 3 ) ), (int) ( $c['database_object_statements'] ?? 0 ) );
		}

		if ( 'deploy_files' === $phase ) {
			$c['filesystem_started'] = true; $this->active_cursor = $c;
			$r = $this->deploy_slice( $c, $time_budget ); $c = $r['cursor'];
			if ( $r['done'] ) { $c['phase'] = 'delete_files'; $c['delete_offset'] = 0; }
			return $this->response( $c, $r['done'] ? 86 : 70 + min( 15, (int) floor( $r['processed'] / 25 ) ), (int) ( $c['files_deployed'] ?? 0 ) );
		}

		if ( 'delete_files' === $phase ) {
			$c['filesystem_started'] = true; $this->active_cursor = $c;
			$r = $this->delete_slice( $c, $time_budget ); $c = $r['cursor'];
			if ( $r['done'] ) { $c['phase'] = 'post_restore'; }
			return $this->response( $c, $r['done'] ? 96 : 87, $r['deleted'] );
		}

		if ( 'post_restore' === $phase ) {
			try { ( new ArchiveInspector( $this->storage ) )->mark_verified( (string) $c['filename'], array( 'last_restore_at' => gmdate( 'c' ) ) ); }
			catch ( \Throwable $e ) { $c['warnings'][] = __( 'Restore completed, but archive verification metadata could not be updated.', 'nobash-backup' ); }
			wp_cache_flush();
			if ( function_exists( 'flush_rewrite_rules' ) ) { flush_rewrite_rules( false ); }
			\Nobash\Backup\Compat\WooCommerceSupport::after_restore();
			$c['post_restore_complete'] = true;
			$c['phase'] = 'extension_finalize';
			return $this->response( $c, 97 );
		}

		if ( 'extension_finalize' === $phase ) {
			$default = array( 'done' => true, 'state' => array(), 'message' => '' );
			$extension = apply_filters( 'nobash_backup_restore_extension_slice', $default, $c, $job, $time_budget );
			if ( ! is_array( $extension ) ) { throw new \RuntimeException( esc_html__( 'A restore extension returned an invalid finalization response.', 'nobash-backup' ) ); }
			$c['extension_state'] = isset( $extension['state'] ) && is_array( $extension['state'] ) ? $extension['state'] : array();
			if ( empty( $extension['done'] ) ) {
				return $this->response( $c, 97, 0, 0, 0, 0, isset( $extension['message'] ) ? sanitize_text_field( (string) $extension['message'] ) : __( 'Finalizing protected application data.', 'nobash-backup' ) );
			}
			if ( empty( $c['extension_finalized'] ) ) {
				do_action( 'nobash_backup_after_restore', $c );
				$c['extension_finalized'] = true;
			}
			$c['phase'] = 'commit_restore';
			return $this->response( $c, 98 );
		}

		if ( 'commit_restore' === $phase ) {
			$this->write_database_state( $c, 'commit_ready' );
			$c['database_committed'] = true;
			$safety_cleanup = array( 'filename' => '', 'deleted' => false );
			try { $safety_cleanup = $this->cleanup_successful_safety_backup( $c ); }
			catch ( \Throwable $e ) { $c['warnings'][] = __( 'Restore completed, but the temporary pre-restore safety backup cleanup could not finish.', 'nobash-backup' ); }
			if ( ! $safety_cleanup['deleted'] && ! empty( $safety_cleanup['filename'] ) ) { $c['warnings'][] = __( 'Restore completed, but the temporary pre-restore safety backup could not be removed automatically.', 'nobash-backup' ); }
			$report = array(
				'filename' => $c['filename'], 'components' => $c['plan']['components'],
				'safety_backup_deleted' => ! empty( $safety_cleanup['deleted'] ), 'files_deployed' => (int) ( $c['files_deployed'] ?? 0 ),
				'files_verified' => (int) ( $c['files_verified'] ?? 0 ), 'files_deleted' => (int) ( $c['files_deleted'] ?? 0 ),
				'database_statements' => (int) ( $c['sql_statements'] ?? 0 ), 'database_object_statements' => (int) ( $c['database_object_statements'] ?? 0 ), 'database_tables' => count( (array) ( $c['database_map'] ?? array() ) ),
				'database_strategy' => 'verified shadow import and rollback-preserving table swap',
				'warnings' => array_values( array_unique( (array) ( $c['warnings'] ?? array() ) ) ), 'rollback_committed' => true,
			);
			$c['phase'] = 'commit_cleanup_pending';
			$c['commit_ready'] = true;
			EmergencyRecovery::mark( $id, $c, 'commit_pending' );
			return array( 'cursor' => $c, 'progress' => 100, 'status' => empty( $report['warnings'] ) ? 'completed' : 'completed_with_warnings', 'result' => $report );
		}

		if ( 'rollback_files' === $phase ) {
			$this->active_cursor = $c;
			$r = $this->rollback_files_slice( $c, $time_budget ); $c = $r['cursor'];
			if ( $r['done'] ) { $c['phase'] = 'rollback_objects_drop'; }
			return $this->response( $c, max( 1, (int) ( $job['progress'] ?? 1 ) ), $r['processed'], 0, 0, 0, __( 'Restoring the pre-restore filesystem state.', 'nobash-backup' ) );
		}

		if ( 'rollback_objects_drop' === $phase ) {
			$this->active_cursor = $c;
			( new DatabaseAdapter() )->drop_objects( (array) ( $c['database_objects_target'] ?? array() ) );
			$c['database_objects_dropped'] = true; $c['phase'] = 'rollback_db';
			return $this->response( $c, max( 1, (int) ( $job['progress'] ?? 1 ) ), 0, 0, 0, 0, __( 'Removing restored database objects before table rollback.', 'nobash-backup' ) );
		}

		if ( 'rollback_db' === $phase ) {
			$this->active_cursor = $c;
			$db = new ShadowDatabaseManager();
			if ( ! empty( $c['database_map'] ) ) {
				$database_state = $db->state( (array) $c['database_map'] );
				if ( 'published' === $database_state ) {
					$db->rollback( (array) $c['database_map'], (string) $c['failed_table_prefix'] );
					$c['database_rolled_back'] = true;
				} elseif ( 'prepared' === $database_state ) {
					$db->cleanup( (array) $c['database_map'] );
				} elseif ( 'rolled_back' === $database_state ) {
					$c['database_rolled_back'] = true;
				} else {
					throw new \RuntimeException( esc_html__( 'Database rollback found a mixed table state and stopped without deleting recovery data.', 'nobash-backup' ) );
				}
				$db->cleanup( (array) $c['database_map'] );
			}
			// Also remove imported operational tables excluded from database_map.
			if ( ! empty( $c['database_tables'] ) ) { $db->drop_named( (array) $c['database_tables'] ); }
			$this->write_database_state( $c, 'rolled_back' );
			$rollback_objects = (string) ( $c['database_objects_rollback_file'] ?? '' );
			if ( is_readable( $rollback_objects ) && (int) filesize( $rollback_objects ) > 0 ) {
				$c['rollback_objects_offset'] = 0; $c['rollback_objects_parser_state'] = array( 'delimiter' => ';' ); $c['phase'] = 'rollback_objects_restore';
			} else { $c['phase'] = 'recovery_finalize'; }
			return $this->response( $c, max( 1, (int) ( $job['progress'] ?? 1 ) ), 0, 0, 0, 0, __( 'Database table rollback completed.', 'nobash-backup' ) );
		}

		if ( 'rollback_objects_restore' === $phase ) {
			$file = (string) $c['database_objects_rollback_file'];
			$r = ( new Importer() )->import_chunk( $file, (int) ( $c['rollback_objects_offset'] ?? 0 ), max( 1.0, $time_budget - 0.25 ), '', '', (array) ( $c['rollback_objects_parser_state'] ?? array() ), 'objects', (array) ( $c['database_objects_previous'] ?? array() ) );
			$c['rollback_objects_offset'] = (int) $r['offset']; $c['rollback_objects_parser_state'] = (array) $r['parser_state'];
			if ( $r['done'] ) { $c['database_objects_rolled_back'] = true; $c['phase'] = 'recovery_finalize'; }
			return $this->response( $c, max( 1, (int) ( $job['progress'] ?? 1 ) ), (int) $r['statements'], 0, 0, 0, __( 'Restoring the pre-restore database objects.', 'nobash-backup' ) );
		}

		if ( 'recovery_finalize' === $phase ) {
			EmergencyRecovery::clear( $id );
			$report = array(
				'filename' => (string) ( $c['filename'] ?? '' ), 'rollback_completed' => true,
				'database_rolled_back' => ! empty( $c['database_rolled_back'] ), 'filesystem_rolled_back' => true,
				'safety_backup_preserved' => ! empty( $c['safety_backup'] ), 'failed_phase' => (string) ( $c['failure_phase'] ?? '' ),
			);
			return array( 'cursor' => $c, 'progress' => (int) ( $job['progress'] ?? 1 ), 'status' => 'failed', 'error' => (string) ( $c['failure_message'] ?? __( 'Restore failed and was rolled back.', 'nobash-backup' ) ), 'result' => $report );
		}


		throw new \RuntimeException( esc_html__( 'Unknown restore phase.', 'nobash-backup' ) );
	}

	/** @return array<string,mixed> */
	private function initialize_plan( array $c, $id, $filename, $path_override ) {
		$plan       = ( new RestorePlan() )->build( $this->storage, $filename, (array) ( $c['components'] ?? array() ), $path_override );
		$temp       = $this->storage->job_temp_dir( $id );
		$quarantine = trailingslashit( $temp ) . 'quarantine';
		$rollback   = trailingslashit( $temp ) . 'rollback';

		$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();

		foreach ( array( $quarantine, $rollback ) as $workspace ) {
			if ( file_exists( $workspace ) || is_link( $workspace ) ) {
				SafeTreeRemoval::remove( $workspace, $temp );
			}
			if ( ! wp_mkdir_p( $workspace ) && ! is_dir( $workspace ) ) {
				throw new \RuntimeException( esc_html__( 'Could not create a secure restore workspace.', 'nobash-backup' ) );
			}
			if ( is_link( $workspace ) ) {
				throw new \RuntimeException( esc_html__( 'A restore workspace cannot be a symbolic link.', 'nobash-backup' ) );
			}
			$wp_filesystem->chmod( $workspace, 0700 );
		}

		$workspace_files = array( 'deployment.ndjson', 'restore-actions.ndjson', 'database-state.json' );
		foreach ( $workspace_files as $workspace_file ) {
			// These are fixed internal filenames. Inspect the lexical child before
			// resolving it so a stale final-component symlink can be removed safely
			// without following its target.
			$workspace_path = trailingslashit( $temp ) . $workspace_file;
			if ( is_link( $workspace_path ) ) {
				if ( ! $wp_filesystem->delete( $workspace_path, false, 'f' ) ) { throw new \RuntimeException( esc_html__( 'Could not reset a restore workspace file.', 'nobash-backup' ) ); }
			} elseif ( is_file( $workspace_path ) ) {
				if ( ! wp_delete_file( $workspace_path ) ) { throw new \RuntimeException( esc_html__( 'Could not reset a restore workspace file.', 'nobash-backup' ) ); }
			} elseif ( file_exists( $workspace_path ) ) {
				throw new \RuntimeException( esc_html__( 'A restore workspace file path is occupied by an unsafe filesystem object.', 'nobash-backup' ) );
			}
			PathBoundary::resolve( $temp, $workspace_file, true );
		}

		$c['job_id'] = $id; $c['plan'] = $plan; $c['temp'] = $temp; $c['quarantine'] = $quarantine; $c['rollback'] = $rollback;
		$c['deployment_plan'] = PathBoundary::resolve( $temp, 'deployment.ndjson', true ); $c['restore_journal'] = PathBoundary::resolve( $temp, 'restore-actions.ndjson', true );
		$c['database_state_file'] = PathBoundary::resolve( $temp, 'database-state.json', true ); $c['archive_offset'] = strlen( NativeArchiveWriter::MAGIC ); $c['archive_integrity'] = array(); $c['deployment_plan_offset'] = 0;
		$c['phase'] = empty( $c['skip_safety_backup'] ) ? 'safety_backup' : 'extract'; $c['warnings'] = array();
		return $this->response( $c, 3 );
	}

	/** Copy selected files, journal first, verify and atomically publish. @return array<string,mixed> */
	private function deploy_slice( array $c, $budget ) {
		$file = (string) $c['deployment_plan'];
		if ( ! is_readable( $file ) ) { return array( 'cursor' => $c, 'done' => true, 'processed' => 0 ); }
		$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
		$started   = microtime( true );
		$deadline  = $started + max( 1.0, (float) $budget - 0.25 );
		$processed = 0;
		$journal   = new RestoreJournal( (string) $c['restore_journal'] );

		while ( microtime( true ) < $deadline ) {
			$state = isset( $c['deploy_file_state'] ) && is_array( $c['deploy_file_state'] ) ? $c['deploy_file_state'] : array();
			if ( ! empty( $state ) && ( ServerFilePolicy::is_excluded( (string) ( $state['path'] ?? '' ) ) || ServerFilePolicy::is_excluded( (string) ( $state['source_rel'] ?? '' ) ) ) ) {
				$preserved_rel = (string) ( $state['path'] ?? '' );
				$preserved_dst = \Nobash\Backup\Compat\Paths::resolve_archive_path( $preserved_rel );
				$preserved_backup = PathBoundary::resolve( (string) $c['rollback'], 'replaced/' . $preserved_rel );
				// An older worker may already have moved the original. Recover it before
				// accepting the new policy; skipping here must never lose the host file.
				if ( is_file( $preserved_backup ) && ! $this->identical_small_files( $preserved_backup, $preserved_dst ) ) {
					throw new \RuntimeException( esc_html__( 'A host file was changed by an earlier restore step and must be recovered before continuing.', 'nobash-backup' ) );
				}
				$c['runtime_paths_preserved'] = true;
				$c['deploy_offset'] = (int) $state['next_plan_offset'];
				unset( $c['deploy_file_state'] );
				continue;
			}
			if ( empty( $state ) ) {
				// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/streaming primitives; resuming the deployment plan by byte offset requires a direct file handle.
				$fh = fopen( $file, 'rb' );
				if ( ! $fh ) { throw new \RuntimeException( esc_html__( 'Could not read the restore deployment plan.', 'nobash-backup' ) ); }
				try {
					$plan_offset = max( 0, (int) ( $c['deploy_offset'] ?? 0 ) );
					if ( 0 !== fseek( $fh, $plan_offset ) ) { throw new \RuntimeException( esc_html__( 'Could not resume the restore deployment plan.', 'nobash-backup' ) ); }
					$line = fgets( $fh );
					if ( false === $line ) { return array( 'cursor' => $c, 'done' => true, 'processed' => $processed ); }
					$next_plan_offset = (int) ftell( $fh );
				} finally { fclose( $fh ); }
				// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

				$entry = json_decode( $line, true );
				if ( ! is_array( $entry ) ) { $c['deploy_offset'] = $next_plan_offset; continue; }
				$source_rel = preg_replace( '#^files/#', '', (string) ( $entry['path'] ?? '' ) );
				$rel = $this->map_relative_path( $source_rel, (array) ( $c['file_path_map'] ?? array() ) );
				if ( ! $this->component_selected( $rel, (array) $c['plan']['components'] ) || $this->runtime_protected_path( $rel ) || ServerFilePolicy::is_excluded( $source_rel ) ) {
					if ( $this->runtime_protected_path( $rel ) ) { $c['runtime_paths_preserved'] = true; }
					$c['deploy_offset'] = $next_plan_offset;
					continue;
				}

				$src = trailingslashit( (string) $c['quarantine'] ) . 'files/' . $source_rel;
				$source_stat = @lstat( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$source_mode = is_array( $source_stat ) ? (int) ( $source_stat['mode'] ?? $source_stat[2] ?? 0 ) : 0;
				if ( ! is_array( $source_stat ) || 0100000 !== ( $source_mode & 0170000 ) || ! is_readable( $src ) || (int) ( $source_stat['size'] ?? $source_stat[7] ?? -1 ) !== (int) ( $entry['size'] ?? -2 ) ) {
					throw new \RuntimeException( esc_html__( 'A verified quarantine file is missing or changed.', 'nobash-backup' ) );
				}
				$dst = \Nobash\Backup\Compat\Paths::resolve_archive_path( $rel );
				$dir = dirname( $dst );
				if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) { throw new \RuntimeException( esc_html__( 'Could not create the restore destination directory.', 'nobash-backup' ) ); }
				$dst = \Nobash\Backup\Compat\Paths::resolve_archive_path( $rel );
				$state = array(
					'entry' => array(
						'path' => (string) ( $entry['path'] ?? '' ),
						'size' => (int) ( $entry['size'] ?? 0 ),
						'sha256' => strtolower( (string) ( $entry['sha256'] ?? '' ) ),
						'checksum_scheme' => (string) ( $entry['checksum_scheme'] ?? 'sha256' ),
					),
					'source_rel' => $source_rel,
					'path' => $rel,
					'next_plan_offset' => $next_plan_offset,
					'offset' => 0,
					'chain' => ChunkFingerprint::seed( 'file' ),
					'chunks' => 0,
					'source_size' => (int) ( $source_stat['size'] ?? $source_stat[7] ?? 0 ),
					'source_mtime' => (int) ( $source_stat['mtime'] ?? $source_stat[9] ?? 0 ),
					'source_ctime' => (int) ( $source_stat['ctime'] ?? $source_stat[10] ?? 0 ),
					'source_ino' => (int) ( $source_stat['ino'] ?? $source_stat[1] ?? 0 ),
					'source_dev' => (int) ( $source_stat['dev'] ?? $source_stat[0] ?? 0 ),
					'temp_path' => $dst . '.nbh-restore-' . (int) ( $c['job_id'] ?? 0 ) . '-' . substr( hash( 'sha256', $rel ), 0, 12 ) . '.part',
					'stage' => 'prepare',
				);
				$c['deploy_file_state'] = $state;
			}

			$entry      = (array) $state['entry'];
			$source_rel = (string) $state['source_rel'];
			$rel        = (string) $state['path'];
			$src        = trailingslashit( (string) $c['quarantine'] ) . 'files/' . $source_rel;
			$dst        = \Nobash\Backup\Compat\Paths::resolve_archive_path( $rel );
			$rollback   = trailingslashit( (string) $c['rollback'] ) . 'replaced/' . $rel;
			$tmp        = (string) $state['temp_path'];
			if ( is_link( $tmp ) || is_link( $dst ) ) { throw new \RuntimeException( esc_html__( 'Restore deployment blocked a symbolic-link target.', 'nobash-backup' ) ); }

			if ( 'prepare' === (string) $state['stage'] ) {
				/* Same-filesystem hard links turn the verified quarantine file into an O(1)
				 * prepared publish. If the host disables hard links or the paths live on
				 * different filesystems, fall back to the resumable bounded copy below. */
				$linked = false;
				$prepared_hardlink = is_file( $tmp ) && $this->same_regular_file( $src, $tmp );
				if ( $prepared_hardlink ) {
					/* A worker can stop after link() but before its cursor is committed. The
					 * orphan .part is already the verified source inode; never reopen it with
					 * w+b because doing so would truncate the quarantine source as well. */
					$this->assert_restore_source_unchanged( $src, $state, true );
					$state = $this->refresh_restore_source_ctime( $src, $state );
					$state['offset'] = (int) $state['source_size'];
					$state['prepared_fingerprint'] = (string) ( $entry['sha256'] ?? '' );
					$state['stage'] = 'publish';
				} elseif ( 0 === (int) $state['offset'] && ! file_exists( $tmp ) && function_exists( 'link' ) ) {
					$this->assert_restore_source_unchanged( $src, $state );
					$linked = @link( $src, $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
				if ( $linked ) {
					/* Creating a hard link legitimately changes the inode ctime. All other
					 * immutable source identity fields must still match, then the new ctime
					 * becomes the committed checkpoint for the publish phase. */
					$this->assert_restore_source_unchanged( $src, $state, true );
					$state = $this->refresh_restore_source_ctime( $src, $state );
					$state['offset'] = (int) $state['source_size'];
					$state['prepared_fingerprint'] = (string) ( $entry['sha256'] ?? '' );
					$state['stage'] = 'publish';
				} elseif ( ! $prepared_hardlink ) {
					$copy = $this->copy_restore_file_slice( $src, $tmp, $state, $deadline );
					$state = array_merge( $state, $copy['state'] );
					if ( empty( $copy['done'] ) ) {
						$c['deploy_file_state'] = $state;
						return array( 'cursor' => $c, 'done' => false, 'processed' => $processed );
					}
					$state['prepared_fingerprint'] = (string) $copy['fingerprint'];
					$state['stage'] = 'publish';
				}
				$c['deploy_file_state'] = $state;
			}

			if ( 'publish' === (string) $state['stage'] ) {
				$already_moved = is_file( $rollback );
				if ( is_file( $tmp ) && $this->same_regular_file( $src, $tmp ) ) {
					/* link() and the later directory-entry rename can both advance an
					 * inode's ctime without changing its verified payload. Device, inode,
					 * size and mtime remain mandatory; ctime is intentionally ignored for
					 * this already-proven hard-link publication window. */
					$this->assert_restore_source_unchanged( $src, $state, true );
				}
				/* A lost HTTP response may occur after rename(). When the quarantine
				 * hard link is already live, inode identity proves this action committed. */
				if ( is_file( $dst ) && ! is_link( $dst ) && $this->same_regular_file( $src, $dst ) ) {
					if ( is_file( $tmp ) ) { $wp_filesystem->delete( $tmp, false, 'f' ); }
					$c['files_deployed'] = (int) ( $c['files_deployed'] ?? 0 ) + 1;
					$c['files_verified'] = (int) ( $c['files_verified'] ?? 0 ) + 1;
					$c['deploy_offset'] = (int) $state['next_plan_offset'];
					unset( $c['deploy_file_state'] );
					++$processed;
					continue;
				}

				if ( ! is_file( $tmp ) || (int) filesize( $tmp ) !== (int) ( $entry['size'] ?? -1 ) ) {
					throw new \RuntimeException( esc_html__( 'The prepared restored file is missing or incomplete.', 'nobash-backup' ) );
				}
				$had_original = is_file( $dst ) && ! is_link( $dst );
				if ( ! $already_moved ) {
					$journal->append( array( 'action' => $had_original ? 'replace' : 'create', 'path' => $rel ) );
				}
				if ( $had_original && ! $already_moved ) {
					if ( ! wp_mkdir_p( dirname( $rollback ) ) && ! is_dir( dirname( $rollback ) ) ) { throw new \RuntimeException( esc_html__( 'Could not create the per-file rollback directory.', 'nobash-backup' ) ); }
					if ( ! $wp_filesystem->move( $dst, $rollback, true ) ) { throw new \RuntimeException( esc_html__( 'Could not create the per-file rollback copy.', 'nobash-backup' ) ); }
				}
				if ( is_file( $dst ) && DIRECTORY_SEPARATOR === '\\' && ! $wp_filesystem->delete( $dst, false, 'f' ) ) { throw new \RuntimeException( esc_html__( 'Could not replace the restored destination file.', 'nobash-backup' ) ); }
				if ( ! $wp_filesystem->move( $tmp, $dst, true ) ) { throw new \RuntimeException( esc_html__( 'Could not atomically publish a restored file.', 'nobash-backup' ) ); }
				if ( ! is_file( $dst ) || (int) filesize( $dst ) !== (int) ( $entry['size'] ?? -1 ) ) { throw new \RuntimeException( esc_html__( 'Published restored file size verification failed.', 'nobash-backup' ) ); }
				$c['files_deployed'] = (int) ( $c['files_deployed'] ?? 0 ) + 1;
				$c['files_verified'] = (int) ( $c['files_verified'] ?? 0 ) + 1;
				$c['deploy_offset'] = (int) $state['next_plan_offset'];
				unset( $c['deploy_file_state'] );
				++$processed;
			}
		}
		return array( 'cursor' => $c, 'done' => false, 'processed' => $processed );
	}

	/** Ensure a verified quarantine file did not mutate while a resumable publish was running. @return void */
	private function assert_restore_source_unchanged( $source, array $state, $allow_ctime_change = false ) {
		clearstatcache( true, $source );
		$stat = @lstat( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $stat )
			|| (int) ( $stat['size'] ?? $stat[7] ?? -1 ) !== (int) ( $state['source_size'] ?? -2 )
			|| (int) ( $stat['mtime'] ?? $stat[9] ?? -1 ) !== (int) ( $state['source_mtime'] ?? -2 )
			|| ( ! $allow_ctime_change && (int) ( $stat['ctime'] ?? $stat[10] ?? -1 ) !== (int) ( $state['source_ctime'] ?? -2 ) )
			|| ( (int) ( $state['source_ino'] ?? 0 ) > 0 && (int) ( $stat['ino'] ?? $stat[1] ?? 0 ) !== (int) $state['source_ino'] )
			|| ( (int) ( $state['source_dev'] ?? 0 ) > 0 && (int) ( $stat['dev'] ?? $stat[0] ?? 0 ) !== (int) $state['source_dev'] ) ) {
			throw new \RuntimeException( esc_html__( 'A verified quarantine file changed before it could be published.', 'nobash-backup' ) );
		}
	}

	/** Refresh the only source attribute that link()/unlink() is allowed to change. @return array<string,mixed> */
	private function refresh_restore_source_ctime( $source, array $state ) {
		clearstatcache( true, $source );
		$stat = @lstat( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $stat ) ) {
			throw new \RuntimeException( esc_html__( 'A verified quarantine file changed before it could be published.', 'nobash-backup' ) );
		}
		$state['source_ctime'] = (int) ( $stat['ctime'] ?? $stat[10] ?? 0 );
		return $state;
	}

	/** Compare regular-file identity without trusting inode numbers across devices. @return bool */
	private function same_regular_file( $left, $right ) {
		clearstatcache( true, $left );
		clearstatcache( true, $right );
		$left_stat  = @lstat( $left ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$right_stat = @lstat( $right ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $left_stat ) || ! is_array( $right_stat ) ) { return false; }
		$left_mode  = (int) ( $left_stat['mode'] ?? $left_stat[2] ?? 0 );
		$right_mode = (int) ( $right_stat['mode'] ?? $right_stat[2] ?? 0 );
		$left_ino   = (int) ( $left_stat['ino'] ?? $left_stat[1] ?? 0 );
		$right_ino  = (int) ( $right_stat['ino'] ?? $right_stat[1] ?? 0 );
		$left_dev   = (int) ( $left_stat['dev'] ?? $left_stat[0] ?? -1 );
		$right_dev  = (int) ( $right_stat['dev'] ?? $right_stat[0] ?? -2 );
		return 0100000 === ( $left_mode & 0170000 )
			&& 0100000 === ( $right_mode & 0170000 )
			&& $left_ino > 0
			&& $left_ino === $right_ino
			&& $left_dev === $right_dev;
	}

	/** Resumably copy an immutable source; archive records require their expected fingerprint.
	 * Only original rollback files omit archive-checksum comparison.
	 * @return array<string,mixed>
	 */
	private function copy_restore_file_slice( $source, $destination, array $state, $deadline, $verify_archive_checksum = true ) {
		$this->assert_restore_source_unchanged( $source, $state );
		if ( is_link( $destination ) ) { throw new \RuntimeException( esc_html__( 'The temporary restore destination is unsafe.', 'nobash-backup' ) ); }
		$offset = max( 0, min( (int) $state['source_size'], (int) ( $state['offset'] ?? 0 ) ) );
		$chain  = (string) ( $state['chain'] ?? ChunkFingerprint::seed( 'file' ) );
		$chunks = max( 0, (int) ( $state['chunks'] ?? 0 ) );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/streaming primitives; resumable, fixed-size-chunked copying of a large quarantine file requires direct file handles.
		$in = fopen( $source, 'rb' );
		$out = fopen( $destination, 0 === $offset ? 'w+b' : 'c+b' );
		if ( ! $in || ! $out ) { if ( $in ) { fclose( $in ); } if ( $out ) { fclose( $out ); } throw new \RuntimeException( esc_html__( 'Could not prepare a restored file for deployment.', 'nobash-backup' ) ); }
		$copied = 0;
		try {
			if ( ! $verify_archive_checksum && ! \Nobash\Backup\Filesystem\DirectFilesystem::get()->chmod( $destination, 0600 ) ) { throw new \RuntimeException( esc_html__( 'Could not protect the temporary rollback copy.', 'nobash-backup' ) ); }
			$out_stat = fstat( $out );
			$out_size = is_array( $out_stat ) && isset( $out_stat['size'] ) ? (int) $out_stat['size'] : 0;
			if ( $out_size < $offset ) { throw new \RuntimeException( esc_html__( 'The temporary restore file lost a committed checkpoint.', 'nobash-backup' ) ); }
			if ( $out_size > $offset && ! ftruncate( $out, $offset ) ) { throw new \RuntimeException( esc_html__( 'Could not rewind an uncommitted restore file tail.', 'nobash-backup' ) ); }
			if ( 0 !== fseek( $in, $offset ) || 0 !== fseek( $out, $offset ) ) { throw new \RuntimeException( esc_html__( 'Could not resume a restored file copy.', 'nobash-backup' ) ); }
			while ( $offset < (int) $state['source_size'] && microtime( true ) < (float) $deadline && $copied < 1073741824 ) {
				$length = min( 16777216, (int) $state['source_size'] - $offset, 1073741824 - $copied );
				$data = stream_get_contents( $in, $length );
				if ( false === $data || strlen( $data ) !== $length ) { throw new \RuntimeException( esc_html__( 'Could not read a restored quarantine file chunk.', 'nobash-backup' ) ); }
				$data_hash = hash( 'sha256', $data );
				$written = 0;
				while ( $written < $length ) {
					$n = fwrite( $out, 0 === $written ? $data : substr( $data, $written ) );
					if ( false === $n || 0 === $n ) { throw new \RuntimeException( esc_html__( 'Could not write a restored deployment file chunk.', 'nobash-backup' ) ); }
					$written += $n;
				}
				$chain = ChunkFingerprint::advance( $chain, $data_hash, $offset, $length, $chunks );
				++$chunks;
				$offset += $length;
				$copied += $length;
			}
			if ( ! fflush( $out ) ) { throw new \RuntimeException( esc_html__( 'Could not checkpoint a restored deployment file.', 'nobash-backup' ) ); }
			if ( function_exists( 'fsync' ) ) { @fsync( $out ); }
		} finally { fclose( $in ); fclose( $out ); }
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->assert_restore_source_unchanged( $source, $state );

		$state['offset'] = $offset;
		$state['chain']  = $chain;
		$state['chunks'] = $chunks;
		if ( $offset < (int) $state['source_size'] ) { return array( 'done' => false, 'state' => $state, 'fingerprint' => '' ); }

		$entry = (array) $state['entry'];
		$scheme = (string) ( $entry['checksum_scheme'] ?? '' );
		$expected = strtolower( (string) ( $entry['sha256'] ?? '' ) );
		if ( $verify_archive_checksum && ChunkFingerprint::FILE_SCHEME !== $scheme ) { throw new \RuntimeException( esc_html__( 'This .nbh file-record version is not supported.', 'nobash-backup' ) ); }
		$fingerprint = ChunkFingerprint::finalize( $chain, $offset, $chunks, 'file' );
		if ( ! is_string( $fingerprint ) || ! preg_match( '/^[a-f0-9]{64}$/', strtolower( $fingerprint ) ) || ( $verify_archive_checksum && ( ! preg_match( '/^[a-f0-9]{64}$/', $expected ) || ! hash_equals( $expected, strtolower( $fingerprint ) ) ) ) ) {
			throw new \RuntimeException( esc_html__( 'A restored file failed integrity verification before deployment.', 'nobash-backup' ) );
		}
		return array( 'done' => true, 'state' => $state, 'fingerprint' => strtolower( $fingerprint ) );
	}

	/** Apply an incremental deleted-file ledger with rollback copies. @return array<string,mixed> */
	private function delete_slice( array $c, $budget ) {
		$file = trailingslashit( (string) $c['quarantine'] ) . 'meta/deleted-files.ndjson'; if ( ! is_readable( $file ) ) { return array( 'cursor' => $c, 'done' => true, 'deleted' => 0 ); }
		$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/streaming primitives; resuming the deleted-files ledger by byte offset requires a direct file handle.
		$fh = fopen( $file, 'rb' ); fseek( $fh, (int) ( $c['delete_offset'] ?? 0 ) ); $started = microtime( true ); $count = 0; $journal = new RestoreJournal( (string) $c['restore_journal'] );
		while ( ( microtime( true ) - $started ) < max( 1.0, (float) $budget - 0.25 ) && ( $line = fgets( $fh ) ) !== false ) {
			$entry = json_decode( $line, true ); if ( ! is_array( $entry ) || empty( $entry['deleted'] ) ) { continue; }
			$source_rel = ltrim( str_replace( '\\', '/', (string) ( $entry['path'] ?? '' ) ), '/' );
			$rel = $this->map_relative_path( $source_rel, (array) ( $c['file_path_map'] ?? array() ) );
			if ( '' === $rel || false !== strpos( $rel, '../' ) || preg_match( '/^[A-Za-z]:\//', $rel ) || ! $this->component_selected( $rel, (array) $c['plan']['components'] ) ) { continue; }
			if ( $this->runtime_protected_path( $rel ) || ServerFilePolicy::is_excluded( $source_rel ) ) { $c['runtime_paths_preserved'] = true; continue; }
			$dst = \Nobash\Backup\Compat\Paths::resolve_archive_path( $rel );
			if ( is_file( $dst ) && ! is_link( $dst ) ) {
				$journal->append( array( 'action' => 'delete', 'path' => $rel ) ); $rollback = trailingslashit( $c['rollback'] ) . 'deleted/' . $rel; wp_mkdir_p( dirname( $rollback ) );
				if ( ! is_file( $rollback ) && ! $wp_filesystem->move( $dst, $rollback, true ) ) { fclose( $fh ); throw new \RuntimeException( esc_html__( 'Could not preserve a file before applying an incremental deletion.', 'nobash-backup' ) ); }
				++$count; $c['files_deleted'] = (int) ( $c['files_deleted'] ?? 0 ) + 1;
			}
		}
		$c['delete_offset'] = (int) ftell( $fh ); $done = feof( $fh ); fclose( $fh );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array( 'cursor' => $c, 'done' => $done, 'deleted' => $count );
	}

	/** @return array<string,mixed> */
	private function rollback_files_slice( array $c, $budget ) {
		$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
		$journal = new RestoreJournal( (string) $c['restore_journal'] ); $deadline = microtime( true ) + max( 1.0, (float) $budget - 0.25 ); $processed = 0;
		$position = array_key_exists( 'rollback_index_offset', $c ) ? (int) $c['rollback_index_offset'] : null; $done = false;
		while ( microtime( true ) < $deadline && ! $done ) {
			// Advance the reverse checkpoint only after this action has completed.
			$slice = $journal->read_reverse_slice( $position, 1 );
			foreach ( $slice['entries'] as $entry ) {
				$c['rollback_index_offset'] = (int) $slice['position'] + RestoreJournal::INDEX_RECORD_BYTES;
				$rel = ltrim( str_replace( '\\', '/', (string) ( $entry['path'] ?? '' ) ), '/' ); if ( '' === $rel || false !== strpos( $rel, '../' ) ) { continue; }
				$dst = \Nobash\Backup\Compat\Paths::resolve_archive_path( $rel ); $action = (string) ( $entry['action'] ?? '' );
				if ( 'replace' === $action || 'delete' === $action ) {
					$backup = PathBoundary::resolve( (string) $c['rollback'], ( 'replace' === $action ? 'replaced/' : 'deleted/' ) . $rel, true );
					if ( is_file( $backup ) ) {
						$r = $this->restore_rollback_file_slice( $c, $backup, $dst, $rel, $deadline ); $c = $r['cursor'];
						if ( ! $r['done'] ) { return array( 'cursor' => $c, 'done' => false, 'processed' => $processed ); }
					}
				} elseif ( 'create' === $action && ( is_file( $dst ) || is_link( $dst ) ) ) {
					if ( ! $wp_filesystem->delete( $dst, false, 'f' ) || file_exists( $dst ) || is_link( $dst ) ) {
						throw new \RuntimeException( sprintf( /* translators: %s: Relative file path. */ esc_html__( 'Could not remove the restored file during rollback: %s', 'nobash-backup' ), esc_html( $rel ) ) );
					}
				}
				++$processed;
			}
			$position = (int) $slice['position']; $done = ! empty( $slice['done'] ); if ( empty( $slice['entries'] ) ) { $done = true; }
		}
		$c['rollback_index_offset'] = (int) $position; if ( $done ) { $c['filesystem_rolled_back'] = true; }
		return array( 'cursor' => $c, 'done' => $done, 'processed' => $processed );
	}

	/** Restore an original file without deleting the current destination first.
	 * Same-device rename is atomic. Other devices use a resumable adjacent copy;
	 * its final rename preserves both existing files if publication is denied.
	 * @return array<string,mixed>
	 */
	private function restore_rollback_file_slice( array $c, $backup, $dst, $rel, $deadline ) {
		if ( is_link( $backup ) || is_link( $dst ) || is_dir( $dst ) ) { throw new \RuntimeException( esc_html__( 'The rollback file destination is unsafe.', 'nobash-backup' ) ); }
		if ( ! is_dir( dirname( $dst ) ) && ! wp_mkdir_p( dirname( $dst ) ) ) {
			throw new \RuntimeException( sprintf( /* translators: %s: Relative file path. */ esc_html__( 'Could not create the rollback destination directory for: %s', 'nobash-backup' ), esc_html( $rel ) ) );
		}
		clearstatcache( true, $backup );
		$stat = @lstat( $backup ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $stat ) || ! is_readable( $backup ) ) { throw new \RuntimeException( sprintf( /* translators: %s: Relative file path. */ esc_html__( 'The preserved rollback file is not readable: %s', 'nobash-backup' ), esc_html( $rel ) ) ); }
		$state = isset( $c['rollback_file_state'] ) && is_array( $c['rollback_file_state'] ) ? $c['rollback_file_state'] : array();
		// Some hosts prohibit replacing .user.ini. A failed WP_Filesystem move can
		// leave an identical rollback copy while the original never left its place.
		// Confirm the bytes before treating that journal action as already undone.
		if ( $this->host_php_configuration_path( $rel ) && $this->identical_small_files( $backup, $dst ) ) {
			if ( ! empty( $state ) ) {
				$temp = (string) ( $state['temp_path'] ?? '' );
				$prefix = $dst . '.nbh-rollback-' . (int) ( $c['job_id'] ?? 0 ) . '-';
				if ( (string) ( $state['path'] ?? '' ) !== $rel || ! preg_match( '/^' . preg_quote( $prefix, '/' ) . '[A-Za-z0-9]{32}\.part$/D', $temp ) ) { throw new \RuntimeException( esc_html__( 'The rollback file checkpoint does not match its journal action.', 'nobash-backup' ) ); }
				if ( is_file( $temp ) && ! is_link( $temp ) && ! \Nobash\Backup\Filesystem\DirectFilesystem::get()->delete( $temp, false, 'f' ) ) {
					$c['warnings'][] = __( 'The original PHP configuration is intact, but a temporary rollback copy could not be removed.', 'nobash-backup' );
				}
			}
			unset( $c['rollback_file_state'] );
			return array( 'cursor' => $c, 'done' => true );
		}
		if ( empty( $state ) ) {
			$parent_stat = @stat( dirname( $dst ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			// WP_Filesystem_Direct::move(..., true) unlinks an existing destination
			// before rename. Use a same-device rename to preserve it on failure.
			if ( isset( $stat['dev'], $parent_stat['dev'] ) && (int) $stat['dev'] === (int) $parent_stat['dev']
				&& @rename( $backup, $dst ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- Same-device atomic replacement; WP_Filesystem::move deletes the destination first.
				return array( 'cursor' => $c, 'done' => true );
			}
			$rollback_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
			if ( ! $rollback_filesystem->is_writable( dirname( $dst ) ) ) { throw new \RuntimeException( sprintf( /* translators: %s: Relative file path. */ esc_html__( 'The rollback destination directory is not writable for: %s', 'nobash-backup' ), esc_html( $rel ) ) ); }
			$state = array(
				'path' => $rel, 'temp_path' => $dst . '.nbh-rollback-' . (int) ( $c['job_id'] ?? 0 ) . '-' . wp_generate_password( 32, false, false ) . '.part',
				'source_size' => (int) $stat['size'], 'source_mtime' => (int) $stat['mtime'], 'source_ctime' => (int) $stat['ctime'],
				'source_ino' => (int) $stat['ino'], 'source_dev' => (int) $stat['dev'],
				'entry' => array(), 'offset' => 0, 'chain' => ChunkFingerprint::seed( 'file' ), 'chunks' => 0,
			);
			// Persist the unpredictable temporary name before writing any private data.
			$c['rollback_file_state'] = $state;
			return array( 'cursor' => $c, 'done' => false );
		}
		$temp = (string) ( $state['temp_path'] ?? '' );
		$prefix = $dst . '.nbh-rollback-' . (int) ( $c['job_id'] ?? 0 ) . '-';
		if ( (string) ( $state['path'] ?? '' ) !== $rel || ! preg_match( '/^' . preg_quote( $prefix, '/' ) . '[A-Za-z0-9]{32}\.part$/D', $temp ) ) { throw new \RuntimeException( esc_html__( 'The rollback file checkpoint does not match its journal action.', 'nobash-backup' ) ); }
		if ( is_link( $temp ) || ( is_file( $temp ) && $this->same_regular_file( $backup, $temp ) ) ) { throw new \RuntimeException( esc_html__( 'The temporary rollback destination is unsafe.', 'nobash-backup' ) ); }
		$c['rollback_file_state'] = $state; $this->active_cursor = $c;
		try {
			// These are the original site files, not archive records; they have no
			// archive fingerprint. Source identity is checked on each bounded slice.
			$r = $this->copy_restore_file_slice( $backup, (string) $state['temp_path'], $state, $deadline, false );
			$c['rollback_file_state'] = $r['state']; $this->active_cursor = $c;
			if ( ! $r['done'] ) { return array( 'cursor' => $c, 'done' => false ); }
			if ( ! \Nobash\Backup\Filesystem\DirectFilesystem::get()->chmod( $temp, (int) $stat['mode'] & 0777 ) ) { throw new \RuntimeException( esc_html__( 'Could not preserve the original rollback file permissions.', 'nobash-backup' ) ); }
			if ( function_exists( 'error_clear_last' ) ) { error_clear_last(); }
			if ( ! @rename( (string) $state['temp_path'], $dst ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- Adjacent atomic replacement must not pre-delete the destination.
				$last = error_get_last();
				$reason = JobError::sanitize_message( (string) ( $last['message'] ?? 'Destination replacement was denied by the filesystem.' ) );
				throw new \RuntimeException( $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Redacted internal cause is escaped by the enclosing exception below.
			}
			unset( $c['rollback_file_state'] );
			return array( 'cursor' => $c, 'done' => true );
		} catch ( \Throwable $error ) {
			throw new \RuntimeException( sprintf( /* translators: 1: Relative file path, 2: Redacted filesystem error. */ esc_html__( 'Could not restore file %1$s during rollback. Cause: %2$s', 'nobash-backup' ), esc_html( $rel ), esc_html( JobError::sanitize_message( $error->getMessage() ) ) ), 0, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Third argument preserves the original Throwable; both message substitutions are escaped.
		}
	}

	/** @return void */
	private function write_database_state( array $c, $status ) {
		$path = (string) ( $c['database_state_file'] ?? '' ); if ( '' === $path ) return;
		$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
		$data = array( 'status' => sanitize_key( (string) $status ), 'job_id' => (int) ( $c['job_id'] ?? 0 ), 'map' => (array) ( $c['database_map'] ?? array() ), 'updated_at' => gmdate( 'c' ) );
		$tmp = $path . '.tmp'; if ( false === $wp_filesystem->put_contents( $tmp, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), FS_CHMOD_FILE ) || ! $wp_filesystem->move( $tmp, $path, true ) ) { $wp_filesystem->delete( $tmp, false, 'f' ); throw new \RuntimeException( esc_html__( 'Could not persist the database restore state.', 'nobash-backup' ) ); }
	}

	/** @return array<string,mixed> */
	private function cleanup_successful_safety_backup( array $cursor ) {
		$safety = isset( $cursor['safety_backup'] ) && is_array( $cursor['safety_backup'] ) ? $cursor['safety_backup'] : array(); $filename = isset( $safety['filename'] ) ? basename( (string) $safety['filename'] ) : ''; $source = isset( $cursor['filename'] ) ? basename( (string) $cursor['filename'] ) : '';
		$last_run_restored = true; if ( array_key_exists( 'previous_last_run', $cursor ) ) { $previous = $cursor['previous_last_run']; $last_run_restored = is_array( $previous ) && ! empty( $previous ) ? (bool) update_option( 'nobash_backup_last_run', $previous, false ) || get_option( 'nobash_backup_last_run', array() ) === $previous : (bool) delete_option( 'nobash_backup_last_run' ) || false === get_option( 'nobash_backup_last_run', false ); }
		if ( '' === $filename ) return array( 'filename' => '', 'deleted' => true, 'metadata_deleted' => true, 'last_run_restored' => $last_run_restored, 'reason' => 'not_created' );
		if ( $filename === $source ) return array( 'filename' => $filename, 'deleted' => false, 'metadata_deleted' => false, 'last_run_restored' => $last_run_restored, 'reason' => 'filename_collision' );
		$deleted = $this->storage->delete_archive( $filename ); $metadata_deleted = false; $reason = $deleted ? '' : 'archive_delete_failed';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Literal transaction-control statements (no dynamic input) wrapping prepared $wpdb->delete() calls against dynamic backup-chain metadata tables; not cacheable.
		if ( $deleted ) { global $wpdb; $chain_id = isset( $safety['chain_id'] ) ? sanitize_text_field( (string) $safety['chain_id'] ) : ''; if ( '' === $chain_id ) $metadata_deleted = true; else { $points_table = $wpdb->prefix . 'nobash_restore_points'; $chains_table = $wpdb->prefix . 'nobash_backup_chains'; $wpdb->query( 'START TRANSACTION' ); $points_ok = false !== $wpdb->delete( $points_table, array( 'chain_id' => $chain_id ), array( '%s' ) ); $chain_ok = false !== $wpdb->delete( $chains_table, array( 'chain_id' => $chain_id ), array( '%s' ) ); $metadata_deleted = $points_ok && $chain_ok; if ( $metadata_deleted ) { $wpdb->query( 'COMMIT' ); } else { $wpdb->query( 'ROLLBACK' ); } if ( ! $metadata_deleted ) $reason = 'metadata_cleanup_failed'; } }
		return array( 'filename' => $filename, 'deleted' => (bool) $deleted && (bool) $metadata_deleted, 'archive_deleted' => (bool) $deleted, 'metadata_deleted' => (bool) $metadata_deleted, 'last_run_restored' => (bool) $last_run_restored, 'reason' => $reason );
	}

	/** @return string */
	private function safety_profile( array $components ) {
		$components = array_values( array_unique( array_map( 'sanitize_key', $components ) ) );
		if ( 1 === count( $components ) && in_array( $components[0], array( 'database', 'files', 'uploads', 'plugins', 'themes' ), true ) ) { return $components[0]; }
		$db = in_array( 'database', $components, true );
		$files = array_intersect( array( 'files', 'uploads', 'plugins', 'themes' ), $components );
		if ( $db && empty( $files ) ) return 'database';
		if ( ! $db && ! empty( $files ) ) return 1 === count( $files ) ? (string) reset( $files ) : 'files';
		return 'full';
	}

	/** @return array<int,string> */
	private function manifest_components( array $manifest ) {
		$profile = sanitize_key( (string) ( $manifest['profile'] ?? '' ) );
		switch ( $profile ) {
			case 'database': return array( 'database' );
			case 'uploads': return array( 'uploads' );
			case 'plugins': return array( 'plugins' );
			case 'themes': return array( 'themes' );
			case 'files': return array( 'files' );
			case 'full': return array( 'database', 'files' );
		}
		$components = array();
		if ( is_readable( trailingslashit( (string) $this->active_cursor['quarantine'] ) . 'database.sql' ) || ! empty( $manifest['tables'] ) ) { $components[] = 'database'; }
		if ( ! empty( $manifest['files_count'] ) || ! empty( $manifest['files_packed'] ) ) { $components[] = 'files'; }
		return $components ? $components : array( 'database', 'files' );
	}

	/** @return array<int,string> */
	private function reconcile_components( array $selected, array $available ) {
		$out = array();
		$file_components = array( 'files', 'uploads', 'plugins', 'themes' );
		if ( in_array( 'database', $selected, true ) && in_array( 'database', $available, true ) ) { $out[] = 'database'; }
		foreach ( $file_components as $component ) {
			if ( ! in_array( $component, $selected, true ) ) { continue; }
			if ( 'files' === $component ) {
				foreach ( $file_components as $available_component ) { if ( in_array( $available_component, $available, true ) ) { $out[] = $available_component; } }
			} elseif ( in_array( $component, $available, true ) || in_array( 'files', $available, true ) ) { $out[] = $component; }
		}
		return array_values( array_unique( $out ) );
	}
	/** Map a verified archive-relative path to a multisite destination path.
	 * @param string $relative Source path.
	 * @param array<string,string> $mapping Prefix mapping.
	 * @return string
	 */
	private function map_relative_path( $relative, array $mapping ) {
		$relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
		if ( '' === $relative || false !== strpos( $relative, '../' ) || preg_match( '/^[A-Za-z]:\//', $relative ) ) { throw new \RuntimeException( esc_html__( 'The archive contains an unsafe mapped path.', 'nobash-backup' ) ); }
		uksort( $mapping, static function ( $a, $b ) { return strlen( (string) $b ) <=> strlen( (string) $a ); } );
		foreach ( $mapping as $from => $to ) {
			$from = trim( str_replace( '\\', '/', (string) $from ), '/' );
			$to = trim( str_replace( '\\', '/', (string) $to ), '/' );
			if ( '' === $from || false !== strpos( $from, '..' ) || false !== strpos( $to, '..' ) ) { continue; }
			if ( $relative === $from || 0 === strpos( $relative, $from . '/' ) ) {
				$relative = $to . substr( $relative, strlen( $from ) );
				break;
			}
		}
		return ltrim( $relative, '/' );
	}

	/** Keep the active recovery runtime and credentials outside a destructive restore. @return bool */
	private function runtime_protected_path( $relative ) {
		$relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );
		if ( 'wp-config.php' === $relative || ServerFilePolicy::is_excluded( $relative ) ) { return true; }
		$prefixes = array();
		$plugin_rel = \Nobash\Backup\Compat\Paths::archive_relative( NOBASH_BACKUP_PATH );
		$uploads_rel = \Nobash\Backup\Compat\Paths::uploads_relative();
		$storage_rel = \Nobash\Backup\Compat\Paths::archive_relative( LocalStorage::get_directory() );
		foreach ( array( $plugin_rel, $storage_rel ) as $protected ) {
			if ( '' !== $protected ) { $prefixes[] = trailingslashit( trim( $protected, '/' ) ); }
		}
		if ( '' !== $uploads_rel ) {
			$prefixes[] = trailingslashit( $uploads_rel ) . 'nobash-backups/';
			$prefixes[] = trailingslashit( $uploads_rel ) . 'nobash-backup/';
		}
		foreach ( $prefixes as $prefix ) { if ( 0 === strpos( $relative, $prefix ) ) { return true; } }
		return false;
	}

	/** Preserve the destination host's per-directory PHP configuration. @return bool */
	private function host_php_configuration_path( $relative ) {
		return ServerFilePolicy::is_php_configuration( $relative );
	}

	/** Bounded equality check for already-recovered host configuration files. @return bool */
	private function identical_small_files( $left, $right ) {
		clearstatcache( true, $left ); clearstatcache( true, $right );
		if ( is_link( $left ) || is_link( $right ) || ! is_file( $left ) || ! is_file( $right ) || ! is_readable( $left ) || ! is_readable( $right ) ) { return false; }
		$before_left = @lstat( $left ); $before_right = @lstat( $right ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $before_left ) || ! is_array( $before_right ) || $before_left['size'] !== $before_right['size'] || $before_left['size'] > 1048576 ) { return false; }
		$left_hash = @hash_file( 'sha256', $left ); $right_hash = @hash_file( 'sha256', $right ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		clearstatcache( true, $left ); clearstatcache( true, $right );
		$after_left = @lstat( $left ); $after_right = @lstat( $right ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( array( 'dev', 'ino', 'size', 'mtime', 'ctime' ) as $key ) {
			if ( ! isset( $after_left[ $key ], $after_right[ $key ] ) || $before_left[ $key ] !== $after_left[ $key ] || $before_right[ $key ] !== $after_right[ $key ] ) { return false; }
		}
		return is_string( $left_hash ) && is_string( $right_hash ) && hash_equals( $left_hash, $right_hash );
	}

	/** @return bool */
	private function component_selected( $rel, array $components ) {
		if ( in_array( 'files', $components, true ) ) { return true; }
		$rel = trim( str_replace( '\\', '/', (string) $rel ), '/' );
		$map = array(
			'uploads' => \Nobash\Backup\Compat\Paths::uploads_relative(),
			'plugins' => \Nobash\Backup\Compat\Paths::plugins_relative(),
			'themes'  => \Nobash\Backup\Compat\Paths::themes_relative(),
		);
		foreach ( $map as $component => $prefix ) {
			$prefix = trim( str_replace( '\\', '/', (string) $prefix ), '/' );
			if ( in_array( $component, $components, true ) && '' !== $prefix && ( $rel === $prefix || 0 === strpos( $rel, $prefix . '/' ) ) ) { return true; }
		}
		return false;
	}

	/** Map archived database object identifiers to the destination prefix. @return array<int,array<string,string>> */
	private function target_database_objects( array $objects, $source_prefix, $target_prefix ) {
		$out = array();
		foreach ( $objects as $object ) {
			if ( ! is_array( $object ) ) { continue; }
			$type = strtolower( sanitize_key( (string) ( $object['type'] ?? '' ) ) );
			$name = preg_replace( '/[^A-Za-z0-9_$-]/', '', (string) ( $object['name'] ?? '' ) );
			$table = preg_replace( '/[^A-Za-z0-9_$-]/', '', (string) ( $object['table'] ?? '' ) );
			if ( '' === $name || ! in_array( $type, array( 'view','trigger','procedure','function','event' ), true ) ) { continue; }
			if ( '' !== $source_prefix && 0 === strpos( $name, $source_prefix ) ) { $name = $target_prefix . substr( $name, strlen( $source_prefix ) ); }
			if ( '' !== $source_prefix && 0 === strpos( $table, $source_prefix ) ) { $table = $target_prefix . substr( $table, strlen( $source_prefix ) ); }
			$out[] = array( 'type' => $type, 'name' => substr( $name, 0, 64 ), 'table' => substr( $table, 0, 64 ) );
		}
		return $out;
	}

	/** @return array<string,mixed> */
	private function response( array $c, $progress, $items = 0, $total = 0, $bytes = 0, $total_bytes = 0, $message = '' ) {
		$out = array( 'cursor' => $c, 'progress' => max( 0, min( 100, (int) $progress ) ), 'status' => 'running', 'processed_items' => (int) $items, 'total_items' => (int) $total, 'processed_bytes' => (int) $bytes, 'total_bytes' => (int) $total_bytes );
		if ( '' !== $message ) $out['error'] = $message; return $out;
	}
}

<?php
/**
 * Resumable backup job processor.
 *
 * @package Nobash\Backup
 */
namespace Nobash\Backup\Backup;
use Nobash\Backup\Database\Dumper;
use Nobash\Backup\Database\AdaptiveDatabaseController;
use Nobash\Backup\Database\BackupSnapshotManager;
use Nobash\Backup\Filesystem\AdaptiveWorkController;
use Nobash\Backup\Archive\NativeArchiveWriter;
use Nobash\Backup\Filesystem\Exclusions;
use Nobash\Backup\Filesystem\FileInventoryRepository;
use Nobash\Backup\Filesystem\ResumableFileScanner;
use Nobash\Backup\Jobs\JobRepository;
use Nobash\Backup\Storage\LocalStorage;
use Nobash\Backup\Incremental\BackupMode;
use Nobash\Backup\Incremental\ChainRepository;
use Nobash\Backup\Incremental\SnapshotRepository;
use Nobash\Backup\Security\PasswordArchive;
use Nobash\Backup\Storage\CredentialVault;
defined( 'ABSPATH' ) || exit;
/** Process one bounded and checkpointed backup slice. */
class BackupJob {
	/** @var LocalStorage */
	private $storage;
	/** @var JobRepository */
	private $repo;
	/** @var FileInventoryRepository */
	private $inventory;
	/** @var AdaptiveWorkController */
	private $adaptive;
	/** @var AdaptiveDatabaseController */
	private $db_adaptive;
	public function __construct( LocalStorage $storage, JobRepository $repo ) {
		$this->storage = $storage;
		$this->repo = $repo;
		$this->inventory = new FileInventoryRepository();
		$this->adaptive = new AdaptiveWorkController();
		$this->db_adaptive = new AdaptiveDatabaseController();
	}
	/**
		 * Process one chunk.
		 *
		 * @param array<string,mixed> $job Job.
		 * @param float $time_budget Time budget.
		 * @return array<string,mixed>
		 */
	public function process( array $job, $time_budget ) {
		$cursor = is_array( $job['cursor'] ?? null ) ? $job['cursor'] : array();
		$target_blog_id = absint( $cursor['target_blog_id'] ?? 0 );
		$switched = false;
		if ( $target_blog_id > 0 && function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_current_blog_id' ) && $target_blog_id !== (int) get_current_blog_id() ) {
			if ( ! function_exists( 'get_site' ) || ! get_site( $target_blog_id ) ) {
				throw new \RuntimeException( esc_html__( 'The selected multisite blog no longer exists.', 'nobash-backup' ) );
			}
			switch_to_blog( $target_blog_id );
			$switched = true;
		}
		try {
			return $this->process_in_blog_context( $job, $time_budget );
		}
		finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
	/** Process the backup inside the selected site context.
		 * @param array<string,mixed> $job Job.
		 * @param float $time_budget Time budget.
		 * @return array<string,mixed>
		 */
	private function process_in_blog_context( array $job, $time_budget ) {
		$started = microtime( true );
		$deadline = $started + max( 1.0, (float) $time_budget - 0.35 );
		$cursor = is_array( $job['cursor'] ) ? $job['cursor'] : array();
		$id = (int) $job['id'];
		$profile = isset( $cursor['profile'] ) ? $cursor['profile'] : 'full';
		$scope = isset( $cursor['scope'] ) ? $cursor['scope'] : 'site';
		$phase = isset( $cursor['phase'] ) ? $cursor['phase'] : 'init';

		/* Repair durable cursors created by an interrupted/older build. Healthy
		 * jobs are left untouched; only missing transient paths are reconstructed. */
		$cursor = $this->repair_resume_cursor( $cursor, $id );
		$phase  = isset( $cursor['phase'] ) ? (string) $cursor['phase'] : 'init';
		if ( 'init' === $phase ) {
			$temp = $this->storage->job_temp_dir( $id );
			$cursor['temp'] = $temp;
			$cursor['scope'] = \Nobash\Backup\Compat\Multisite::normalize_scope( $scope );
			$cursor['warnings'] = array();
			$cursor['backup_mode'] = BackupMode::normalize( $cursor['backup_mode'] ?? 'full' );
			$chains = new ChainRepository();
			if ( BackupMode::FULL === $cursor['backup_mode'] ) {
				if ( empty( $cursor['chain_id'] ) ) {
					$cursor['chain_id'] = $chains->create_chain( (string) ( $cursor['profile_key'] ?? $profile ) );
				}
				$cursor['parent_point_id'] = '';
			} else {
				$chain = $chains->get_chain( (string) ( $cursor['chain_id'] ?? '' ) );
				if ( ! $chain || 'active' !== $chain['status'] ) {
					throw new \RuntimeException( esc_html__( 'A valid active backup chain is required.', 'nobash-backup' ) );
				}
				if ( $chains->should_compact( $chain ) ) {
					$cursor['warnings'][] = __( 'The incremental chain reached its safe length; a new full base backup was created.', 'nobash-backup' );
					$cursor['backup_mode'] = BackupMode::FULL;
					$cursor['chain_id'] = $chains->create_chain( (string) ( $cursor['profile_key'] ?? $profile ) );
					$cursor['parent_point_id'] = '';
				} else {
					$cursor['parent_point_id'] = BackupMode::parent( $chain, $cursor['backup_mode'] );
				}
				if ( BackupMode::FULL !== $cursor['backup_mode'] && '' === $cursor['parent_point_id'] ) {
					throw new \RuntimeException( esc_html__( 'The backup chain has no verified parent restore point.', 'nobash-backup' ) );
				}
			}
			$objects_sql = trailingslashit( $temp ) . 'database-objects.sql';
			$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
			if ( false === $wp_filesystem->put_contents( $objects_sql, '', FS_CHMOD_FILE ) ) {
				throw new \RuntimeException( esc_html__( 'Could not initialize the database object export file.', 'nobash-backup' ) );
			}
			$dumper = new Dumper();
			$custom_tables = (array) ( $cursor['profile_include_tables'] ?? array() );
			$include_database = in_array( $profile, array( 'full', 'database' ), true ) || ! empty( $custom_tables );
			if ( $include_database ) {
				$cursor['direct_database_archive'] = true;
				$cursor['archive_name'] = LocalStorage::allocate_archive_filename( (string) ( $cursor['profile_key'] ?? $profile ) );
				$cursor['archive'] = trailingslashit( $temp ) . $cursor['archive_name'] . '.partial';
				$writer = new NativeArchiveWriter( $cursor['archive'] );
				$cursor['archive_offset'] = $writer->prepare( 0 );
				$cursor = $this->append_database_stream( $writer, $cursor, $dumper->preamble(), false );
				$cursor['archive_integrity_checkpoint'] = $writer->checkpoint();
				$tables = $dumper->tables( $cursor['scope'] );
				if ( ! empty( $custom_tables ) ) {
					global $wpdb;
					$requested = array_fill_keys( array_map( 'strval', $custom_tables ), true );
					$tables = array_values( array_filter( $tables, static function ( $table ) use ( $requested, $wpdb ) {
						$short = 0 === strpos( $table, $wpdb->prefix ) ? substr( $table, strlen( $wpdb->prefix ) ) : $table;
						return isset( $requested[ $table ] ) || isset( $requested[ $short ] );
					} ) );
					if ( empty( $tables ) ) {
						throw new \RuntimeException( esc_html__( 'None of the selected database tables are available.', 'nobash-backup' ) );
					}
				}
				$cursor['tables'] = $tables;
				$cursor['table_idx'] = 0;
				$cursor['db_cursor'] = array();
				$cursor['db_rows'] = 0;
				$cursor['db_bytes'] = 0;
				$snapshot_manager = new BackupSnapshotManager();
				$cursor['database_snapshot_queue'] = $snapshot_manager->non_transactional_tables( $tables );
				$cursor['database_snapshot_idx'] = 0;
				$cursor['database_snapshots'] = array();
				if ( ! empty( $cursor['database_snapshot_queue'] ) ) {
					$cursor['warnings'][] = __( 'Non-transactional database tables are being frozen into private InnoDB snapshots before export to prevent mixed-state backups.', 'nobash-backup' );
				}
				$cursor['phase'] = empty( $cursor['database_snapshot_queue'] ) ? 'database' : 'database_snapshot';
			} else {
				$cursor['phase'] = 'files_seed';
			}
			return $this->response( $cursor, 5, $id );
		}
		if ( 'database_snapshot' === $phase ) {
			$queue = is_array( $cursor['database_snapshot_queue'] ?? null ) ? array_values( $cursor['database_snapshot_queue'] ) : array();
			$idx = (int) ( $cursor['database_snapshot_idx'] ?? 0 );
			$map = is_array( $cursor['database_snapshots'] ?? null ) ? $cursor['database_snapshots'] : array();
			if ( $idx < count( $queue ) ) {
				$source = (string) $queue[ $idx ];
				$map[ $source ] = ( new BackupSnapshotManager() )->snapshot_table( $source, $id );
				$cursor['database_snapshots'] = $map;
				$cursor['database_snapshot_idx'] = ++$idx;
			}
			if ( $idx >= count( $queue ) ) { $cursor['phase'] = 'database'; }
			return $this->response( $cursor, min( 5, 3 + (int) floor( ( $idx / max( 1, count( $queue ) ) ) * 2 ) ), $id );
		}

		if ( 'database' === $phase ) {
			$dumper = new Dumper();
			$tables = isset( $cursor['tables'] ) && is_array( $cursor['tables'] ) ? $cursor['tables'] : array();
			$idx = (int) ( $cursor['table_idx'] ?? 0 );
			$db_cursor = isset( $cursor['db_cursor'] ) && is_array( $cursor['db_cursor'] ) ? $cursor['db_cursor'] : array();

			/* Database export is streamed directly into database.sql records inside
			 * the native archive. The old temporary-SQL branch was removed when the
			 * direct archive writer was introduced, but two stale `$direct` checks
			 * remained. Because `$direct` was never initialized PHP treated it as
			 * false and called Dumper::write_epilogue()/checkpoint() with an
			 * undefined `$sql` path, which is the source of the user-visible
			 * "Path cannot be empty" retry loop. Enforce the current archive
			 * invariant explicitly and fail with a useful message if a damaged
			 * cursor somehow reaches this phase without an archive path. */
			$archive_path = isset( $cursor['archive'] ) ? (string) $cursor['archive'] : '';
			if ( '' === $archive_path ) {
				throw new \RuntimeException( esc_html__( 'The backup archive checkpoint is missing. Retry the backup from a clean checkpoint.', 'nobash-backup' ) );
			}
			$cursor['direct_database_archive'] = true;
			$writer = new NativeArchiveWriter( $archive_path );
			$cursor['archive_offset'] = $writer->prepare( (int) ( $cursor['archive_offset'] ?? 0 ), (array) ( $cursor['archive_integrity_checkpoint'] ?? array() ) );
			$total_t = max( 1, count( $tables ) );
			while ( microtime( true ) < $deadline && $idx < count( $tables ) ) {
				$table = $tables[ $idx ];
				$source_table = isset( $cursor['database_snapshots'][ $table ] ) ? (string) $cursor['database_snapshots'][ $table ] : (string) $table;
				if ( empty( $db_cursor ) ) {
					$cursor = $this->append_database_stream( $writer, $cursor, $dumper->create_block( $table, $source_table ), false );
					$db_cursor = $dumper->plan( $source_table );
				}
				$db_limits = $this->db_adaptive->limits( $cursor );
				$db_started = microtime( true );
				$result = $dumper->render_chunk( $source_table, $db_cursor, $db_limits['rows'], $db_limits['statement_bytes'], $table );
				if ( '' !== (string) $result['sql'] ) {
					$cursor = $this->append_database_stream( $writer, $cursor, (string) $result['sql'], false );
				}
				$cursor = $this->db_adaptive->learn( $cursor, microtime( true ) - $db_started, $result['rows'], $result['bytes'], $this->memory_pressure() );
				$db_cursor = $result['cursor'];
				$cursor['db_rows'] = (int) ( $cursor['db_rows'] ?? 0 ) + $result['rows'];
				$cursor['db_bytes'] = (int) ( $cursor['db_bytes'] ?? 0 ) + $result['bytes'];
				if ( ! empty( $result['warnings'] ) ) {
					$cursor['warnings'] = array_values( array_unique( array_merge( (array) $cursor['warnings'], $result['warnings'] ) ) );
				}
				if ( $result['done'] ) {
					++$idx;
					$db_cursor = array();
				}
				if ( 0 === $result['rows'] && ! $result['done'] ) {
					break;
				}
			}
			$cursor['table_idx'] = $idx;
			$cursor['db_cursor'] = $db_cursor;
			if ( $idx >= count( $tables ) ) {
				$cursor = $this->append_database_stream( $writer, $cursor, $dumper->epilogue(), true );
				$cursor['package_step'] = 'database_objects';
				$cursor['database_objects'] = in_array( $profile, array( 'full', 'database' ), true ) || ! empty( $cursor['include_database_objects'] ) ? $dumper->objects() : array();
				if ( 'custom' === $profile && ! empty( $cursor['profile_include_tables'] ) && ! empty( $cursor['database_objects'] ) ) {
					$selected_objects = array_fill_keys( array_map( 'strval', (array) $cursor['tables'] ), true );
					$cursor['database_objects'] = array_values( array_filter( (array) $cursor['database_objects'], static function ( $object ) use ( $selected_objects ) {
						$type = sanitize_key( (string) ( $object['type'] ?? '' ) );
						$name = (string) ( $object['name'] ?? '' );
						$table = (string) ( $object['table'] ?? '' );
						if ( 'view' === $type ) {
							return isset( $selected_objects[ $name ] );
						}
						if ( 'trigger' === $type ) {
							return isset( $selected_objects[ $table ] );
						}
						return false;
					} ) );
				}
				$cursor['database_object_idx'] = 0;
				$cursor['phase'] = ! empty( $cursor['database_snapshots'] ) ? 'database_snapshot_cleanup' : 'database_objects';
			}
			$cursor['archive_integrity_checkpoint'] = $writer->checkpoint();
			return $this->response( $cursor, min( 43, 5 + (int) floor( ( $idx / $total_t ) * 38 ) ), $id );
		}

		if ( 'database_snapshot_cleanup' === $phase ) {
			( new BackupSnapshotManager() )->cleanup( is_array( $cursor['database_snapshots'] ?? null ) ? $cursor['database_snapshots'] : array() );
			$cursor['database_snapshots'] = array();
			$cursor['database_snapshot_queue'] = array();
			$cursor['phase'] = 'database_objects';
			return $this->response( $cursor, 43, $id );
		}

		if ( 'database_objects' === $phase ) {
			$dumper = new Dumper();
			$objects = is_array( $cursor['database_objects'] ?? null ) ? $cursor['database_objects'] : array();
			$idx = (int) ( $cursor['database_object_idx'] ?? 0 );
			$sql = trailingslashit( $cursor['temp'] ) . 'database-objects.sql';
			$dumper->prepare_file( $sql, (int) ( $cursor['database_objects_file_offset'] ?? 0 ) );
			while ( microtime( true ) < $deadline && $idx < count( $objects ) ) {
				try {
					$dumper->write_object( $objects[ $idx ], $sql );
				}
				catch ( \Throwable $error ) {
					$cursor['warnings'][] = sanitize_text_field( $error->getMessage() );
				}
				++$idx;
			}
			$cursor['database_object_idx'] = $idx;
			if ( $idx >= count( $objects ) ) {
				$has_custom_files = 'custom' === $profile && ! empty( $cursor['profile_include_paths'] );
				$cursor['phase'] = ( 'database' === $profile || ( 'custom' === $profile && ! $has_custom_files ) ) ? 'package' : 'files_seed';
			}
			$cursor['database_objects_file_offset'] = $dumper->checkpoint( $sql );
			return $this->response( $cursor, empty( $objects ) ? 45 : min( 45, 43 + (int) floor( ( $idx / max( 1, count( $objects ) ) ) * 2 ) ), $id );
		}
		if ( 'files_seed' === $phase ) {
			$scanner = new ResumableFileScanner( $this->inventory );
			if ( ! empty( $cursor['profile_include_paths'] ) ) {
				$roots = array();
				foreach ( (array) ( $cursor['profile_include_paths'] ?? array() ) as $relative ) {
					try {
						$roots[] = \Nobash\Backup\Security\PathBoundary::resolve( \Nobash\Backup\Compat\Paths::wordpress_root(), (string) $relative );
					} catch ( \Throwable $error ) {
						$cursor['warnings'][] = $error->getMessage();
					}
				}
				if ( empty( $roots ) ) {
					$cursor['phase'] = 'package';
					return $this->response( $cursor, 86, $id );
				}
				$scanner->seed_roots( $id, array_values( array_unique( $roots ) ) );
			} else {
				$scanner->seed( $id, $profile, $cursor['scope'] );
			}
			$cursor['phase'] = 'files_discover';
			$cursor['scan_complete'] = false;
			$cursor['pack_after_id'] = 0;
			if ( empty( $cursor['archive'] ) || empty( $cursor['archive_name'] ) ) {
				$cursor['archive_name'] = LocalStorage::allocate_archive_filename( (string) ( $cursor['profile_key'] ?? $profile ) );
				$cursor['archive'] = trailingslashit( $cursor['temp'] ) . $cursor['archive_name'] . '.partial';
			}
			$cursor['checksums_file'] = trailingslashit( $cursor['temp'] ) . 'file-checksums.ndjson';
			if ( false === \Nobash\Backup\Filesystem\DirectFilesystem::get()->put_contents( $cursor['checksums_file'], '', 0640 ) ) {
				throw new \RuntimeException( esc_html__( 'Could not initialize the backup checksum index.', 'nobash-backup' ) );
			}
			$writer = new NativeArchiveWriter( $cursor['archive'] );
			$cursor['archive_offset'] = $writer->prepare( (int) ( $cursor['archive_offset'] ?? 0 ), (array) ( $cursor['archive_integrity_checkpoint'] ?? array() ) );
			$cursor['archive_integrity_checkpoint'] = $writer->checkpoint();
			$cursor['active_file_id'] = 0;
			$cursor['active_file_offset'] = 0;
			$cursor['active_file_chain'] = \Nobash\Backup\Filesystem\ChunkFingerprint::seed( 'file' );
			$cursor['active_file_chunks'] = 0;
			return $this->response( $cursor, 46, $id );
		}
		if ( 'files_discover' === $phase ) {
			$limits = $this->adaptive->limits( $cursor );
			$scanner = new ResumableFileScanner( $this->inventory );
			$cursor = $scanner->scan( $id, $cursor, max( $limits['files'] * 8, (int) ( $limits['scan_entries'] ?? 0 ) ), max( $limits['bytes'] * 4, (int) ( $limits['scan_bytes'] ?? 0 ) ), $deadline );
			$cursor = $this->adaptive->learn( $cursor, microtime( true ) - $started, $this->memory_pressure() );
			$stats = ! empty( $cursor['scan_complete'] ) ? $this->inventory->stats( $id ) : $this->cursor_stats( $cursor );
			if ( ! empty( $cursor['scan_complete'] ) ) {
				$cursor['phase'] = 'files_pack';
				$cursor['total_files'] = $stats['files'];
				$cursor['total_file_bytes'] = $stats['bytes'];
				$cursor['packed_files'] = $stats['packed'];
				$cursor['packed_bytes'] = $stats['packed_bytes'];
				$cursor['skipped_files'] = $stats['skipped'];
				$cursor['unchanged_files'] = $stats['unchanged'];
				if ( BackupMode::FULL !== ( $cursor['backup_mode'] ?? BackupMode::FULL ) ) {
					$cursor['deleted_file_index'] = trailingslashit( $cursor['temp'] ) . 'deleted-files.ndjson';
					$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
					$wp_filesystem->put_contents( $cursor['deleted_file_index'], '', FS_CHMOD_FILE );
					$cursor['deleted_files'] = ( new SnapshotRepository() )->write_deleted( (string) $cursor['parent_point_id'], $id, $cursor['deleted_file_index'] );
				}
			}
			// Discovery totals are unknown until completed; keep progress honest and bounded.
			$progress = empty( $cursor['scan_complete'] ) ? min( 60, 47 + (int) floor( log( max( 1, (int) ( $cursor['scan_entries'] ?? 1 ) ), 10 ) * 3 ) ) : 61;
			return $this->response( $cursor, $progress, $id, $stats );
		}
		if ( 'files_pack' === $phase ) {
			$limits = $this->adaptive->limits( $cursor );
			$writer = new NativeArchiveWriter( $cursor['archive'] );
			$cursor['archive_offset'] = $writer->prepare( (int) ( $cursor['archive_offset'] ?? 0 ), (array) ( $cursor['archive_integrity_checkpoint'] ?? array() ) );
			$this->inventory->rewind_file_states_after( $id, (int) ( $cursor['pack_after_id'] ?? 0 ) );
			$rows = $this->inventory->ready_files( $id, (int) ( $cursor['pack_after_id'] ?? 0 ), max( 1, min( 5000, $limits['files'] ) ) );
			if ( empty( $rows ) ) {
				$stats = $this->inventory->stats( $id );
				$cursor['packed_files'] = $stats['packed'];
				$cursor['packed_bytes'] = $stats['packed_bytes'];
				$cursor['skipped_files'] = $stats['skipped'];
				$cursor['unchanged_files'] = $stats['unchanged'];
				$cursor['phase'] = 'package';
				$cursor['archive_integrity_checkpoint'] = $writer->checkpoint();
				return $this->response( $cursor, 86, $id, $stats );
			}
			$checksums_handle = $this->open_checkpointed_append( (string) $cursor['checksums_file'], (int) ( $cursor['checksums_file_offset'] ?? 0 ) );
			$packed_marks = array();
			$checksum_lines = '';
			$slice_bytes = 0;
			$changed = 0;
			foreach ( $rows as $row ) {
				if ( microtime( true ) >= $deadline || $slice_bytes >= $limits['bytes'] ) {
					break;
				}
				$entry_id = (int) $row['id'];
				$path = (string) $row['absolute_path'];
				if ( Exclusions::is_excluded( $path ) ) {
					if ( (int) ( $cursor['active_file_id'] ?? 0 ) === $entry_id ) { $this->rewind_active_file( $writer, $cursor ); }
					$this->inventory->set_state( $entry_id, 'skipped', 'Excluded by the active backup policy.' );
					$cursor['skipped_files'] = (int) ( $cursor['skipped_files'] ?? 0 ) + 1;
					$cursor['pack_after_id'] = $entry_id;
					$cursor['active_file_id'] = 0;
					$cursor['active_file_offset'] = 0;
					$cursor['active_file_chain'] = \Nobash\Backup\Filesystem\ChunkFingerprint::seed( 'file' );
					$cursor['active_file_chunks'] = 0;
					continue;
				}
				$current_meta = $this->regular_file_metadata( $path );
				if ( ! $current_meta ) {
					if ( (int) ( $cursor['active_file_id'] ?? 0 ) === $entry_id ) { $this->rewind_active_file( $writer, $cursor ); }
					$this->inventory->set_state( $entry_id, 'skipped', 'File became unavailable or unreadable.' );
					$cursor['skipped_files'] = (int) ( $cursor['skipped_files'] ?? 0 ) + 1;
					$cursor['pack_after_id'] = $entry_id;
					$cursor['active_file_id'] = 0;
					$cursor['active_file_offset'] = 0;
					$cursor['active_file_chain'] = \Nobash\Backup\Filesystem\ChunkFingerprint::seed( 'file' );
					$cursor['active_file_chunks'] = 0;
					continue;
				}
                $current_size  = $current_meta['size'];
                $current_mtime = $current_meta['mtime'];
                $current_ctime = $current_meta['ctime'];
                $current_ino   = $current_meta['ino'];
                $current_dev   = $current_meta['dev'];
                if ( BackupMode::FULL !== ( $cursor['backup_mode'] ?? BackupMode::FULL ) && ! ( new SnapshotRepository() )->changed( (string) $cursor['parent_point_id'], (string) $row['path_hash'], $current_size, $current_mtime ) ) {
                    if ( (int) ( $cursor['active_file_id'] ?? 0 ) === $entry_id ) { $this->rewind_active_file( $writer, $cursor ); }
                    $this->inventory->set_state( $entry_id, 'unchanged' );
                    $cursor['unchanged_files'] = (int) ( $cursor['unchanged_files'] ?? 0 ) + 1;
                    $cursor['pack_after_id'] = $entry_id;
                    $this->clear_active_file_state( $cursor );
                    continue;
                }
                if ( $current_size !== (int) $row['size_bytes'] || $current_mtime !== (int) $row['modified_at'] ) {
                    ++$changed;
                    $this->inventory->refresh_file_metadata( $entry_id, $current_size, $current_mtime );
                }

                $active_same = (int) ( $cursor['active_file_id'] ?? 0 ) === $entry_id;
                $file_offset = $active_same ? (int) ( $cursor['active_file_offset'] ?? 0 ) : 0;
                if ( $active_same && $file_offset > 0 ) {
                    $source_changed =
                        $current_size !== (int) ( $cursor['active_file_size'] ?? -1 ) ||
                        $current_mtime !== (int) ( $cursor['active_file_mtime'] ?? -1 ) ||
                        $current_ctime !== (int) ( $cursor['active_file_ctime'] ?? -1 ) ||
                        ( (int) ( $cursor['active_file_ino'] ?? 0 ) > 0 && $current_ino > 0 && $current_ino !== (int) $cursor['active_file_ino'] ) ||
                        ( (int) ( $cursor['active_file_dev'] ?? 0 ) > 0 && $current_dev > 0 && $current_dev !== (int) $cursor['active_file_dev'] );
                    if ( $source_changed ) {
                        $restarts = (int) ( $cursor['active_file_restarts'] ?? 0 ) + 1;
                        if ( $restarts > 3 ) {
                            $this->rewind_active_file( $writer, $cursor );
                            $this->inventory->set_state( $entry_id, 'skipped', 'File changed repeatedly while it was being backed up.' );
                            /* translators: %s: relative file path */
                            $cursor['warnings'][] = sprintf( __( 'Skipped a file that changed repeatedly during backup: %s', 'nobash-backup' ), (string) $row['relative_path'] );
                            $cursor['warnings'] = array_values( array_unique( (array) $cursor['warnings'] ) );
                            $cursor['skipped_files'] = (int) ( $cursor['skipped_files'] ?? 0 ) + 1;
                            $cursor['pack_after_id'] = $entry_id;
                            $this->clear_active_file_state( $cursor );
                            continue;
                        }
                        $start_offset = (int) ( $cursor['active_file_archive_start'] ?? 0 );
                        $start_state  = isset( $cursor['active_file_integrity_start'] ) && is_array( $cursor['active_file_integrity_start'] ) ? $cursor['active_file_integrity_start'] : array();
                        if ( $start_offset < strlen( NativeArchiveWriter::MAGIC ) || empty( $start_state ) ) {
                            throw new \RuntimeException( esc_html__( 'The active file restart checkpoint is invalid.', 'nobash-backup' ) );
                        }
                        $cursor['archive_offset'] = $writer->prepare( $start_offset, $start_state );
                        $cursor['archive_integrity_checkpoint'] = $start_state;
                        $file_offset = 0;
                        $cursor['active_file_offset'] = 0;
                        $cursor['active_file_chain'] = \Nobash\Backup\Filesystem\ChunkFingerprint::seed( 'file' );
                        $cursor['active_file_chunks'] = 0;
                        $cursor['active_file_restarts'] = $restarts;
                    }
                }

                if ( 0 === $file_offset ) {
                    $start_state = $writer->integrity_state();
                    $cursor['active_file_id'] = $entry_id;
                    $cursor['active_file_archive_start'] = (int) $start_state['offset'];
                    $cursor['active_file_integrity_start'] = $start_state;
                    $cursor['active_file_size'] = $current_size;
                    $cursor['active_file_mtime'] = $current_mtime;
                    $cursor['active_file_ctime'] = $current_ctime;
                    $cursor['active_file_ino'] = $current_ino;
                    $cursor['active_file_dev'] = $current_dev;
                    if ( ! isset( $cursor['active_file_restarts'] ) ) { $cursor['active_file_restarts'] = 0; }
                }

                $local = 'files/' . ltrim( str_replace( '\\\\', '/', (string) $row['relative_path'] ), '/' );
				$result = $writer->append_file_chunk(
				$path,
				$local,
				$file_offset,
				min( (int) ( $limits['chunk_bytes'] ?? NativeArchiveWriter::DEFAULT_CHUNK ), NativeArchiveWriter::DEFAULT_CHUNK, max( 262144, $limits['bytes'] - $slice_bytes ) ),
				array( 'inventory_id' => $entry_id, 'compress' => 0, 'mtime' => $current_mtime, 'file_chain_previous' => (string) ( $cursor['active_file_chain'] ?? \Nobash\Backup\Filesystem\ChunkFingerprint::seed( 'file' ) ), 'chunk_index' => (int) ( $cursor['active_file_chunks'] ?? 0 ) )
				);
				$cursor['archive_offset'] = $result['archive_offset'];
				$cursor['active_file_id'] = $entry_id;
				$cursor['active_file_offset'] = $result['file_offset'];
				$cursor['active_file_chain'] = $result['file_chain_sha256'];
				$cursor['active_file_chunks'] = $result['chunk_index'];
				$slice_bytes += $result['bytes'];
				if ( $result['done'] ) {
					$checksum = (string) $result['file_fingerprint'];
					if ( '' === $checksum ) {
						throw new \RuntimeException( esc_html__( 'Could not calculate final file fingerprint.', 'nobash-backup' ) );
					}
					$packed_marks[ $entry_id ] = $checksum;
					$checksum_lines .= wp_json_encode( array( 'path' => $local, 'size' => $current_size, 'mtime' => $current_mtime, 'sha256' => $checksum, 'checksum_scheme' => (string) $result['checksum_scheme'], 'chunks' => (int) $result['chunk_index'] ) ) . "\n";
					$cursor['packed_files'] = (int) ( $cursor['packed_files'] ?? 0 ) + 1;
					$cursor['packed_bytes'] = (int) ( $cursor['packed_bytes'] ?? 0 ) + $current_size;
					$cursor['pack_after_id'] = $entry_id;
					$this->clear_active_file_state( $cursor );
				} else {
					// Keep file chunks contiguous in the archive. Resume this file before selecting another entry.
					break;
				}
			}
			if ( $changed > 0 ) {
				$cursor['changed_files'] = (int) ( $cursor['changed_files'] ?? 0 ) + $changed;
			}
			if ( '' !== $checksum_lines ) { $this->write_all_to_handle( $checksums_handle, $checksum_lines ); }
			$cursor['archive_integrity_checkpoint'] = $writer->checkpoint();
			$cursor['checksums_file_offset'] = $this->checkpoint_append_handle( $checksums_handle );
			$this->inventory->mark_packed_many( $packed_marks );
			$cursor = $this->adaptive->learn( $cursor, microtime( true ) - $started, $this->memory_pressure() );
			$total = max( 1, (int) ( $cursor['total_files'] ?? 0 ) );
			$complete = (int) ( $cursor['packed_files'] ?? 0 ) + (int) ( $cursor['skipped_files'] ?? 0 ) + (int) ( $cursor['unchanged_files'] ?? 0 );
			$progress = 61 + (int) floor( ( $complete / $total ) * 24 );
			return $this->response( $cursor, min( 85, $progress ), $id, $this->cursor_stats( $cursor ) );
		}
		if ( 'package' === $phase ) {
			return $this->package( $cursor, $id, $profile, $deadline );
		}
		throw new \RuntimeException( esc_html__( 'Unknown backup phase.', 'nobash-backup' ) );
	}
	/**
		 * Package all immediately available small components in one request.
		 *
		 * @param array<string,mixed> $cursor Cursor.
		 * @param int $id Job.
		 * @param string $profile Profile.
		 * @param float $deadline Absolute time deadline.
		 * @return array<string,mixed>
		 */
	private function package( array $cursor, $id, $profile, $deadline ) {
		$temp = $cursor['temp'];
		$package_step = isset( $cursor['package_step'] ) ? (string) $cursor['package_step'] : 'database_objects';
		if ( in_array( $package_step, array( 'encrypt_init', 'encrypt' ), true ) ) {
			$plain = (string) ( $cursor['archive'] ?? '' );
			$sealed = CredentialVault::open( (string) ( $cursor['password_sealed'] ?? '' ) );
			$password = isset( $sealed['password'] ) ? (string) $sealed['password'] : '';
			if ( '' === $password ) {
				throw new \RuntimeException( esc_html__( 'The password-protected export can no longer access its password.', 'nobash-backup' ) );
			}
			$encrypted = trailingslashit( $temp ) . (string) $cursor['archive_name'] . '.encrypted.partial';
			if ( 'encrypt_init' === $package_step ) {
				$cursor['encryption_state'] = PasswordArchive::init_encrypt( $plain, $encrypted, $password );
				$cursor['encrypted_archive'] = $encrypted;
				$cursor['package_step'] = 'encrypt';
			}
			$state = PasswordArchive::encrypt_slice( $plain, $encrypted, $password, (array) $cursor['encryption_state'], max( 0.5, $deadline - microtime( true ) - 0.15 ) );
			$cursor['encryption_state'] = $state;
			if ( empty( $state['done'] ) ) {
				$total = max( 1, (int) ( $state['header']['original_size'] ?? 1 ) );
				return $this->response( $cursor, 97 + (int) floor( min( 1, (int) $state['source_offset'] / $total ) * 2 ), $id );
			}
			$cursor['archive'] = $encrypted;
			$cursor['package_step'] = 'publish';
			wp_delete_file( $plain );
		}
		$objects_sql = trailingslashit( $temp ) . 'database-objects.sql';
		$archive = isset( $cursor['archive'] ) ? $cursor['archive'] : '';
		if ( '' === $archive ) {
			$cursor['archive_name'] = LocalStorage::allocate_archive_filename( $profile );
			$archive = trailingslashit( $temp ) . $cursor['archive_name'] . '.partial';
			$cursor['archive'] = $archive;
		}
		if ( 'publish' === (string) ( $cursor['package_step'] ?? '' ) ) {
			return $this->publish_completed_archive( $cursor, $id, $profile );
		}
		$writer = new NativeArchiveWriter( $archive );
		$cursor['archive_offset'] = $writer->prepare( (int) ( $cursor['archive_offset'] ?? 0 ), (array) ( $cursor['archive_integrity_checkpoint'] ?? array() ) );
		$stats = $this->inventory->stats( $id );
		if ( $stats['packed'] + $stats['skipped'] + (int) ( $stats['unchanged'] ?? 0 ) < $stats['files'] ) {
			throw new \RuntimeException( esc_html__( 'Archive finalization blocked because the file inventory is incomplete.', 'nobash-backup' ) );
		}
		while ( microtime( true ) < $deadline ) {
			$step = isset( $cursor['package_step'] ) ? (string) $cursor['package_step'] : 'database_objects';
			if ( 'database_objects' === $step ) {
				if ( is_readable( $objects_sql ) && filesize( $objects_sql ) > 0 ) {
					$previous = (int) ( $cursor['package_objects_offset'] ?? 0 );
					$result = $writer->append_file_chunk( $objects_sql, 'database-objects.sql', $previous, NativeArchiveWriter::DEFAULT_CHUNK, array( 'component' => 'database_objects', 'compress' => 0, 'file_chain_previous' => (string) ( $cursor['package_objects_chain'] ?? \Nobash\Backup\Filesystem\ChunkFingerprint::seed( 'file' ) ), 'chunk_index' => (int) ( $cursor['package_objects_chunks'] ?? 0 ) ) );
					$cursor['archive_offset'] = $result['archive_offset'];
					$cursor['package_objects_offset'] = $result['file_offset'];
					$cursor['package_objects_chain'] = $result['file_chain_sha256'];
					$cursor['package_objects_chunks'] = $result['chunk_index'];
					if ( ! $result['done'] ) {
						if ( microtime( true ) + 0.15 >= $deadline ) {
							$cursor['archive_integrity_checkpoint'] = $writer->checkpoint();
							return $this->response( $cursor, 90, $id, $stats );
						}
						continue;
					}
					$cursor['database_objects_sha256'] = (string) $result['file_fingerprint'];
					$cursor['database_objects_checksum_scheme'] = (string) $result['checksum_scheme'];
				}
				$cursor['package_step'] = 'checksums';
				continue;
			}
			if ( 'checksums' === $step ) {
				$file = isset( $cursor['checksums_file'] ) ? $cursor['checksums_file'] : '';
				if ( is_readable( $file ) && filesize( $file ) > 0 ) {
					$previous = (int) ( $cursor['package_checksums_offset'] ?? 0 );
					$result = $writer->append_file_chunk( $file, 'meta/file-checksums.ndjson', $previous, NativeArchiveWriter::DEFAULT_CHUNK, array( 'component' => 'checksums', 'compress' => 0, 'file_chain_previous' => (string) ( $cursor['package_checksums_chain'] ?? \Nobash\Backup\Filesystem\ChunkFingerprint::seed( 'file' ) ), 'chunk_index' => (int) ( $cursor['package_checksums_chunks'] ?? 0 ) ) );
					$cursor['archive_offset'] = $result['archive_offset'];
					$cursor['package_checksums_offset'] = $result['file_offset'];
					$cursor['package_checksums_chain'] = $result['file_chain_sha256'];
					$cursor['package_checksums_chunks'] = $result['chunk_index'];
					if ( ! $result['done'] ) {
						if ( microtime( true ) + 0.15 >= $deadline ) {
							$cursor['archive_integrity_checkpoint'] = $writer->checkpoint();
							return $this->response( $cursor, 92, $id, $stats );
						}
						continue;
					}
					$cursor['checksums_sha256'] = (string) $result['file_fingerprint'];
					$cursor['checksums_checksum_scheme'] = (string) $result['checksum_scheme'];
				}
				$cursor['package_step'] = 'deleted';
				continue;
			}
			if ( 'deleted' === $step ) {
				$file = isset( $cursor['deleted_file_index'] ) ? $cursor['deleted_file_index'] : '';
				if ( is_readable( $file ) && filesize( $file ) > 0 ) {
					$previous = (int) ( $cursor['package_deleted_offset'] ?? 0 );
					$result = $writer->append_file_chunk( $file, 'meta/deleted-files.ndjson', $previous, NativeArchiveWriter::DEFAULT_CHUNK, array( 'component' => 'deleted_files', 'compress' => 0, 'file_chain_previous' => (string) ( $cursor['package_deleted_chain'] ?? \Nobash\Backup\Filesystem\ChunkFingerprint::seed( 'file' ) ), 'chunk_index' => (int) ( $cursor['package_deleted_chunks'] ?? 0 ) ) );
					$cursor['archive_offset'] = $result['archive_offset'];
					$cursor['package_deleted_offset'] = $result['file_offset'];
					$cursor['package_deleted_chain'] = $result['file_chain_sha256'];
					$cursor['package_deleted_chunks'] = $result['chunk_index'];
					if ( ! $result['done'] ) {
						if ( microtime( true ) + 0.15 >= $deadline ) {
							$cursor['archive_integrity_checkpoint'] = $writer->checkpoint();
							return $this->response( $cursor, 93, $id, $stats );
						}
						continue;
					}
					$cursor['deleted_sha256'] = (string) $result['file_fingerprint'];
					$cursor['deleted_checksum_scheme'] = (string) $result['checksum_scheme'];
				}
				$cursor['package_step'] = 'metadata';
				continue;
			}
			if ( 'metadata' === $step ) {
				$cursor['archive_offset'] = $writer->append_bytes( 'metadata', 'meta/plugins.json', wp_json_encode( get_option( 'active_plugins', array() ) ) );
				$cursor['archive_offset'] = $writer->append_bytes( 'metadata', 'meta/themes.json', wp_json_encode( array( 'stylesheet' => get_stylesheet(), 'template' => get_template() ) ) );
				$cursor['package_step'] = 'manifest';
				continue;
			}
			if ( 'manifest' === $step ) {
				$checksums = array();
				if ( ! empty( $cursor['database_sha256'] ) ) {
					$checksums['database.sql'] = $cursor['database_sha256'];
				}
				if ( ! empty( $cursor['database_objects_sha256'] ) ) {
					$checksums['database-objects.sql'] = $cursor['database_objects_sha256'];
				}
				if ( ! empty( $cursor['checksums_sha256'] ) ) {
					$checksums['meta/file-checksums.ndjson'] = $cursor['checksums_sha256'];
				}
				if ( ! empty( $cursor['deleted_sha256'] ) ) {
					$checksums['meta/deleted-files.ndjson'] = $cursor['deleted_sha256'];
				}
				$manifest = Manifest::build( $profile, array_merge( array(
				'format' => 'nbh', 'format_version' => '3.0', 'archive_engine' => 'native-append-only-v3',
				'checksums' => $checksums, 'checksum_schemes' => array( 'database.sql' => (string) ( $cursor['database_checksum_scheme'] ?? '' ), 'database-objects.sql' => (string) ( $cursor['database_objects_checksum_scheme'] ?? '' ), 'meta/file-checksums.ndjson' => (string) ( $cursor['checksums_checksum_scheme'] ?? '' ), 'meta/deleted-files.ndjson' => (string) ( $cursor['deleted_checksum_scheme'] ?? '' ) ), 'files_count' => $stats['files'], 'files_bytes' => $stats['bytes'],
				'files_packed' => $stats['packed'], 'files_skipped' => $stats['skipped'],
				'files_changed_during_backup' => (int) ( $cursor['changed_files'] ?? 0 ),
				'backup_mode' => (string) ( $cursor['backup_mode'] ?? BackupMode::FULL ), 'chain_id' => (string) ( $cursor['chain_id'] ?? '' ), 'parent_point_id' => (string) ( $cursor['parent_point_id'] ?? '' ),
				'files_unchanged' => (int) ( $stats['unchanged'] ?? 0 ), 'files_deleted' => (int) ( $cursor['deleted_files'] ?? 0 ),
				'database_increment_strategy' => BackupMode::FULL === ( $cursor['backup_mode'] ?? BackupMode::FULL ) ? 'full-base' : 'verified-full-database-segment',
				'file_inventory' => 'database-backed-v1', 'file_checksums' => 'meta/file-checksums.ndjson',
				'tables' => isset( $cursor['tables'] ) ? $cursor['tables'] : array(), 'database_objects' => isset( $cursor['database_objects'] ) ? $cursor['database_objects'] : array(),
				'db_engine' => \Nobash\Backup\Compat\Environment::db_engine(),
				'server' => \Nobash\Backup\Compat\Environment::server_family(),
				'woocommerce' => \Nobash\Backup\Compat\WooCommerceSupport::status(),
				'woocommerce_consistency' => \Nobash\Backup\Compat\WooCommerceSupport::consistency_marker(),
				), \Nobash\Backup\Compat\Multisite::manifest_meta( isset( $cursor['scope'] ) ? $cursor['scope'] : 'site' ), (array) ( $cursor['multisite_meta'] ?? array() ) ) );
				$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				$cursor['manifest_sha256'] = hash( 'sha256', $manifest_json );
				$cursor['archive_offset'] = $writer->append_bytes( 'manifest', 'manifest.json', $manifest_json );
				$cursor['package_step'] = 'finalize';
				continue;
			}
			$final = $writer->finalize( array( 'manifest_sha256' => $cursor['manifest_sha256'], 'files' => $stats['files'], 'skipped' => $stats['skipped'], 'unchanged' => (int) ( $stats['unchanged'] ?? 0 ), 'deleted' => (int) ( $cursor['deleted_files'] ?? 0 ), 'chain_id' => (string) $cursor['chain_id'], 'backup_mode' => (string) ( $cursor['backup_mode'] ?? BackupMode::FULL ) ) );
			$cursor['native_final'] = $final;
			if ( ! empty( $cursor['password_protected'] ) ) {
				if ( ! PasswordArchive::available() ) {
					throw new \RuntimeException( esc_html__( 'Password protection is unavailable because this server has no supported authenticated-encryption backend.', 'nobash-backup' ) );
				}
				$cursor['package_step'] = 'encrypt_init';
				return $this->response( $cursor, 97, $id, $stats );
			}
			$cursor['package_step'] = 'publish';
			return $this->publish_completed_archive( $cursor, $id, $profile, $stats );
		}
		$cursor['archive_integrity_checkpoint'] = $writer->checkpoint();
		$progress = array( 'database' => 88, 'database_objects' => 90, 'checksums' => 92, 'deleted' => 93, 'metadata' => 94, 'manifest' => 96, 'finalize' => 97 );
		$step = (string) ( $cursor['package_step'] ?? 'database' );
		return $this->response( $cursor, $progress[ $step ] ?? 87, $id, $stats );
	}
	/** Publish a completed plain or password-protected archive. @return array<string,mixed> */
	private function publish_completed_archive( array $cursor, $id, $profile, ?array $stats = null ) {
		$stats = $stats ? $stats : $this->inventory->stats( $id );
		$archive = (string) $cursor['archive'];
		$final_name = (string) $cursor['archive_name'];
		$dest = trailingslashit( LocalStorage::ensure_directory() ) . $final_name;
		$encrypted = ! empty( $cursor['password_protected'] );
		$sha = $encrypted ? (string) ( $cursor['encryption_state']['container_fingerprint'] ?? '' ) : (string) ( $cursor['native_final']['archive_fingerprint'] ?? '' );
		$checksum_scheme = $encrypted ? (string) ( $cursor['encryption_state']['checksum_scheme'] ?? 'encrypted-chunk-chain-v1' ) : (string) ( $cursor['native_final']['checksum_scheme'] ?? \Nobash\Backup\Filesystem\ChunkFingerprint::ARCHIVE_SCHEME );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', strtolower( $sha ) ) ) throw new \RuntimeException( esc_html__( 'Could not verify the completed archive fingerprint.', 'nobash-backup' ) );
		$expected_size = $encrypted
			? (int) ( $cursor['encryption_state']['dest_offset'] ?? 0 )
			: (int) ( $cursor['native_final']['size'] ?? 0 );
		if ( is_file( $archive ) && ! is_link( $archive ) ) {
			$this->publish_archive_safely( $archive, $dest );
		} elseif ( is_file( $dest ) && ! is_link( $dest ) ) {
			/* A worker can stop after the atomic publication but before the terminal
			 * job cursor is saved. Reuse only the exact verified archive allocated to
			 * this job; never overwrite or blindly trust an existing destination. */
			$published = \Nobash\Backup\Archive\ArchiveFingerprint::inspect( $dest );
			if ( $expected_size < 1
				|| (int) filesize( $dest ) !== $expected_size
				|| ! hash_equals( strtolower( $sha ), strtolower( (string) ( $published['fingerprint'] ?? '' ) ) ) ) {
				throw new \RuntimeException( esc_html__( 'The previously published archive does not match this backup checkpoint.', 'nobash-backup' ) );
			}
		} else {
			throw new \RuntimeException( esc_html__( 'The completed archive publication source is missing.', 'nobash-backup' ) );
		}
		$status = $stats['skipped'] > 0 ? 'completed_with_warnings' : 'completed';
		$chain_repo = new ChainRepository();
		$point_id = $chain_repo->add_point( (string) $cursor['chain_id'], (string) ( $cursor['backup_mode'] ?? BackupMode::FULL ), $final_name, $sha, array( 'files'=>(int)$stats['files'], 'packed'=>(int)$stats['packed'], 'unchanged'=>(int)($stats['unchanged']??0), 'deleted'=>(int)($cursor['deleted_files']??0), 'checksum_scheme'=>$checksum_scheme, 'database_strategy'=>BackupMode::FULL === ( $cursor['backup_mode'] ?? BackupMode::FULL ) ? 'full-base' : 'verified-full-database-segment', 'encrypted'=>$encrypted ), (string)($cursor['parent_point_id']??'') );
		$temporary_cloud_full = absint( $cursor['cloud_destination_id'] ?? 0 ) > 0 && empty( $cursor['keep_local_after_upload'] );
		if ( BackupMode::FULL === ( $cursor['backup_mode'] ?? BackupMode::FULL ) && ! $temporary_cloud_full ) {
			$chain_repo->supersede_other_chains( (string) $cursor['chain_id'], (string) ( $cursor['profile_key'] ?? $profile ) );
		}
		( new SnapshotRepository() )->capture_inventory( $point_id, $id );
		$components = array();
		if ( in_array( $profile, array( 'full', 'database' ), true ) || ( 'custom' === $profile && ! empty( $cursor['profile_include_tables'] ) ) ) {
			$components[] = 'database';
		}
		if ( in_array( $profile, array( 'full', 'files', 'uploads', 'plugins', 'themes' ), true ) || ( 'custom' === $profile && ! empty( $cursor['profile_include_paths'] ) ) ) {
			$components[] = in_array( $profile, array( 'uploads', 'plugins', 'themes' ), true ) ? $profile : 'files';
		}
		$multisite_meta = \Nobash\Backup\Compat\Multisite::manifest_meta( isset( $cursor['scope'] ) ? $cursor['scope'] : 'site', (array) ( $cursor['multisite_meta'] ?? array() ) );
		$portable_index = array();
		foreach ( array( 'archive_type', 'source_network_id', 'source_blog_id', 'domain', 'path', 'home', 'siteurl', 'uploads_relative', 'shared_users', 'site_users_total', 'site_users_truncated', 'subdomain_install', 'site_ids' ) as $key ) {
			if ( array_key_exists( $key, $multisite_meta ) ) {
				$portable_index[ $key ] = $multisite_meta[ $key ];
			}
		}
		$this->storage->write_archive_index( $final_name, array_merge( array( 'profile'=>$profile, 'components'=>array_values(array_unique($components)), 'scope'=>$cursor['scope']??'site', 'sha256'=>$sha, 'checksum_scheme'=>$checksum_scheme, 'encrypted'=>$encrypted, 'password_required'=>$encrypted, 'encryption'=>$encrypted ? 'password-aead-v2' : '', 'format'=>'nbh', 'format_version'=>$encrypted ? 'password-2' : 3, 'created'=>time(), 'files'=>$stats['files'], 'skipped'=>$stats['skipped'], 'backup_mode'=>(string)($cursor['backup_mode']??BackupMode::FULL), 'chain_id'=>(string)$cursor['chain_id'], 'point_id'=>$point_id, 'parent_point_id'=>(string)($cursor['parent_point_id']??''), 'schedule_id'=>absint($cursor['schedule_id']??0), 'schedule_key'=>sanitize_key($cursor['schedule_key']??''), 'storage_provider'=>sanitize_key($cursor['storage_provider']??'local'), 'source'=>'local', 'integrity_status'=>$encrypted ? 'password_required' : 'ready_for_verification' ), $portable_index ) );
		update_option( 'nobash_backup_last_run', array( 'filename'=>$final_name, 'time'=>time(), 'profile'=>$profile, 'sha256'=>$sha, 'checksum_scheme'=>$checksum_scheme, 'point_id'=>$point_id, 'chain_id'=>(string)$cursor['chain_id'], 'encrypted'=>$encrypted ) );
		$cursor['password_sealed'] = '';
		$this->inventory->delete_job( $id );
		$this->storage->cleanup_job_temp( $id );
		return array( 'cursor'=>$cursor, 'progress'=>100, 'status'=>$status, 'processed_items'=>$stats['packed'], 'total_items'=>$stats['files'], 'processed_bytes'=>$stats['packed_bytes'], 'total_bytes'=>$stats['bytes'], 'result'=>array( 'filename'=>$final_name, 'sha256'=>$sha, 'size'=>(int)filesize($dest), 'format'=>'nbh', 'checksum_scheme'=>$checksum_scheme, 'encrypted'=>$encrypted, 'files'=>$stats['files'], 'skipped'=>$stats['skipped'], 'unchanged'=>(int)($stats['unchanged']??0), 'deleted'=>(int)($cursor['deleted_files']??0), 'chain_id'=>(string)$cursor['chain_id'], 'backup_mode'=>(string)($cursor['backup_mode']??BackupMode::FULL) ) );
	}

	/**
	 * Publish a completed archive without following or overwriting a raced path.
	 *
	 * The job workspace and archive library normally share a filesystem, so an
	 * atomic hard-link is both safer and O(1). Hosts that disable hard links fall
	 * back to an exclusive-create streaming copy; `xb` prevents symlink/path races.
	 *
	 * @return void
	 */
	private function publish_archive_safely( $source, $destination ) {
		$source = (string) $source;
		$destination = (string) $destination;
		if ( is_link( $source ) || ! is_file( $source ) || ! is_readable( $source ) || is_link( $destination ) || file_exists( $destination ) ) {
			throw new \RuntimeException( esc_html__( 'The completed archive publication path is unsafe.', 'nobash-backup' ) );
		}
		if ( @link( $source, $destination ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->chmod_published_archive( $destination );
			wp_delete_file( $source );
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem has no streaming read primitive; a multi-GB archive is copied in bounded 8MB chunks to avoid loading it into memory.
		$in = @fopen( $source, 'rb' );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive-create ('xb') destination handle prevents a symlink/path race; WP_Filesystem has no equivalent atomic-create mode.
		$out = @fopen( $destination, 'xb' );
		if ( ! is_resource( $in ) || ! is_resource( $out ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the direct streaming handles opened above; see fopen justification.
			if ( is_resource( $in ) ) { fclose( $in ); }
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the direct streaming handles opened above; see fopen justification.
			if ( is_resource( $out ) ) { fclose( $out ); }
			if ( is_file( $destination ) && ! is_link( $destination ) ) { wp_delete_file( $destination ); }
			throw new \RuntimeException( esc_html__( 'Could not publish the completed archive safely.', 'nobash-backup' ) );
		}
		try {
			while ( ! feof( $in ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Bounded chunked read of a multi-GB archive; WP_Filesystem has no streaming read primitive.
				$chunk = fread( $in, 8388608 );
				if ( false === $chunk ) { throw new \RuntimeException( esc_html__( 'Could not read the completed archive for publication.', 'nobash-backup' ) ); }
				if ( '' === $chunk ) { continue; }
				$this->write_all_to_handle( $out, $chunk );
			}
			if ( ! fflush( $out ) ) { throw new \RuntimeException( esc_html__( 'Could not flush the completed archive.', 'nobash-backup' ) ); }
			if ( function_exists( 'fsync' ) ) { @fsync( $out ); }
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the direct streaming handles opened above; see fopen justification.
			fclose( $in );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the direct streaming handles opened above; see fopen justification.
			fclose( $out );
			if ( is_file( $destination ) && ! is_link( $destination ) ) { wp_delete_file( $destination ); }
			throw $error;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the direct streaming handles opened above; see fopen justification.
		fclose( $in );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the direct streaming handles opened above; see fopen justification.
		fclose( $out );
		if ( (int) filesize( $source ) !== (int) filesize( $destination ) ) {
			wp_delete_file( $destination );
			throw new \RuntimeException( esc_html__( 'Completed archive publication was incomplete.', 'nobash-backup' ) );
		}
		$this->chmod_published_archive( $destination );
		wp_delete_file( $source );
	}

	/** Best-effort permission tightening on a newly published archive via WP_Filesystem. @return void */
	private function chmod_published_archive( $destination ) {
		$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
		if ( $wp_filesystem ) {
			$wp_filesystem->chmod( $destination, 0640 );
		}
	}

	/** Append SQL bytes directly to database.sql inside the native archive. @return array<string,mixed> */
	private function append_database_stream( NativeArchiveWriter $writer, array $cursor, $bytes, $final ) {
		$bytes = (string) $bytes;
		$length = strlen( $bytes );
		if ( 0 === $length && ! $final ) {
			return $cursor;
		}
		$offset = 0;
		/* A single unusually large BLOB row can produce SQL larger than one native
		 * record. Splitting the logical file at arbitrary byte boundaries is safe:
		 * extraction concatenates file chunks back into the exact SQL stream. */
		do {
			$remaining = $length - $offset;
			$take = min( NativeArchiveWriter::MAX_RECORD_SIZE, max( 0, $remaining ) );
			$piece = $take > 0 ? substr( $bytes, $offset, $take ) : '';
			$is_last = ( $offset + $take ) >= $length;
			$result = $writer->append_stream_chunk(
				'database.sql',
				(int) ( $cursor['package_db_offset'] ?? 0 ),
				$piece,
				(bool) $final && $is_last,
				array(
					'component' => 'database',
					'file_chain_previous' => (string) ( $cursor['package_db_chain'] ?? \Nobash\Backup\Filesystem\ChunkFingerprint::seed( 'file' ) ),
					'chunk_index' => (int) ( $cursor['package_db_chunks'] ?? 0 ),
				)
			);
			$cursor['archive_offset'] = (int) $result['archive_offset'];
			$cursor['package_db_offset'] = (int) $result['file_offset'];
			$cursor['package_db_chain'] = (string) $result['file_chain_sha256'];
			$cursor['package_db_chunks'] = (int) $result['chunk_index'];
			if ( $final && $is_last ) {
				$cursor['database_sha256'] = (string) $result['file_fingerprint'];
				$cursor['database_checksum_scheme'] = (string) $result['checksum_scheme'];
			}
			$offset += $take;
		} while ( $offset < $length );
		return $cursor;
	}

	/** @return resource */
	private function open_checkpointed_append( $file, $committed_offset ) {
		$committed_offset = max( 0, (int) $committed_offset );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Resumable checkpoint append requires fseek()/ftruncate() random-access; WP_Filesystem has no such primitives.
		$fh = fopen( (string) $file, is_file( $file ) ? 'c+b' : 'w+b' );
		if ( ! $fh ) { throw new \RuntimeException( esc_html__( 'Could not open the resumable checksum checkpoint.', 'nobash-backup' ) ); }
		$stat = fstat( $fh ); $size = is_array( $stat ) && isset( $stat['size'] ) ? (int) $stat['size'] : 0;
		if ( $committed_offset > $size || ( $size > $committed_offset && ! ftruncate( $fh, $committed_offset ) ) || 0 !== fseek( $fh, $committed_offset, SEEK_SET ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the direct handle opened above; see fopen justification.
			fclose( $fh ); throw new \RuntimeException( esc_html__( 'Could not rewind the resumable checksum checkpoint.', 'nobash-backup' ) );
		}
		return $fh;
	}

	/** @return void */
	private function write_all_to_handle( $fh, $bytes ) {
		$length = strlen( $bytes ); $offset = 0;
		while ( $offset < $length ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Chunked write loop onto a resumable checkpoint handle opened with fopen()/fseek() above; WP_Filesystem has no equivalent.
			$n = fwrite( $fh, 0 === $offset ? $bytes : substr( $bytes, $offset ) );
			if ( false === $n || 0 === $n ) { throw new \RuntimeException( esc_html__( 'Could not write the resumable checksum checkpoint.', 'nobash-backup' ) ); }
			$offset += $n;
		}
	}

	/** @return int */
	private function checkpoint_append_handle( $fh ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the resumable checkpoint handle on the flush-failure path; see open_checkpointed_append() fopen justification.
		if ( ! fflush( $fh ) ) { fclose( $fh ); throw new \RuntimeException( esc_html__( 'Could not flush the resumable checksum checkpoint.', 'nobash-backup' ) ); }
		if ( function_exists( 'fsync' ) ) { @fsync( $fh ); }
		$offset = ftell( $fh );
		if ( false === $offset ) { $stat = fstat( $fh ); $offset = is_array( $stat ) && isset( $stat['size'] ) ? (int) $stat['size'] : 0; }
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the resumable checkpoint handle; see open_checkpointed_append() fopen justification.
		fclose( $fh );
		return (int) $offset;
	}

	/**
	 * Repair an interrupted backup cursor before opening archive paths.
	 *
	 * This recovers jobs that reached the database phase with an empty transient
	 * archive path. If no real partial archive exists, only the unpublished job
	 * is restarted from its safe initialization boundary.
	 *
	 * @param array<string,mixed> $cursor Durable job cursor.
	 * @param int                 $job_id Job ID.
	 * @return array<string,mixed>
	 */
	private function repair_resume_cursor( array $cursor, $job_id ) {
		$phase = isset( $cursor['phase'] ) ? (string) $cursor['phase'] : 'init';
		if ( 'init' === $phase ) {
			return $cursor;
		}

		$temp = isset( $cursor['temp'] ) ? (string) $cursor['temp'] : '';
		if ( '' === $temp || ! is_dir( $temp ) ) {
			$temp = $this->storage->job_temp_dir( $job_id );
			$cursor['temp'] = $temp;
		}

		if ( ! in_array( $phase, array( 'database_snapshot', 'database' ), true ) ) {
			return $cursor;
		}

		$archive = isset( $cursor['archive'] ) ? (string) $cursor['archive'] : '';
		$name    = isset( $cursor['archive_name'] ) ? basename( (string) $cursor['archive_name'] ) : '';
		if ( '' === $archive && '' !== $name ) {
			$archive = trailingslashit( $temp ) . $name . '.partial';
			$cursor['archive'] = $archive;
		}

		if ( '' === $archive ) {
			$matches = glob( trailingslashit( $temp ) . '*.nbh.partial' );
			if ( is_array( $matches ) && 1 === count( $matches ) && is_file( $matches[0] ) ) {
				$archive = (string) $matches[0];
				$cursor['archive'] = $archive;
				$cursor['archive_name'] = preg_replace( '/\.partial$/', '', basename( $archive ) );
			}
		}

		if ( '' !== $archive && is_file( $archive ) ) {
			return $cursor;
		}

		BackupSnapshotManager::cleanup_cursor( $cursor );
		$this->storage->cleanup_job_temp( $job_id );
		foreach ( array(
			'temp', 'archive', 'archive_name', 'archive_offset', 'archive_integrity_checkpoint',
			'direct_database_archive', 'tables', 'table_idx', 'db_cursor', 'db_rows', 'db_bytes',
			'database_snapshot_queue', 'database_snapshot_idx', 'database_snapshots',
		) as $key ) {
			unset( $cursor[ $key ] );
		}
		$cursor['phase'] = 'init';
		$cursor['tables'] = array();
		$cursor['table_idx'] = 0;
		$cursor['row_offset'] = 0;
		$cursor['archive'] = '';
		$cursor['temp'] = '';
		$cursor['warnings'] = array_values( array_unique( array_merge(
			(array) ( $cursor['warnings'] ?? array() ),
			array( __( 'An incomplete backup checkpoint was repaired and restarted from its last safe boundary.', 'nobash-backup' ) )
		) ) );
		return $cursor;
	}

	/** @param array<string,mixed> $cursor Cursor. @param int $progress Progress. @param int $job_id Job. @param array<string,int>|null $stats Stats. @return array<string,mixed> */
	private function response( array $cursor, $progress, $job_id, ?array $stats = null ) {
		$stats = $stats ? $stats : $this->cursor_stats( $cursor );
		return array(
		'cursor' => $cursor,
		'progress' => max( 0, min( 100, (int) $progress ) ),
		'status' => 'running',
		'processed_items' => (int) $stats['packed'],
		'total_items' => (int) $stats['files'],
		'processed_bytes' => (int) $stats['packed_bytes'],
		'total_bytes' => (int) $stats['bytes'],
		);
	}
	/** @return array<string,int> */
	private function cursor_stats( array $cursor ) {
		return array(
		'files' => (int) ( $cursor['total_files'] ?? 0 ),
		'bytes' => (int) ( $cursor['total_file_bytes'] ?? 0 ),
		'packed' => (int) ( $cursor['packed_files'] ?? 0 ),
		'packed_bytes' => (int) ( $cursor['packed_bytes'] ?? 0 ),
		'skipped' => (int) ( $cursor['skipped_files'] ?? 0 ),
		'unchanged' => (int) ( $cursor['unchanged_files'] ?? 0 ),
		'pending_dirs' => 0,
		);
	}
	/** Rewind any partially written active file to its pre-file archive boundary. */
	private function rewind_active_file( NativeArchiveWriter $writer, array &$cursor ) {
		if ( (int) ( $cursor['active_file_offset'] ?? 0 ) > 0 ) {
			$start_offset = (int) ( $cursor['active_file_archive_start'] ?? 0 );
			$start_state  = isset( $cursor['active_file_integrity_start'] ) && is_array( $cursor['active_file_integrity_start'] ) ? $cursor['active_file_integrity_start'] : array();
			if ( $start_offset < strlen( NativeArchiveWriter::MAGIC ) || empty( $start_state ) ) {
				throw new \RuntimeException( esc_html__( 'The active file rewind checkpoint is invalid.', 'nobash-backup' ) );
			}
			$cursor['archive_offset'] = $writer->prepare( $start_offset, $start_state );
			$cursor['archive_integrity_checkpoint'] = $start_state;
		}
		$this->clear_active_file_state( $cursor );
	}

	/** Clear all state that belongs to a partially archived source file. */
	private function clear_active_file_state( array &$cursor ) {
		foreach ( array(
			'active_file_id', 'active_file_offset', 'active_file_chain', 'active_file_chunks',
			'active_file_archive_start', 'active_file_integrity_start', 'active_file_size',
			'active_file_mtime', 'active_file_ctime', 'active_file_ino', 'active_file_dev',
			'active_file_restarts',
		) as $key ) {
			unset( $cursor[ $key ] );
		}
	}

	/** Return regular-file metadata with one no-following-symlink stat call. @return array{size:int,mtime:int,ctime:int,ino:int,dev:int}|null */
	private function regular_file_metadata( $path ) {
		$stat = @lstat( (string) $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $stat ) ) { return null; }
		$mode = (int) ( $stat['mode'] ?? $stat[2] ?? 0 );
		if ( 0100000 !== ( $mode & 0170000 ) ) { return null; }
		return array(
			'size'  => max( 0, (int) ( $stat['size'] ?? $stat[7] ?? 0 ) ),
			'mtime' => max( 0, (int) ( $stat['mtime'] ?? $stat[9] ?? 0 ) ),
			'ctime' => max( 0, (int) ( $stat['ctime'] ?? $stat[10] ?? 0 ) ),
			'ino'   => max( 0, (int) ( $stat['ino'] ?? $stat[1] ?? 0 ) ),
			'dev'   => max( 0, (int) ( $stat['dev'] ?? $stat[0] ?? 0 ) ),
		);
	}

	/** @return bool */
	private function memory_pressure() {
		$limit = ini_get( 'memory_limit' );
		if ( false === $limit || '-1' === $limit ) {
			return false;
		}
		$unit = strtolower( substr( trim( (string) $limit ), -1 ) );
		$value = (int) $limit;
		if ( 'g' === $unit ) {
			$value *= 1073741824;
		} elseif ( 'm' === $unit ) {
			$value *= 1048576;
		} elseif ( 'k' === $unit ) {
			$value *= 1024;
		}
		return $value > 0 && memory_get_usage( true ) >= (int) ( $value * 0.75 );
	}
}

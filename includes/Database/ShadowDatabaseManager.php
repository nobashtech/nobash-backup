<?php
/** Shadow-table database restore, verified publish and rollback. @package Nobash\Backup */
namespace Nobash\Backup\Database;

use Nobash\Backup\Compat\DatabaseAdapter;
use Nobash\Backup\Compat\Environment;

defined( 'ABSPATH' ) || exit;

/** Restore SQL away from live tables and publish with metadata-level table swaps. */
class ShadowDatabaseManager {
	/** @var DatabaseAdapter */ private $db;
	public function __construct() { $this->db = new DatabaseAdapter(); }

	/** @return string */
	public function shadow_prefix( $job_id ) { return 'n' . substr( base_convert( (string) max( 1, (int) $job_id ), 10, 36 ), -5 ) . 's_'; }
	/** @return string */
	public function rollback_prefix( $job_id ) { return 'n' . substr( base_convert( (string) max( 1, (int) $job_id ), 10, 36 ), -5 ) . 'r_'; }
	/** @return string */
	public function failed_prefix( $job_id ) { return 'n' . substr( base_convert( (string) max( 1, (int) $job_id ), 10, 36 ), -5 ) . 'f_'; }

	/** @return bool */
	public function table_exists( $table ) {
		global $wpdb;
		$table = $this->clean_identifier( $table );
		if ( 'sqlite' === Environment::db_engine() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM sqlite_master WHERE type='table' AND name=%s", $table ) ) === $table;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	}

	/** Validate imported shadow tables and return table diagnostics. @return array<string,mixed> */
	public function verify( array $tables ) {
		global $wpdb;
		$tables = array_values( array_unique( array_filter( array_map( array( $this, 'clean_identifier' ), $tables ) ) ) );
		if ( empty( $tables ) ) { throw new \RuntimeException( esc_html__( 'The SQL archive did not create any restorable database tables.', 'nobash-backup' ) ); }
		$checked = array();
		foreach ( $tables as $table ) {
			if ( ! $this->table_exists( $table ) ) { throw new \RuntimeException( sprintf( /* translators: %s table */ esc_html__( 'Shadow database table is missing: %s', 'nobash-backup' ), esc_html( $table ) ) ); }
			$status = 'ok';
			if ( 'sqlite' !== Environment::db_engine() ) {
				$row = $wpdb->get_row(
					$wpdb->prepare( 'CHECK TABLE %i QUICK', $table ),
					ARRAY_A
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore verification query.
				$status = strtolower( (string) ( $row['Msg_text'] ?? 'ok' ) );
				if ( ! in_array( $status, array( 'ok', 'table is already up to date' ), true ) ) {
					/* translators: 1: table name 2: verification status */
					throw new \RuntimeException( sprintf( esc_html__( 'Shadow table verification failed for %1$s: %2$s', 'nobash-backup' ), esc_html( $table ), esc_html( $status ) ) );
				}
			}
			$checked[] = array( 'table' => $table, 'status' => $status );
		}
		return array( 'healthy' => true, 'tables' => $checked, 'count' => count( $checked ) );
	}

	/** Preserve operational state needed to finish a restore after wp_options is replaced. @return void */
	public function preserve_runtime_options( $shadow_prefix, $target_prefix, $preserve_active_plugins = true ) {
		global $wpdb;
		$shadow = $this->clean_identifier( $shadow_prefix . 'options' );
		$live   = $this->clean_identifier( $target_prefix . 'options' );
		if ( ! $this->table_exists( $shadow ) || ! $this->table_exists( $live ) ) { return; }
		$names = array( 'cron', $target_prefix . 'user_roles', 'nobash_backup_db_version', 'nobash_backup_active_restore', 'nobash_backup_production_barrier' );
		if ( $preserve_active_plugins ) { array_unshift( $names, 'active_plugins' ); }
		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$sql = "INSERT INTO %i (option_name,option_value,autoload) SELECT option_name,option_value,autoload FROM %i WHERE option_name IN ({$placeholders}) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload=VALUES(autoload)";
		if ( 'sqlite' === Environment::db_engine() ) {
			$sql = "INSERT OR REPLACE INTO %i (option_id,option_name,option_value,autoload) SELECT option_id,option_name,option_value,autoload FROM %i WHERE option_name IN ({$placeholders})";
		}
		$args = array_merge( array( $shadow, $live ), $names );
		$result = $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fixed SQL templates and locally generated %s placeholders; all identifiers and option names are prepare arguments.
		if ( false === $result ) { throw new \RuntimeException( esc_html__( 'Could not preserve restore runtime options in the shadow database.', 'nobash-backup' ) ); }
		if ( ! $preserve_active_plugins ) { $this->merge_protected_active_plugins( $shadow, $live ); }
	}

	/** Merge Nobash Backup runtime plugins into the restored activation state without discarding unrelated plugin changes. */
	private function merge_protected_active_plugins( $shadow, $live ) {
		global $wpdb;
		$shadow = $this->clean_identifier( $shadow );
		$live   = $this->clean_identifier( $live );
		$shadow_value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM %i WHERE option_name='active_plugins' LIMIT 1", $shadow ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$live_value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM %i WHERE option_name='active_plugins' LIMIT 1", $live ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// Archive options are data, never PHP objects with magic methods.
		$desired = self::decode_plugin_list( $shadow_value );
		$current = self::decode_plugin_list( $live_value );
		$desired = is_array( $desired ) ? array_values( $desired ) : array();
		$current = is_array( $current ) ? array_values( $current ) : array();
		$valid = array();
		foreach ( $desired as $plugin ) {
			if ( ! is_string( $plugin ) ) { continue; }
			$plugin = ltrim( str_replace( '\\', '/', (string) $plugin ), '/' );
			if ( '' === $plugin || false !== strpos( $plugin, '..' ) ) { continue; }
			if ( is_file( trailingslashit( \Nobash\Backup\Compat\Paths::plugins_root() ) . $plugin ) ) { $valid[] = $plugin; }
		}
		foreach ( $current as $plugin ) {
			if ( ! is_string( $plugin ) ) { continue; }
			$plugin = ltrim( str_replace( '\\', '/', (string) $plugin ), '/' );
			if ( 0 !== strpos( $plugin, 'nobash-backup/' ) ) { continue; }
			if ( is_file( trailingslashit( \Nobash\Backup\Compat\Paths::plugins_root() ) . $plugin ) ) { $valid[] = $plugin; }
		}
		$valid = array_values( array_unique( $valid ) );
		$serialized = maybe_serialize( $valid );
		if ( null === $shadow_value ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO %i (option_name,option_value,autoload) VALUES ('active_plugins',%s,'yes')", $shadow, $serialized ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE %i SET option_value=%s WHERE option_name='active_plugins'", $shadow, $serialized ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}

	/** Decode only a list of plugin paths without instantiating serialized objects. */
	private static function decode_plugin_list( $value ) {
		if ( ! is_string( $value ) || ! is_serialized( $value ) ) { return array(); }
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Imported data is decoded with class instantiation explicitly disabled.
		$decoded = unserialize( trim( $value ), array( 'allowed_classes' => false ) );
		return is_array( $decoded ) ? array_values( array_filter( $decoded, 'is_string' ) ) : array();
	}

	/** Build publish mappings while excluding NBH operational tables. @return array<int,array<string,mixed>> */
	public function build_map( array $shadow_tables, $shadow_prefix, $target_prefix, $rollback_prefix ) {
		$map = array();
		foreach ( array_values( array_unique( $shadow_tables ) ) as $shadow ) {
			$shadow = $this->clean_identifier( $shadow );
			if ( 0 !== strpos( $shadow, $shadow_prefix ) ) { continue; }
			$suffix = substr( $shadow, strlen( $shadow_prefix ) );
			if ( '' === $suffix || 0 === strpos( $suffix, 'nobash_' ) || 0 === strpos( $suffix, 'actionscheduler_' ) ) { continue; }
			$live = $this->clean_identifier( $target_prefix . $suffix );
			$rollback = $this->clean_identifier( $rollback_prefix . $suffix );
			$map[] = array( 'shadow' => $shadow, 'live' => $live, 'rollback' => $rollback, 'had_live' => $this->table_exists( $live ) );
		}
		if ( empty( $map ) ) { throw new \RuntimeException( esc_html__( 'No safe database tables were available to publish.', 'nobash-backup' ) ); }
		return $map;
	}


	/** Reconcile durable table names after a worker dies around an atomic publish/rollback. @return string */
	public function state( array $map ) {
		if ( empty( $map ) ) { return 'empty'; }
		$prepared = true;
		$published = true;
		$rolled_back = true;
		foreach ( $map as $row ) {
			$shadow_exists = $this->table_exists( (string) $row['shadow'] );
			$live_exists = $this->table_exists( (string) $row['live'] );
			$rollback_exists = $this->table_exists( (string) $row['rollback'] );
			$had_live = ! empty( $row['had_live'] );
			$prepared = $prepared && $shadow_exists && ! $rollback_exists && ( $had_live ? $live_exists : ! $live_exists );
			$published = $published && ! $shadow_exists && $live_exists && ( $had_live ? $rollback_exists : ! $rollback_exists );
			$rolled_back = $rolled_back && ! $shadow_exists && ! $rollback_exists && ( $had_live ? $live_exists : ! $live_exists );
		}
		if ( $published ) { return 'published'; }
		if ( $prepared ) { return 'prepared'; }
		if ( $rolled_back ) { return 'rolled_back'; }
		return 'mixed';
	}

	/** Publish all imported tables. MySQL RENAME TABLE is atomic even for MyISAM. @return void */
	public function publish( array $map ) {
		global $wpdb;
		$this->drop_named( array_column( $map, 'rollback' ) );
		if ( 'sqlite' === Environment::db_engine() ) {
			$this->assert_query_result( $wpdb->query( 'BEGIN IMMEDIATE TRANSACTION' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			try {
				foreach ( $map as $row ) {
					if ( ! empty( $row['had_live'] ) ) { $this->assert_query_result( $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i RENAME TO %i', $row['live'], $row['rollback'] ) ) ); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
					$this->assert_query_result( $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i RENAME TO %i', $row['shadow'], $row['live'] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				}
				$this->assert_query_result( $wpdb->query( 'COMMIT' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed transaction-control literal, not user input.
				$wpdb->query( 'ROLLBACK' );
				throw $e;
			}
			return;
		}
		$parts = array();
		$args  = array();
		foreach ( $map as $row ) {
			if ( ! empty( $row['had_live'] ) ) {
				$parts[] = '%i TO %i';
				$args[] = $row['live'];
				$args[] = $row['rollback'];
			}
			$parts[] = '%i TO %i';
			$args[] = $row['shadow'];
			$args[] = $row['live'];
		}
		$result = $wpdb->query( $wpdb->prepare( 'RENAME TABLE ' . implode( ', ', $parts ), $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fragments contain only %i TO %i; all table identifiers are prepare arguments for the atomic multi-table rename.
		if ( false === $result ) { throw new \RuntimeException( esc_html__( 'Could not atomically publish the restored database tables.', 'nobash-backup' ) ); }
	}

	/** Roll live tables back to their pre-restore names. @return void */
	public function rollback( array $map, $failed_prefix ) {
		global $wpdb;
		$parts = array();
		$failed = array();
		foreach ( $map as $row ) {
			$live = $this->clean_identifier( $row['live'] );
			$rollback = $this->clean_identifier( $row['rollback'] );
			$fail = $this->clean_identifier( $failed_prefix . substr( hash( 'sha256', $live ), 0, 16 ) );
			if ( $this->table_exists( $live ) ) { $parts[] = array( 'from' => $live, 'to' => $fail ); $failed[] = $fail; }
			if ( ! empty( $row['had_live'] ) && $this->table_exists( $rollback ) ) { $parts[] = array( 'from' => $rollback, 'to' => $live ); }
		}
		if ( empty( $parts ) ) { return; }
		$this->drop_named( $failed );
		if ( 'sqlite' === Environment::db_engine() ) {
			$this->assert_query_result( $wpdb->query( 'BEGIN IMMEDIATE TRANSACTION' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			try {
				foreach ( $parts as $row ) { $this->assert_query_result( $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i RENAME TO %i', $row['from'], $row['to'] ) ) ); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				$this->assert_query_result( $wpdb->query( 'COMMIT' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed transaction-control literal, not user input.
				$wpdb->query( 'ROLLBACK' );
				throw $e;
			}
		} else {
			$sql = array();
			$args = array();
			foreach ( $parts as $row ) {
				$sql[] = '%i TO %i';
				$args[] = $row['from'];
				$args[] = $row['to'];
			}
			if ( false === $wpdb->query( $wpdb->prepare( 'RENAME TABLE ' . implode( ', ', $sql ), $args ) ) ) { throw new \RuntimeException( esc_html__( 'Automatic database rollback could not complete.', 'nobash-backup' ) ); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Fragments contain only %i TO %i; all table identifiers are prepare arguments for the atomic rollback rename.
		}
		$this->drop_named( $failed );
	}

	/** Remove shadow/rollback tables after a committed success. @return void */
	public function cleanup( array $map ) {
		$this->drop_named( array_merge( array_column( $map, 'shadow' ), array_column( $map, 'rollback' ) ) );
	}

	/** @param array<int,string> $tables Tables. @return void */
	public function drop_named( array $tables ) {
		global $wpdb;
		foreach ( array_values( array_unique( array_filter( $tables ) ) ) as $table ) {
			$table = $this->clean_identifier( $table );
			if ( '' === $table || ! $this->table_exists( $table ) ) { continue; }
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	/** @return string */
	public function clean_identifier( $name ) {
		$name = (string) $name;
		if ( ! preg_match( '/^[A-Za-z0-9_$]{1,64}$/D', $name ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Unsupported database table identifier. No name was changed or truncated.', 'nobash-backup' ) );
		}
		return $name;
	}

	/** Convert a wpdb failure result into a restore exception. */
	private function assert_query_result( $result ) {
		if ( false === $result ) {
			throw new \RuntimeException( esc_html__( 'The database table transaction failed and was not committed.', 'nobash-backup' ) );
		}
	}

}

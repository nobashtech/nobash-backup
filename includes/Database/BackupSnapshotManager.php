<?php
/**
 * Consistent snapshots for non-transactional database tables during backup.
 *
 * @package Nobash\Backup
 */
namespace Nobash\Backup\Database;

use Nobash\Backup\Compat\DatabaseAdapter;
use Nobash\Backup\Compat\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Freeze MyISAM/MEMORY-style tables into private InnoDB shadows before the
 * resumable dump spans multiple HTTP requests. Transactional tables continue
 * to use the normal bounded cursor path.
 */
class BackupSnapshotManager {
	/** @var DatabaseAdapter */
	private $db;

	public function __construct( DatabaseAdapter $db = null ) { $this->db = $db ?: new DatabaseAdapter(); }

	/** Whether an engine needs a stable copy for a multi-request dump. */
	public static function requires_snapshot_engine( $engine ) {
		$engine = strtoupper( trim( (string) $engine ) );
		if ( '' === $engine || 'SQLITE' === $engine ) { return false; }
		return ! in_array( $engine, array( 'INNODB', 'XTRADB', 'NDB', 'NDBCLUSTER' ), true );
	}

	/** @return array<int,string> */
	public function non_transactional_tables( array $tables ) {
		if ( 'sqlite' === Environment::db_engine() ) { return array(); }
		$out = array();
		foreach ( $tables as $table ) {
			$table = (string) $table;
			$engine = $this->db->table_engine( $table );
			/* Unknown engine metadata should not make a healthy backup fail. The
			 * dumper still bounds each table to a finite cursor; only positively
			 * identified non-transactional engines need the blocking copy. */
			if ( '' !== $engine && self::requires_snapshot_engine( $engine ) ) { $out[] = $table; }
		}
		return array_values( array_unique( $out ) );
	}

	/** Create one deterministic private InnoDB shadow while holding a source read lock. */
	public function snapshot_table( $source, $job_id ) {
		global $wpdb;
		$source = (string) $source;
		if ( '' === $source ) { throw new \InvalidArgumentException( 'Snapshot source table is required.' ); }
		$shadow = $this->shadow_name( $source, $job_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dynamic identifiers are passed through the WordPress %i placeholder.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $shadow ) );
		if ( false === $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $shadow, $source ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			throw new \RuntimeException( esc_html__( 'Could not create a stable database snapshot table.', 'nobash-backup' ) );
		}
		if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $shadow ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $shadow ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			throw new \RuntimeException( esc_html__( 'Could not convert the database snapshot to a transactional engine.', 'nobash-backup' ) );
		}

		$columns = array();
		foreach ( $this->db->get_columns( $source ) as $column ) {
			$name = (string) ( $column['Field'] ?? '' );
			$extra = strtoupper( (string) ( $column['Extra'] ?? '' ) );
			if ( '' !== $name && false === strpos( $extra, 'GENERATED' ) ) { $columns[] = $name; }
		}
		if ( empty( $columns ) ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $shadow ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			throw new \RuntimeException( esc_html__( 'Could not resolve copyable columns for a stable database snapshot.', 'nobash-backup' ) );
		}
		$column_placeholders = implode( ',', array_fill( 0, count( $columns ), '%i' ) );
		$locked = false;
		try {
			/* MyISAM/MEMORY cannot provide a transaction snapshot. A connection-
			 * scoped READ lock is held only while copying this one table. */
			if ( false === $wpdb->query( $wpdb->prepare( 'LOCK TABLES %i READ, %i WRITE', $source, $shadow ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				throw new \RuntimeException( esc_html__( 'Could not lock a non-transactional table for a consistent backup.', 'nobash-backup' ) );
			}
			$locked = true;
			$sql = 'INSERT INTO %i (' . $column_placeholders . ') SELECT ' . $column_placeholders . ' FROM %i';
			$args = array_merge( array( $shadow ), $columns, $columns, array( $source ) );
			if ( false === $wpdb->query( $wpdb->prepare( $sql, $args ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Template contains only generated %i placeholders; all identifiers are supplied as prepare arguments.
				throw new \RuntimeException( esc_html__( 'Could not copy a non-transactional table into its stable backup snapshot.', 'nobash-backup' ) );
			}
		} finally {
			if ( $locked ) { $wpdb->query( 'UNLOCK TABLES' ); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		return $shadow;
	}

	/** Drop only shadows generated by this manager. */
	public function cleanup( array $map ) {
		global $wpdb;
		foreach ( $map as $shadow ) {
			$shadow = (string) $shadow;
			if ( ! $this->is_managed_shadow( $shadow ) ) { continue; }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Managed snapshot identifier is prepared with %i.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $shadow ) );
		}
	}

	/** Best-effort terminal cleanup from a job cursor. */
	public static function cleanup_cursor( array $cursor ) {
		try { ( new self() )->cleanup( is_array( $cursor['database_snapshots'] ?? null ) ? $cursor['database_snapshots'] : array() ); } catch ( \Throwable $ignored ) {}
	}

	private function shadow_name( $source, $job_id ) {
		global $wpdb;
		$prefix = isset( $wpdb->prefix ) ? preg_replace( '/[^A-Za-z0-9_]/', '', (string) $wpdb->prefix ) : 'wp_';
		$job = base_convert( (string) max( 1, absint( $job_id ) ), 10, 36 );
		$name = $prefix . 'nobash_bsnap_' . $job . '_' . substr( hash( 'sha256', (string) $source ), 0, 16 );
		return substr( $name, 0, 64 );
	}

	private function is_managed_shadow( $table ) { return (bool) preg_match( '/(?:^|_)nobash_bsnap_[a-z0-9]+_[a-f0-9]{16}$/i', (string) $table ); }
}

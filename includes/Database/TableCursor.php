<?php
/**
 * Stable keyset cursor planning for large database tables.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Database;

use Nobash\Backup\Compat\DatabaseAdapter;

defined( 'ABSPATH' ) || exit;

/** Builds deterministic cursor plans and SQL predicates. */
class TableCursor {
	/** @var DatabaseAdapter */
	private $db;

	public function __construct( ?DatabaseAdapter $db = null ) {
		$this->db = $db ?: new DatabaseAdapter();
	}

	/**
	 * Pick the safest available cursor strategy.
	 *
	 * @param string $table Table name.
	 * @return array<string,mixed>
	 */
	public function plan( $table ) {
		$columns = $this->db->get_columns( $table );
		$primary = array();
		foreach ( $columns as $column ) {
			if ( isset( $column['Key'] ) && 'PRI' === strtoupper( (string) $column['Key'] ) ) {
				$primary[] = (string) $column['Field'];
			}
		}
		if ( ! empty( $primary ) ) {
			$upper = $this->upper_values( $table, $primary );
			return array( 'strategy' => 'keyset', 'columns' => $primary, 'values' => array(), 'upper_values' => $upper, 'upper_bound_empty' => empty( $upper ), 'rows' => 0 );
		}

		$unique = $this->db->get_unique_index_columns( $table );
		if ( ! empty( $unique ) && $this->columns_are_non_nullable( $unique, $columns ) ) {
			$upper = $this->upper_values( $table, $unique );
			return array( 'strategy' => 'keyset', 'columns' => $unique, 'values' => array(), 'upper_values' => $upper, 'upper_bound_empty' => empty( $upper ), 'rows' => 0 );
		}

		// A nullable UNIQUE index is not a safe keyset cursor: SQL comparisons
		// against NULL can skip rows and UNIQUE indexes may contain multiple NULLs.
		// Fall back to the bounded OFFSET strategy rather than silently producing
		// an incomplete database export.

		// Keyless tables cannot be resumed with a stable key. Keep the fallback
		// explicit so reports can warn that concurrent writes may affect ordering.
		return array( 'strategy' => 'offset', 'columns' => array(), 'offset' => 0, 'rows' => 0, 'warning' => 'No safe non-null primary or unique key; OFFSET fallback is required.' );
	}


	/**
	 * Whether every cursor column is explicitly non-nullable.
	 *
	 * MySQL/MariaDB SHOW COLUMNS exposes Null=NO. SQLite metadata in the
	 * adapter intentionally does not promise nullability for unique indexes, so
	 * those indexes conservatively use OFFSET unless they are primary keys.
	 *
	 * @param array<int,string> $wanted  Cursor columns.
	 * @param array<int,array<string,mixed>> $metadata Column metadata.
	 * @return bool
	 */
	private function columns_are_non_nullable( array $wanted, array $metadata ) {
		$map = array();
		foreach ( $metadata as $column ) {
			$field = isset( $column['Field'] ) ? (string) $column['Field'] : '';
			if ( '' !== $field ) {
				$map[ $field ] = strtoupper( (string) ( $column['Null'] ?? '' ) );
			}
		}
		foreach ( $wanted as $field ) {
			if ( ! isset( $map[ $field ] ) || 'NO' !== $map[ $field ] ) {
				return false;
			}
		}
		return true;
	}

	/** Capture the highest key visible when a table export starts.
	 *
	 * New rows with larger auto-increment keys can otherwise make a busy site's
	 * backup chase a moving tail for minutes or hours. Bounding the keyset keeps
	 * every table finite and deterministic without holding a long database lock.
	 *
	 * @param string $table Table name.
	 * @param array<int,string> $columns Cursor columns.
	 * @return array<int,mixed>
	 */
	private function upper_values( $table, array $columns ) {
		global $wpdb;
		if ( empty( $columns ) ) { return array(); }
		$select_placeholders = implode( ',', array_fill( 0, count( $columns ), '%i' ) );
		$order_placeholders  = implode( ' DESC,', array_fill( 0, count( $columns ), '%i' ) ) . ' DESC';
		$args = array_merge( $columns, array( $table ), $columns );
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- A fresh export boundary must not be cached; all identifiers use %i arguments.
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fragments are generated exclusively from %i and DESC; every identifier is passed separately to prepare().
			$wpdb->prepare( 'SELECT ' . $select_placeholders . ' FROM %i ORDER BY ' . $order_placeholders . ' LIMIT 1', $args ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Dynamic identifier placeholder counts are generated locally and all identifiers are prepare arguments.
		if ( '' !== (string) $wpdb->last_error ) { throw new \RuntimeException( sprintf( /* translators: %s database error */ esc_html__( 'Could not capture the database export boundary: %s', 'nobash-backup' ), esc_html( $wpdb->last_error ) ) ); }
		if ( ! is_array( $row ) ) { return array(); }
		$values = array();
		foreach ( $columns as $column ) {
			if ( ! array_key_exists( $column, $row ) ) { return array(); }
			$values[] = $row[ $column ];
		}
		return $values;
	}

	/** Build an inclusive lexicographic upper bound for a captured keyset.
	 * @param array<int,string> $columns Cursor columns.
	 * @param array<int,mixed> $values Captured maximum key values.
	 * @return array{sql:string,args:array<int,mixed>}
	 */
	public function upper_predicate( array $columns, array $values ) {
		if ( empty( $columns ) || count( $columns ) !== count( $values ) ) { return array( 'sql' => '', 'args' => array() ); }
		$or = array();
		$args = array();
		$last_index = count( $columns ) - 1;
		for ( $i = 0; $i < count( $columns ); ++$i ) {
			$and = array();
			for ( $j = 0; $j < $i; ++$j ) {
				$and[] = '%i = %s';
				$args[] = $columns[ $j ];
				$args[] = $values[ $j ];
			}
			$and[] = '%i' . ( $i === $last_index ? ' <= %s' : ' < %s' );
			$args[] = $columns[ $i ];
			$args[] = $values[ $i ];
			$or[] = '(' . implode( ' AND ', $and ) . ')';
		}
		return array( 'sql' => '(' . implode( ' OR ', $or ) . ')', 'args' => $args );
	}

	/**
	 * Build lexicographic keyset predicate.
	 *
	 * @param array<int,string> $columns Cursor columns.
	 * @param array<int,mixed>  $values Last values.
	 * @return array{sql:string,args:array<int,mixed>}
	 */
	public function predicate( array $columns, array $values ) {
		if ( empty( $columns ) || count( $columns ) !== count( $values ) ) {
			return array( 'sql' => '', 'args' => array() );
		}
		$or = array();
		$args = array();
		for ( $i = 0; $i < count( $columns ); $i++ ) {
			$and = array();
			for ( $j = 0; $j < $i; $j++ ) {
				$and[] = '%i = %s';
				$args[] = $columns[ $j ];
				$args[] = $values[ $j ];
			}
			$and[] = '%i > %s';
			$args[] = $columns[ $i ];
			$args[] = $values[ $i ];
			$or[] = '(' . implode( ' AND ', $and ) . ')';
		}
		return array( 'sql' => '(' . implode( ' OR ', $or ) . ')', 'args' => $args );
	}
}

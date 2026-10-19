<?php
/**
 * Portable database operations (MySQL, MariaDB, SQLite).
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Class DatabaseAdapter
 */
class DatabaseAdapter {

	/**
	 * List all tables visible to WordPress.
	 *
	 * @return array<int, string>
	 */
	public function list_tables() {
		global $wpdb;

		$engine = Environment::db_engine();

		if ( 'sqlite' === $engine ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$tables = $wpdb->get_col( "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'" );
			return is_array( $tables ) ? $tables : array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( is_array( $tables ) && ! empty( $tables ) ) {
			return $tables;
		}

		// Fallback for exotic drivers that reject SHOW TABLES.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_col( "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'" );
		return is_array( $tables ) ? $tables : array();
	}

	/** List only physical base tables for cursor-based row export. @return array<int,string> */
	public function list_base_tables() {
		global $wpdb;
		if ( 'sqlite' === Environment::db_engine() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col( "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'" );
			return is_array( $rows ) ? array_values( array_map( 'strval', $rows ) ) : array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'", ARRAY_N );
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) { if ( isset( $row[0] ) ) { $out[] = (string) $row[0]; } }
		return $out;
	}

	/** Return the storage engine for a physical table. Empty means unknown. @return string */
	public function table_engine( $table ) {
		global $wpdb;
		if ( 'sqlite' === Environment::db_engine() ) { return 'SQLITE'; }
		$table = (string) $table;
		if ( '' === $table ) { return ''; }
		$wpdb->last_error = '';
		// information_schema avoids LIKE wildcard ambiguity in table names.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s LIMIT 1', $table ) );
		if ( '' === trim( (string) $engine ) ) {
			/* Some restricted hosts deny information_schema metadata while SHOW
			 * TABLE STATUS remains available. Use an exact WHERE predicate rather
			 * than LIKE so underscores in WordPress prefixes are never wildcards. */
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
			$engine = is_array( $row ) ? (string) ( $row['Engine'] ?? '' ) : '';
		}
		return strtoupper( trim( (string) $engine ) );
	}

	/** Discover views, triggers, routines and events without failing the main table export. @return array<int,array<string,string>> */
	public function list_database_objects() {
		global $wpdb;
		$out = array();
		if ( 'sqlite' === Environment::db_engine() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( "SELECT type,name,tbl_name FROM sqlite_master WHERE type IN ('view','trigger') AND name NOT LIKE 'sqlite_%' ORDER BY type,name", ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) { $out[] = array( 'type' => strtolower( (string) $row['type'] ), 'name' => (string) $row['name'], 'table' => (string) ( $row['tbl_name'] ?? '' ) ); }
			return $out;
		}
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$views = $wpdb->get_results( "SHOW FULL TABLES WHERE Table_type = 'VIEW'", ARRAY_N );
		foreach ( is_array( $views ) ? $views : array() as $row ) { if ( isset( $row[0] ) ) { $out[] = array( 'type' => 'view', 'name' => (string) $row[0], 'table' => '' ); } }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$triggers = $wpdb->get_results( 'SHOW TRIGGERS', ARRAY_A );
		foreach ( is_array( $triggers ) ? $triggers : array() as $row ) { $out[] = array( 'type' => 'trigger', 'name' => (string) ( $row['Trigger'] ?? '' ), 'table' => (string) ( $row['Table'] ?? '' ) ); }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$routines = $wpdb->get_results( "SELECT ROUTINE_NAME,ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_TYPE,ROUTINE_NAME", ARRAY_A );
		foreach ( is_array( $routines ) ? $routines : array() as $row ) { $out[] = array( 'type' => strtolower( (string) ( $row['ROUTINE_TYPE'] ?? '' ) ), 'name' => (string) ( $row['ROUTINE_NAME'] ?? '' ), 'table' => '' ); }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$events = $wpdb->get_results( 'SHOW EVENTS', ARRAY_A );
		foreach ( is_array( $events ) ? $events : array() as $row ) { $out[] = array( 'type' => 'event', 'name' => (string) ( $row['Name'] ?? '' ), 'table' => '' ); }
		return array_values( array_filter( $out, static function ( $object ) { return ! empty( $object['name'] ) && in_array( $object['type'], array( 'view','trigger','procedure','function','event' ), true ); } ) );
	}

	/** Determine whether a named database object exists before replacing it. @return bool */
	public function object_exists( array $object ) {
		global $wpdb;
		$type = strtolower( sanitize_key( (string) ( $object['type'] ?? '' ) ) );
		$name = preg_replace( '/[^A-Za-z0-9_$-]/', '', (string) ( $object['name'] ?? '' ) );
		if ( '' === $name || ! in_array( $type, array( 'view','trigger','procedure','function','event' ), true ) ) { return false; }
		if ( 'sqlite' === Environment::db_engine() ) {
			if ( ! in_array( $type, array( 'view', 'trigger' ), true ) ) { return false; }
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM sqlite_master WHERE type=%s AND name=%s', $type, $name ) );
			return (int) $count > 0;
		}
		$wpdb->last_error = '';
		switch ( $type ) {
			case 'view':
				$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $name ) );
				break;
			case 'trigger':
				$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=%s', $name ) );
				break;
			case 'procedure':
			case 'function':
				$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME=%s AND ROUTINE_TYPE=%s', $name, strtoupper( $type ) ) );
				break;
			case 'event':
				$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE() AND EVENT_NAME=%s', $name ) );
				break;
			default:
				$count = 0;
		}
		if ( '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( sprintf( /* translators: %s database error */ esc_html__( 'Could not verify a database object before restore: %s', 'nobash-backup' ), esc_html( $wpdb->last_error ) ) );
		}
		return (int) $count > 0;
	}

	/** Return portable CREATE SQL for a database object. @return string */
	public function get_create_object( array $object ) {
		global $wpdb;
		$type = strtolower( sanitize_key( (string) ( $object['type'] ?? '' ) ) );
		$name = preg_replace( '/[^A-Za-z0-9_$-]/', '', (string) ( $object['name'] ?? '' ) );
		if ( '' === $name || ! in_array( $type, array( 'view','trigger','procedure','function','event' ), true ) ) { throw new \InvalidArgumentException( 'Invalid database object.' ); }
		if ( 'sqlite' === Environment::db_engine() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$sql = $wpdb->get_var( $wpdb->prepare( 'SELECT sql FROM sqlite_master WHERE type=%s AND name=%s', $type, $name ) );
			if ( ! is_string( $sql ) || '' === trim( $sql ) ) { throw new \RuntimeException( sprintf( /* translators: %s: object name */ esc_html__( 'Could not read database object %s.', 'nobash-backup' ), esc_html( $name ) ) ); }
			return $sql;
		}
		switch ( $type ) {
			case 'view':
				$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE VIEW %i', $name ), ARRAY_A );
				break;
			case 'trigger':
				$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TRIGGER %i', $name ), ARRAY_A );
				break;
			case 'procedure':
				$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE PROCEDURE %i', $name ), ARRAY_A );
				break;
			case 'function':
				$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE FUNCTION %i', $name ), ARRAY_A );
				break;
			case 'event':
				$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE EVENT %i', $name ), ARRAY_A );
				break;
			default:
				$row = null;
		}
		if ( ! is_array( $row ) ) { throw new \RuntimeException( sprintf( /* translators: %s: object name */ esc_html__( 'Could not read database object %s.', 'nobash-backup' ), esc_html( $name ) ) ); }
		$create = '';
		foreach ( $row as $key => $value ) { if ( false !== stripos( (string) $key, 'create' ) && is_string( $value ) ) { $create = $value; break; } }
		if ( '' === $create ) { throw new \RuntimeException( sprintf( /* translators: %s: object name */ esc_html__( 'Could not read database object %s.', 'nobash-backup' ), esc_html( $name ) ) ); }
		// Definers frequently do not exist on a migrated server. SQL SECURITY remains intact.
		$create = preg_replace( '/\sDEFINER\s*=\s*(?:`[^`]+`|[^@\s]+)@(?:`[^`]+`|[^\s]+)\s*/i', ' ', $create );
		return trim( (string) $create );
	}

	/** Format a DROP + CREATE block using a custom delimiter for compound bodies. @return string */
	public function format_object_block( array $object ) {
		$type = strtolower( sanitize_key( (string) ( $object['type'] ?? '' ) ) );
		$name = preg_replace( '/[^A-Za-z0-9_$-]/', '', (string) ( $object['name'] ?? '' ) );
		$create = $this->get_create_object( $object );
		$q = $this->quote_id( $name );
		if ( 'sqlite' === Environment::db_engine() ) { return "\n-- Object: {$type} {$name}\nDROP " . strtoupper( $type ) . " IF EXISTS {$q};\n{$create};\n"; }
		return "\n-- Object: {$type} {$name}\nDELIMITER $$\nDROP " . strtoupper( $type ) . " IF EXISTS {$q}$$\n{$create}$$\nDELIMITER ;\n";
	}

	/** Capture existing objects before a restore replaces them. @return array<int,array<string,string>> */
	public function write_object_snapshot( array $objects, $file ) {
		$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
		if ( false === $wp_filesystem->put_contents( $file, '' ) ) {
			throw new \RuntimeException( esc_html__( 'Could not initialize the database object rollback snapshot.', 'nobash-backup' ) );
		}
		$captured = array();
		foreach ( $objects as $object ) {
			$type = strtolower( sanitize_key( (string) ( $object['type'] ?? '' ) ) );
			$name = preg_replace( '/[^A-Za-z0-9_$-]/', '', (string) ( $object['name'] ?? '' ) );
			if ( '' === $name || ! in_array( $type, array( 'view','trigger','procedure','function','event' ), true ) ) { continue; }
			$normalized = array( 'type' => $type, 'name' => $name, 'table' => (string) ( $object['table'] ?? '' ) );
			if ( ! $this->object_exists( $normalized ) ) { continue; }
			$block = $this->format_object_block( $normalized );
			$existing = $wp_filesystem->get_contents( $file );
			if ( false === $existing ) {
				$existing = '';
			}
			if ( false === $wp_filesystem->put_contents( $file, $existing . $block ) ) {
				throw new \RuntimeException( esc_html__( 'Could not write the database object rollback snapshot.', 'nobash-backup' ) );
			}
			$captured[] = $normalized;
		}
		return $captured;
	}

	/** Drop named database objects in dependency-safe order. @return void */
	public function drop_objects( array $objects ) {
		global $wpdb;
		$weight = array( 'trigger' => 1, 'view' => 2, 'event' => 3, 'procedure' => 4, 'function' => 5 );
		usort( $objects, static function ( $a, $b ) use ( $weight ) {
			return ( $weight[ strtolower( (string) ( $a['type'] ?? '' ) ) ] ?? 99 ) <=> ( $weight[ strtolower( (string) ( $b['type'] ?? '' ) ) ] ?? 99 );
		} );
		foreach ( $objects as $object ) {
			$type = strtolower( sanitize_key( (string) ( $object['type'] ?? '' ) ) );
			$name = preg_replace( '/[^A-Za-z0-9_$-]/', '', (string) ( $object['name'] ?? '' ) );
			if ( '' === $name || ! in_array( $type, array( 'view','trigger','procedure','function','event' ), true ) ) { continue; }
			if ( 'sqlite' === Environment::db_engine() && ! in_array( $type, array( 'view', 'trigger' ), true ) ) { continue; }
			switch ( $type ) {
				case 'view':
					$result = $wpdb->query( $wpdb->prepare( 'DROP VIEW IF EXISTS %i', $name ) );
					break;
				case 'trigger':
					$result = $wpdb->query( $wpdb->prepare( 'DROP TRIGGER IF EXISTS %i', $name ) );
					break;
				case 'procedure':
					$result = $wpdb->query( $wpdb->prepare( 'DROP PROCEDURE IF EXISTS %i', $name ) );
					break;
				case 'function':
					$result = $wpdb->query( $wpdb->prepare( 'DROP FUNCTION IF EXISTS %i', $name ) );
					break;
				case 'event':
					$result = $wpdb->query( $wpdb->prepare( 'DROP EVENT IF EXISTS %i', $name ) );
					break;
				default:
					$result = false;
			}
			if ( false === $result && ! empty( $wpdb->last_error ) ) {
				throw new \RuntimeException( sprintf( /* translators: %s database error */ esc_html__( 'Could not remove a database object during recovery: %s', 'nobash-backup' ), esc_html( $wpdb->last_error ) ) );
			}
		}
	}

	/**
	 * Quote an identifier (table/column).
	 *
	 * @param string $name Identifier.
	 * @return string
	 */
	public function quote_id( $name ) {
		$name = (string) $name;
		if ( '' === $name || false !== strpos( $name, "\0" ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Invalid database identifier.', 'nobash-backup' ) );
		}
		// Preserve legal identifier characters. Stripping quotes can silently
		// redirect operations to a different table or column with a similar name.
		if ( 'sqlite' === Environment::db_engine() ) {
			return '"' . str_replace( '"', '""', $name ) . '"';
		}
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Escape a string literal for SQL VALUES.
	 *
	 * @param string $value Raw value.
	 * @return string Escaped (without surrounding quotes).
	 */
	public function escape( $value ) {
		global $wpdb;

		// Strip surrounding quotes from prepare('%s') for portable escaping.
		$prepared = $wpdb->prepare( '%s', (string) $value );
		if ( is_string( $prepared ) && strlen( $prepared ) >= 2 ) {
			return substr( $prepared, 1, -1 );
		}

		return addslashes( (string) $value );
	}

	/**
	 * CREATE TABLE DDL for a table.
	 *
	 * @param string $table Table name.
	 * @return string
	 * @throws \RuntimeException When schema cannot be read.
	 */
	public function get_create_table( $table ) {
		global $wpdb;

		$engine = Environment::db_engine();

		if ( 'sqlite' === $engine ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$sql = $wpdb->get_var( $wpdb->prepare( 'SELECT sql FROM sqlite_master WHERE type=%s AND name=%s', 'table', $table ) );
			if ( ! is_string( $sql ) || '' === $sql ) {
				throw new \RuntimeException( sprintf( /* translators: %s table name */ esc_html__( 'Could not get CREATE TABLE for %s', 'nobash-backup' ), esc_html( $table ) ) );
			}
			return $sql;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table identifier is prepared with %i.
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
		if ( ! $row || empty( $row[1] ) ) {
			throw new \RuntimeException( sprintf( /* translators: %s table name */ esc_html__( 'Could not get CREATE TABLE for %s', 'nobash-backup' ), esc_html( $table ) ) );
		}
		return (string) $row[1];
	}

	/**
	 * Column metadata for search/replace.
	 *
	 * @param string $table Table.
	 * @return array<int, array{Field:string,Type:string,Key:string}>
	 */
	public function get_columns( $table ) {
		global $wpdb;

		$engine = Environment::db_engine();

		if ( 'sqlite' === $engine ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared with %i.
			$rows = $wpdb->get_results( $wpdb->prepare( 'PRAGMA table_info(%i)', $table ), ARRAY_A );
			if ( ! is_array( $rows ) ) {
				return array();
			}
			$out = array();
			foreach ( $rows as $row ) {
				$out[] = array(
					'Field' => isset( $row['name'] ) ? (string) $row['name'] : '',
					'Type'  => isset( $row['type'] ) ? (string) $row['type'] : 'TEXT',
					'Key'   => ( ! empty( $row['pk'] ) ) ? 'PRI' : '',
				);
			}
			return $out;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared with %i.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * SQL preamble written at the start of a dump.
	 *
	 * @return string
	 */
	public function dump_preamble() {
		$engine = Environment::db_engine();
		$lines  = array(
			'-- Nobash Backup SQL dump',
			'-- Engine: ' . $engine,
			'-- Generated: ' . gmdate( 'c' ),
			'',
		);

		if ( 'sqlite' === $engine ) {
			$lines[] = 'PRAGMA foreign_keys=OFF;';
		} else {
			$lines[] = 'SET NAMES utf8mb4;';
			$lines[] = 'SET FOREIGN_KEY_CHECKS=0;';
			$lines[] = 'SET SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\';';
		}

		$lines[] = '';
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * SQL epilogue at end of dump.
	 *
	 * @return string
	 */
	public function dump_epilogue() {
		if ( 'sqlite' === Environment::db_engine() ) {
			return "\nPRAGMA foreign_keys=ON;\n";
		}
		return "\nSET FOREIGN_KEY_CHECKS=1;\n";
	}

	/**
	 * Prepare the connection before importing.
	 *
	 * @return void
	 */
	public function begin_import() {
		global $wpdb;
		if ( 'sqlite' === Environment::db_engine() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'PRAGMA foreign_keys=OFF' );
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
		// Rebuilding isolated/shadow tables does not benefit from per-row secondary
		// unique-index probes. This is connection-local and is restored below.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET UNIQUE_CHECKS=0' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET NAMES utf8mb4' );
	}

	/**
	 * Restore connection flags after import.
	 *
	 * @return void
	 */
	public function end_import() {
		global $wpdb;
		if ( 'sqlite' === Environment::db_engine() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'PRAGMA foreign_keys=ON' );
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET UNIQUE_CHECKS=1' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
	}

	/**
	 * Return columns from the first deterministic unique index.
	 *
	 * @param string $table Table name.
	 * @return array<int,string>
	 */
	public function get_unique_index_columns( $table ) {
		global $wpdb;
		if ( 'sqlite' === Environment::db_engine() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared with %i.
			$indexes = $wpdb->get_results( $wpdb->prepare( 'PRAGMA index_list(%i)', $table ), ARRAY_A );
			foreach ( is_array( $indexes ) ? $indexes : array() as $index ) {
				if ( empty( $index['unique'] ) || empty( $index['name'] ) ) { continue; }
				$name = (string) $index['name'];
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Index identifier is prepared with %i.
				$parts = $wpdb->get_results( $wpdb->prepare( 'PRAGMA index_info(%i)', $name ), ARRAY_A );
				$columns = array(); foreach ( is_array( $parts ) ? $parts : array() as $part ) { if ( isset( $part['name'] ) ) { $columns[] = (string) $part['name']; } }
				if ( ! empty( $columns ) ) { return $columns; }
			}
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifier is prepared with %i.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Non_unique = 0', $table ), ARRAY_A );
		$indexes = array(); foreach ( is_array( $rows ) ? $rows : array() as $row ) { $name=(string)($row['Key_name']??''); if('PRIMARY'===$name){continue;} $seq=(int)($row['Seq_in_index']??0); $indexes[$name][$seq]=(string)($row['Column_name']??''); }
		ksort($indexes); foreach($indexes as $parts){ksort($parts);$columns=array_values(array_filter($parts));if(!empty($columns)){return $columns;}}
		return array();
	}

	/**
	 * DROP + CREATE statements for dump file.
	 *
	 * @param string $table Table.
	 * @return string
	 */
	public function format_create_block( $table, $schema_source = '' ) {
		$q      = $this->quote_id( $table );
		$schema_source = '' === (string) $schema_source ? (string) $table : (string) $schema_source;
		$create = $this->get_create_table( $schema_source );
		if ( $schema_source !== (string) $table && 'sqlite' !== Environment::db_engine() ) {
			$q_source = $this->quote_id( $schema_source );
			$pattern = '/^(CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+)' . preg_quote( $q_source, '/' ) . '/i';
			$rewritten = preg_replace( $pattern, '$1' . $q, $create, 1, $count );
			if ( 1 !== $count || ! is_string( $rewritten ) ) { throw new \RuntimeException( esc_html__( 'Could not normalize a stable snapshot schema for export.', 'nobash-backup' ) ); }
			$create = $rewritten;
		}
		$sql    = "\n\n-- Table: {$table}\n";
		$sql   .= "DROP TABLE IF EXISTS {$q};\n";
		$sql   .= $create . ";\n\n";
		return $sql;
	}
}

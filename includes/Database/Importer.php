<?php
/** Resumable statement-safe SQL importer. @package Nobash\Backup */
namespace Nobash\Backup\Database;

use Nobash\Backup\Compat\DatabaseAdapter;

defined( 'ABSPATH' ) || exit;

class Importer {
	/** @var DatabaseAdapter */ private $db;
	/** @var SqlStatementReader */ private $reader;
	/** @var LargeInsertSplitter */ private $splitter;
	public function __construct() { $this->db = new DatabaseAdapter(); $this->reader = new SqlStatementReader(); $this->splitter = new LargeInsertSplitter(); }

	/** @return array{offset:int,done:bool,statements:int,bytes:int,tables:array<int,string>,objects:array<int,array<string,string>>,parser_state:array<string,mixed>} */
	public function import_chunk( $file, $byte_offset, $time_budget, $source_prefix = '', $target_prefix = '', array $parser_state = array(), $policy = 'tables', array $allowed_targets = array() ) {
		global $wpdb;
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no fseek()/streaming primitives; resumable SQL-dump import requires a direct file handle read statement-by-statement from a byte offset.
		$fh = fopen( $file, 'rb' );
		if ( ! $fh ) { throw new \RuntimeException( esc_html__( 'Cannot open SQL dump.', 'nobash-backup' ) ); }
		$this->db->begin_import();
		$start = microtime( true );
		$offset = max( 0, (int) $byte_offset );
		$statements = 0;
		$bytes = 0;
		$done = false;
		$tables = array();
		$objects = array();
		$transaction_open = false;
		try {
			while ( ( microtime( true ) - $start ) < max( 0.25, (float) $time_budget ) ) {
				$item = $this->reader->next( $fh, $offset, $parser_state );
				if ( null === $item ) { $done = true; break; }
				$bytes += (int) $item['offset'] - $offset;
				$offset = (int) $item['offset'];
				$parser_state = isset( $item['state'] ) && is_array( $item['state'] ) ? $item['state'] : $parser_state;
				if ( ! empty( $item['directive'] ) ) {
					continue;
				}
				$sql = trim( (string) $item['sql'] );
				if ( '' !== $source_prefix && '' !== $target_prefix && $source_prefix !== $target_prefix ) {
					$sql = $this->replace_table_prefix( $sql, $source_prefix, $target_prefix );
				}
				$this->assert_statement_allowed( $sql, (string) $policy, $allowed_targets, (string) $target_prefix );
				$tracked_sql = $this->strip_leading_comments( $sql );
				$table = $this->statement_table( $tracked_sql );
				if ( '' !== $table ) { $tables[] = $table; }
				$object = $this->statement_object( $tracked_sql );
				if ( ! empty( $object ) ) { $objects[] = $object; }
				if ( '' !== $sql ) {
					$is_dml = $this->is_bulk_dml( $tracked_sql );
					/* Keep a whole bounded import slice in one database transaction whenever
					 * it contains ordinary INSERT/REPLACE data. This removes hundreds of
					 * autocommit fsyncs on large restores and, on transactional tables,
					 * guarantees a killed PHP worker cannot leave a half-checkpointed slice.
					 * DDL/session statements are executed outside the transaction because
					 * MySQL may implicitly commit them. */
					if ( $is_dml && ! $transaction_open ) {
						$this->begin_slice_transaction();
						$transaction_open = true;
					} elseif ( ! $is_dml && $transaction_open ) {
						$this->commit_slice_transaction();
						$transaction_open = false;
					}
					foreach ( $this->splitter->split( $sql, $this->query_chunk_bytes() ) as $query ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
						$result = $wpdb->query( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Each archive statement passes assert_statement_allowed() and target-table restrictions before bounded splitting; preparing a complete SQL dump as a string would change its semantics.
						if ( false === $result && ! empty( $wpdb->last_error ) ) {
							throw new \RuntimeException( sprintf( /* translators: %s SQL error */ esc_html__( 'SQL import error: %s', 'nobash-backup' ), esc_html( $wpdb->last_error ) ) );
						}
					}
				}
				++$statements;
				if ( ! empty( $item['eof'] ) || feof( $fh ) ) { $done = true; break; }
			}
			if ( $transaction_open ) {
				$this->commit_slice_transaction();
				$transaction_open = false;
			}
		} catch ( \Throwable $error ) {
			if ( $transaction_open ) {
				$this->rollback_slice_transaction();
				$transaction_open = false;
			}
			throw $error;
		} finally {
			fclose( $fh );
			// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->db->end_import();
		}
		return array(
			'offset' => $offset,
			'done' => $done,
			'statements' => $statements,
			'bytes' => $bytes,
			'tables' => array_values( array_unique( $tables ) ),
			'objects' => array_values( $objects ),
			'parser_state' => $parser_state,
		);
	}

	/** Conservatively rewrite SQL table/object identifiers during cross-prefix migration. */
	private function replace_table_prefix( $sql, $source, $target ) {
		$source = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $source );
		$target = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $target );
		if ( '' === $source || '' === $target ) { return $sql; }
		$sql = preg_replace( '/`' . preg_quote( $source, '/' ) . '([A-Za-z0-9_$]+)`/', '`' . $target . '$1`', $sql );
		$sql = preg_replace( '/(?<=\s)' . preg_quote( $source, '/' ) . '([A-Za-z0-9_$]+)(?=\s|\()/i', $target . '$1', $sql );
		return (string) $sql;
	}

	/** Extract the primary table touched by common dump statements. @return string */
	private function statement_table( $sql ) {
		$patterns = array(
			'/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([A-Za-z0-9_$]+)[`"]?/i',
			'/^\s*DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?[`"]?([A-Za-z0-9_$]+)[`"]?/i',
			'/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+[`"]?([A-Za-z0-9_$]+)[`"]?/i',
			'/^\s*REPLACE\s+INTO\s+[`"]?([A-Za-z0-9_$]+)[`"]?/i',
			'/^\s*ALTER\s+TABLE\s+[`"]?([A-Za-z0-9_$]+)[`"]?/i',
			'/^\s*UPDATE\s+[`"]?([A-Za-z0-9_$]+)[`"]?/i',
			'/^\s*DELETE\s+FROM\s+[`"]?([A-Za-z0-9_$]+)[`"]?/i',
			'/^\s*TRUNCATE\s+(?:TABLE\s+)?[`"]?([A-Za-z0-9_$]+)[`"]?/i',
		);
		foreach ( $patterns as $pattern ) { if ( preg_match( $pattern, $sql, $m ) ) { return substr( $m[1], 0, 64 ); } }
		return '';
	}

	/** @return array<string,string> */
	private function statement_object( $sql ) {
		if ( preg_match( '/^\s*CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM\s*=\s*\w+\s+)?(?:DEFINER\s*=\s*[^\s]+\s+)?(?:SQL\s+SECURITY\s+\w+\s+)?(VIEW|TRIGGER|PROCEDURE|FUNCTION|EVENT)\s+[`"]?([A-Za-z0-9_$-]+)[`"]?/i', $sql, $m ) ) {
			return array( 'type' => strtolower( (string) $m[1] ), 'name' => substr( (string) $m[2], 0, 64 ) );
		}
		return array();
	}

	/**
	 * Enforce a conservative restore SQL policy before execution.
	 *
	 * @param string                   $sql             SQL statement.
	 * @param string                   $policy          tables|objects.
	 * @param array<int,mixed>         $allowed_targets Expected tables/objects.
	 * @param string                   $target_prefix   Shadow/target table prefix.
	 * @return void
	 */
	private function assert_statement_allowed( $sql, $policy, array $allowed_targets, $target_prefix ) {
		if ( false !== strpos( (string) $sql, "\0" ) ) {
			throw new \RuntimeException( esc_html__( 'The backup contains an invalid SQL byte sequence.', 'nobash-backup' ) );
		}
		// MySQL and MariaDB execute versioned comments (/*! ... */ and
		// /*M! ... */). Treating them as ordinary comments would bypass the
		// statement allowlist, so native .nbh restore rejects them entirely.
		if ( preg_match( '#/\*(?:!|M!)#i', (string) $sql ) ) {
			throw new \RuntimeException( esc_html__( 'Executable SQL comments are not permitted during restore.', 'nobash-backup' ) );
		}
		$probe = $this->strip_leading_comments( $sql );
		if ( '' === $probe ) {
			return;
		}

		// Server/account/database administration and filesystem-capable SQL are
		// never part of an NBH site restore.
		if ( preg_match( '/^(?:GRANT|REVOKE|CREATE\s+USER|ALTER\s+USER|DROP\s+USER|CREATE\s+DATABASE|DROP\s+DATABASE|USE\s+|LOAD\s+DATA|INSTALL\s+PLUGIN|UNINSTALL\s+PLUGIN|SHUTDOWN\b|SET\s+(?:GLOBAL|PERSIST)|RESET\s+MASTER|PURGE\s+BINARY\s+LOGS)/i', $probe )
			|| preg_match( '/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i', $probe )
			|| preg_match( '/\b(?:LOAD_FILE|SLEEP|BENCHMARK)\s*\(/i', $probe )
			|| preg_match( '/@@\s*GLOBAL\b/i', $probe ) ) {
			throw new \RuntimeException( esc_html__( 'The backup contains a prohibited administrative SQL statement.', 'nobash-backup' ) );
		}

		if ( preg_match( '/^SET\s+/i', $probe ) ) {
			if ( ! preg_match( '/^SET\s+(?:NAMES\s+[A-Za-z0-9_]+|FOREIGN_KEY_CHECKS\s*=\s*[01]|UNIQUE_CHECKS\s*=\s*[01]|SQL_MODE\s*=\s*(?:\'[^\']*\'|"[^"]*")|TIME_ZONE\s*=\s*(?:\'[^\']*\'|"[^"]*")|CHARACTER_SET_CLIENT\s*=\s*[A-Za-z0-9_]+)\s*;?$/is', $probe ) ) {
				throw new \RuntimeException( esc_html__( 'The backup contains an unsupported session SQL statement.', 'nobash-backup' ) );
			}
			return;
		}

		if ( preg_match( '/^PRAGMA\s+/i', $probe ) ) {
			if ( ! preg_match( '/^PRAGMA\s+foreign_keys\s*=\s*(?:ON|OFF|0|1)\s*;?$/i', $probe ) ) {
				throw new \RuntimeException( esc_html__( 'The backup contains an unsupported SQLite PRAGMA statement.', 'nobash-backup' ) );
			}
			return;
		}

		if ( 'objects' === $policy ) {
			$this->assert_object_statement_allowed( $probe, $allowed_targets );
			return;
		}
		if ( 'tables' !== $policy ) {
			throw new \RuntimeException( esc_html__( 'The SQL import policy is invalid.', 'nobash-backup' ) );
		}

		if ( ! preg_match( '/^(?:CREATE\s+TABLE|DROP\s+TABLE|INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO)/i', $probe ) ) {
			throw new \RuntimeException( esc_html__( 'The backup contains an unsupported table SQL statement.', 'nobash-backup' ) );
		}
		if ( preg_match( '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?[A-Za-z0-9_$]+[`"]?\s+(?:AS\s+SELECT\b|LIKE\s+[`"]?[A-Za-z0-9_$]+[`"]?)/is', $probe ) ) {
			throw new \RuntimeException( esc_html__( 'CREATE TABLE AS/LIKE is not permitted during restore.', 'nobash-backup' ) );
		}
		if ( preg_match( '/^(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO)/i', $probe )
			&& ! preg_match( '/^(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO)\s+[`"]?[A-Za-z0-9_$]+[`"]?\s*(?:\((?:[^`"()]|`[^`]*`|"[^"]*"|\([^()]*\))*\))?\s+VALUES\s*\(/is', $probe ) ) {
			throw new \RuntimeException( esc_html__( 'Only literal VALUES inserts are permitted during restore.', 'nobash-backup' ) );
		}
		if ( preg_match( '/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $probe ) ) {
			throw new \RuntimeException( esc_html__( 'ON DUPLICATE KEY UPDATE is not permitted during restore.', 'nobash-backup' ) );
		}
		if ( preg_match( '/^(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO)/i', $probe ) ) {
			$this->assert_literal_values_only( $probe );
		}

		$table = $this->statement_table( $probe );
		if ( '' === $table ) {
			throw new \RuntimeException( esc_html__( 'The restore SQL table could not be identified safely.', 'nobash-backup' ) );
		}
		$allowed_tables = array();
		foreach ( $allowed_targets as $allowed ) {
			if ( is_scalar( $allowed ) ) {
				$name = preg_replace( '/[^A-Za-z0-9_$]/', '', (string) $allowed );
				if ( '' !== $name ) { $allowed_tables[] = $name; }
			}
		}
		if ( empty( $allowed_tables ) ) {
			throw new \RuntimeException( esc_html__( 'The restore manifest does not authorize any database tables.', 'nobash-backup' ) );
		}
		if ( ! in_array( $table, $allowed_tables, true ) ) {
			throw new \RuntimeException( esc_html__( 'The backup attempted to modify a table outside the restore manifest.', 'nobash-backup' ) );
		}
		$target_prefix = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $target_prefix );
		if ( '' === $target_prefix ) {
			throw new \RuntimeException( esc_html__( 'The isolated restore table prefix is missing.', 'nobash-backup' ) );
		}
		if ( 0 !== strpos( $table, $target_prefix ) ) {
			throw new \RuntimeException( esc_html__( 'The backup attempted to modify a table outside the isolated restore prefix.', 'nobash-backup' ) );
		}
	}

	/**
	 * Accept only the literal VALUES grammar emitted by Nobash Backup's own dumper.
	 *
	 * This deliberately rejects SQL expressions/functions/subqueries inside imported
	 * data rows. A native dump consists solely of NULL, quoted string literals and
	 * hexadecimal binary literals, separated by commas/parentheses.
	 *
	 * @param string $sql INSERT/REPLACE statement already accepted by the table policy.
	 * @return void
	 */
	private function assert_literal_values_only( $sql ) {
		if ( ! preg_match( '/\bVALUES\b/i', (string) $sql, $match, PREG_OFFSET_CAPTURE ) ) {
			throw new \RuntimeException( esc_html__( 'The backup data statement is missing a VALUES payload.', 'nobash-backup' ) );
		}
		$keyword = (string) $match[0][0];
		$offset  = (int) $match[0][1] + strlen( $keyword );
		$body    = substr( (string) $sql, $offset );
		$length  = strlen( $body );
		$i       = 0;
		$depth   = 0;
		$values  = 0;

		while ( $i < $length ) {
			$char = $body[ $i ];
			if ( ctype_space( $char ) || ',' === $char ) { ++$i; continue; }
			if ( ';' === $char ) {
				++$i;
				while ( $i < $length && ctype_space( $body[ $i ] ) ) { ++$i; }
				if ( $i !== $length ) { $this->reject_nonliteral_values(); }
				break;
			}
			if ( '(' === $char ) { ++$depth; ++$i; continue; }
			if ( ')' === $char ) {
				if ( $depth < 1 ) { $this->reject_nonliteral_values(); }
				--$depth; ++$i; continue;
			}
			if ( 0 === strncasecmp( substr( $body, $i, 4 ), 'NULL', 4 ) ) {
				$next = $i + 4 < $length ? $body[ $i + 4 ] : '';
				if ( '' !== $next && ( ctype_alnum( $next ) || '_' === $next || '$' === $next ) ) { $this->reject_nonliteral_values(); }
				$i += 4; ++$values; continue;
			}
			if ( ( 'X' === $char || 'x' === $char ) && $i + 1 < $length && "'" === $body[ $i + 1 ] ) {
				$end = strpos( $body, "'", $i + 2 );
				if ( false === $end ) { $this->reject_nonliteral_values(); }
				$hex = substr( $body, $i + 2, $end - ( $i + 2 ) );
				if ( 0 !== strlen( $hex ) % 2 || ( '' !== $hex && ! ctype_xdigit( $hex ) ) ) { $this->reject_nonliteral_values(); }
				$i = $end + 1; ++$values; continue;
			}
			if ( "'" === $char ) {
				++$i;
				$closed = false;
				while ( $i < $length ) {
					if ( '\\' === $body[ $i ] ) {
						$i += 2;
						continue;
					}
					if ( "'" === $body[ $i ] ) {
						if ( $i + 1 < $length && "'" === $body[ $i + 1 ] ) { $i += 2; continue; }
						++$i; $closed = true; break;
					}
					++$i;
				}
				if ( ! $closed ) { $this->reject_nonliteral_values(); }
				++$values; continue;
			}
			$this->reject_nonliteral_values();
		}
		if ( 0 !== $depth || 0 === $values ) { $this->reject_nonliteral_values(); }
	}

	/** @return void */
	private function reject_nonliteral_values() {
		throw new \RuntimeException( esc_html__( 'The backup contains a non-literal SQL value that is not permitted during restore.', 'nobash-backup' ) );
	}

	/** @return void */
	private function assert_object_statement_allowed( $sql, array $allowed_targets ) {
		if ( preg_match( '/\bDEFINER\s*=/i', $sql ) ) {
			throw new \RuntimeException( esc_html__( 'Database object DEFINER clauses are not permitted during restore.', 'nobash-backup' ) );
		}
		if ( ! preg_match( '/^(?:CREATE\s+(?:OR\s+REPLACE\s+)?(?:ALGORITHM\s*=\s*\w+\s+)?(?:SQL\s+SECURITY\s+\w+\s+)?|DROP\s+)(VIEW|TRIGGER|PROCEDURE|FUNCTION|EVENT)\s+(?:IF\s+EXISTS\s+)?[`"]?([A-Za-z0-9_$-]+)[`"]?/i', $sql, $match ) ) {
			throw new \RuntimeException( esc_html__( 'The backup contains an unsupported database object statement.', 'nobash-backup' ) );
		}
		$type = strtolower( (string) $match[1] );
		$name = (string) $match[2];
		$allowed = array();
		foreach ( $allowed_targets as $object ) {
			if ( ! is_array( $object ) ) { continue; }
			$object_type = strtolower( preg_replace( '/[^a-z]/', '', (string) ( $object['type'] ?? '' ) ) );
			$object_name = preg_replace( '/[^A-Za-z0-9_$-]/', '', (string) ( $object['name'] ?? '' ) );
			if ( '' !== $object_type && '' !== $object_name ) { $allowed[] = $object_type . ':' . $object_name; }
		}
		if ( empty( $allowed ) ) {
			throw new \RuntimeException( esc_html__( 'The restore manifest does not authorize any database objects.', 'nobash-backup' ) );
		}
		if ( ! in_array( $type . ':' . $name, $allowed, true ) ) {
			throw new \RuntimeException( esc_html__( 'The backup attempted to create a database object outside the restore manifest.', 'nobash-backup' ) );
		}
	}

	/** Remove leading whitespace/comments for policy matching without changing execution SQL. @return string */
	private function strip_leading_comments( $sql ) {
		$sql = ltrim( (string) $sql );
		do {
			$before = $sql;
			$sql = preg_replace( '/\A(?:--[^\r\n]*(?:\r?\n|$)|#[^\r\n]*(?:\r?\n|$)|\/\*[\s\S]*?\*\/\s*)/s', '', $sql );
			$sql = ltrim( (string) $sql );
		} while ( $before !== $sql );
		return $sql;
	}

	/** Whether a statement is safe to group into the current bulk-data transaction. @return bool */
	private function is_bulk_dml( $sql ) {
		return (bool) preg_match( '/^\s*(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO)\b/i', (string) $sql );
	}

	/** Start a bounded import transaction and fail closed when the database refuses it. @return void */
	private function begin_slice_transaction() {
		global $wpdb;
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query( 'START TRANSACTION' );
		if ( false === $result && '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( sprintf( /* translators: %s database error */ esc_html__( 'Could not start the resumable import transaction: %s', 'nobash-backup' ), esc_html( $wpdb->last_error ) ) );
		}
	}

	/** Commit the current data slice before its byte cursor is returned to the job engine. @return void */
	private function commit_slice_transaction() {
		global $wpdb;
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query( 'COMMIT' );
		if ( false === $result && '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( sprintf( /* translators: %s database error */ esc_html__( 'Could not commit the resumable import transaction: %s', 'nobash-backup' ), esc_html( $wpdb->last_error ) ) );
		}
	}

	/** Best-effort rollback; the original import error remains authoritative. @return void */
	private function rollback_slice_transaction() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'ROLLBACK' );
	}

	/** @return int */
	private function query_chunk_bytes() {
		$limit = trim( (string) ini_get( 'memory_limit' ) );
		$bytes = 0;
		if ( '' !== $limit && '-1' !== $limit ) {
			$unit = strtolower( substr( $limit, -1 ) );
			$bytes = (int) $limit;
			if ( 'g' === $unit ) { $bytes *= 1073741824; }
			elseif ( 'm' === $unit ) { $bytes *= 1048576; }
			elseif ( 'k' === $unit ) { $bytes *= 1024; }
		}
		$target = 4194304;
		if ( $bytes > 0 ) {
			$headroom = max( 0, $bytes - (int) memory_get_usage( true ) );
			if ( $headroom < 33554432 ) { $target = 524288; }
			elseif ( $headroom < 67108864 ) { $target = 1048576; }
			elseif ( $headroom >= 134217728 && $bytes > 268435456 ) { $target = 8388608; }
			elseif ( $bytes <= 67108864 ) { $target = 1048576; }
			elseif ( $bytes <= 134217728 ) { $target = 2097152; }
		}

		$target = min( $target, \Nobash\Backup\Compat\HostingEnvironment::max_db_statement_bytes() );
		$packet = $this->max_allowed_packet();
		if ( $packet > 0 ) {
			// Leave protocol/query metadata headroom and never intentionally emit a
			// statement as large as the server packet ceiling.
			$safe_packet = max( 262144, (int) floor( $packet * 0.70 ) );
			$target = min( $target, $safe_packet );
		}
		return max( 262144, (int) $target );
	}

	/** Best-effort MySQL/MariaDB packet ceiling; zero means unavailable/not applicable. @return int */
	private function max_allowed_packet() {
		static $cached = null;
		if ( null !== $cached ) { return (int) $cached; }
		$cached = 0;
		if ( 'sqlite' === \Nobash\Backup\Compat\Environment::db_engine() ) { return 0; }
		global $wpdb;
		$previous_error = (string) $wpdb->last_error;
		$wpdb->last_error = '';
		// SHOW SESSION VARIABLES is available on ordinary shared-host database
		// accounts; failure is non-fatal and simply keeps the conservative default.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var( "SHOW SESSION VARIABLES LIKE 'max_allowed_packet'", 1 );
		if ( is_numeric( $value ) ) { $cached = max( 0, (int) $value ); }
		$wpdb->last_error = $previous_error;
		return (int) $cached;
	}

}

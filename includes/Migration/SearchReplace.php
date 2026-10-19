<?php
/**
 * Serialized-safe search/replace (migration).
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Migration;

use Nobash\Backup\Compat\DatabaseAdapter;

defined( 'ABSPATH' ) || exit;

/**
 * Class SearchReplace
 */
class SearchReplace {

	/**
	 * Replace in a value recursively with serialized-data awareness.
	 *
	 * @param mixed  $data    Data.
	 * @param string $search  Search.
	 * @param string $replace Replace.
	 * @return mixed
	 */
	public function replace( $data, $search, $replace ) {
		if ( '' === $search || $search === $replace ) {
			return $data;
		}

		if ( is_string( $data ) ) {
			return $this->replace_string( $data, $search, $replace );
		}

		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->replace( $value, $search, $replace );
			}
			return $data;
		}

		return $data;
	}

	/**
	 * Replace inside a string; handles PHP serialized payloads.
	 *
	 * @param string $data    String.
	 * @param string $search  Search.
	 * @param string $replace Replace.
	 * @return string
	 */
	public function replace_string( $data, $search, $replace ) {
		if ( false === strpos( $data, (string) $search ) ) {
			return $data;
		}

		/*
		 * Serialized objects are common in page-builder/plugin options. Unserializing
		 * them and reserializing incomplete objects can corrupt private/custom state,
		 * while a raw str_replace() leaves PHP string-length descriptors stale. Rewrite
		 * serialized string tokens in-place instead: this preserves the object graph and
		 * reference layout without instantiating application classes.
		 */
		if ( is_serialized( $data ) ) {
			$rewritten = $this->replace_serialized_string_tokens( $data, (string) $search, (string) $replace );
			if ( $rewritten !== $data && is_serialized( $rewritten ) ) {
				return $rewritten;
			}

			$un = @unserialize( $data, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			if ( false !== $un || 'b:0;' === $data ) {
				if ( $this->contains_object( $un ) ) {
					return $data;
				}
				$un = $this->replace( $un, $search, $replace );
				return serialize( $un ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			}

			// It was recognized as serialized data but PHP could not safely decode it
			// (for example an unavailable custom serializer). Never fall through to
			// a raw byte replacement that could invalidate embedded length fields.
			return $data;
		}

		/* Preserve JSON syntax/escaping rather than replacing bytes inside encoded
		 * strings. Only object/array roots are treated as JSON so ordinary scalar
		 * option values keep their original representation. */
		$trimmed = ltrim( $data );
		if ( '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) ) {
			$decoded = json_decode( $data );
			if ( JSON_ERROR_NONE === json_last_error() && ( is_array( $decoded ) || $decoded instanceof \stdClass ) ) {
				$decoded = $this->replace_json_value( $decoded, (string) $search, (string) $replace );
				$encoded = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( is_string( $encoded ) ) {
					return $encoded;
				}
			}
		}

		return str_replace( $search, $replace, $data );
	}

	/**
	 * Rewrite PHP serialized string tokens without instantiating serialized objects.
	 *
	 * Custom-serialized (C:) payloads are opaque and therefore copied verbatim; their
	 * internal byte-count belongs to application code and must not be modified here.
	 * strlen() intentionally counts bytes, matching PHP's serialization format even
	 * for multibyte UTF-8 values.
	 *
	 * @return string
	 */
	private function replace_serialized_string_tokens( $data, $search, $replace ) {
		$length  = strlen( $data );
		$out     = '';
		$offset  = 0;
		$changed = false;

		while ( $offset < $length ) {
			if ( 'C' === $data[ $offset ] && $offset + 1 < $length && ':' === $data[ $offset + 1 ] ) {
				$custom_end = $this->serialized_custom_token_end( $data, $offset );
				if ( $custom_end > $offset ) {
					$out   .= substr( $data, $offset, $custom_end - $offset );
					$offset = $custom_end;
					continue;
				}
			}

			if ( 's' === $data[ $offset ] && $offset + 2 < $length && ':' === $data[ $offset + 1 ] ) {
				$cursor = $offset + 2;
				$digits = $cursor;
				while ( $cursor < $length && $data[ $cursor ] >= '0' && $data[ $cursor ] <= '9' ) {
					++$cursor;
				}
				if ( $cursor > $digits && $cursor + 1 < $length && ':' === $data[ $cursor ] && '"' === $data[ $cursor + 1 ] ) {
					$declared     = (int) substr( $data, $digits, $cursor - $digits );
					$content_start = $cursor + 2;
					$content_end   = $content_start + $declared;
					if ( $content_end + 1 < $length && '";' === substr( $data, $content_end, 2 ) ) {
						$value = substr( $data, $content_start, $declared );
						$new   = str_replace( $search, $replace, $value );
						$out  .= 's:' . strlen( $new ) . ':"' . $new . '";';
						$changed = $changed || $new !== $value;
						$offset  = $content_end + 2;
						continue;
					}
				}
			}

			$out .= $data[ $offset ];
			++$offset;
		}

		return $changed ? $out : $data;
	}

	/** Return the byte offset immediately after a valid C: custom-serialized token. */
	private function serialized_custom_token_end( $data, $offset ) {
		$length = strlen( $data );
		$cursor = $offset + 2;
		$digits = $cursor;
		while ( $cursor < $length && $data[ $cursor ] >= '0' && $data[ $cursor ] <= '9' ) { ++$cursor; }
		if ( $cursor === $digits || $cursor + 1 >= $length || ':' !== $data[ $cursor ] || '"' !== $data[ $cursor + 1 ] ) { return 0; }
		$class_length = (int) substr( $data, $digits, $cursor - $digits );
		$class_end    = $cursor + 2 + $class_length;
		if ( $class_end + 1 >= $length || '":' !== substr( $data, $class_end, 2 ) ) { return 0; }
		$cursor = $class_end + 2;
		$digits = $cursor;
		while ( $cursor < $length && $data[ $cursor ] >= '0' && $data[ $cursor ] <= '9' ) { ++$cursor; }
		if ( $cursor === $digits || $cursor + 1 >= $length || ':{' !== substr( $data, $cursor, 2 ) ) { return 0; }
		$payload_length = (int) substr( $data, $digits, $cursor - $digits );
		$end            = $cursor + 2 + $payload_length;
		return ( $end < $length && '}' === $data[ $end ] ) ? $end + 1 : 0;
	}

	/** Recursively replace JSON string values while preserving object/array shape. */
	private function replace_json_value( $value, $search, $replace ) {
		if ( is_string( $value ) ) { return str_replace( $search, $replace, $value ); }
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) { $value[ $key ] = $this->replace_json_value( $item, $search, $replace ); }
			return $value;
		}
		if ( $value instanceof \stdClass ) {
			foreach ( get_object_vars( $value ) as $key => $item ) { $value->{$key} = $this->replace_json_value( $item, $search, $replace ); }
		}
		return $value;
	}

	/**
	 * Determine whether a decoded serialized value contains an object.
	 *
	 * @param mixed $value Decoded value.
	 * @return bool
	 */
	private function contains_object( $value ) {
		if ( is_object( $value ) ) {
			return true;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $this->contains_object( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/** Cursor-based search/replace for one bounded table slice.
	 * @return array{cursor:array<string,mixed>,rows:int,changed:int,done:bool}
	 */
	public function process_table_cursor( $search, $replace, $table, array $cursor = array(), $limit = 100 ) {
		global $wpdb;
		$db = new DatabaseAdapter();
		$planner = new \Nobash\Backup\Database\TableCursor( $db );
		$columns = $db->get_columns( $table );
		if ( empty( $columns ) ) {
			return array( 'cursor' => $cursor, 'rows' => 0, 'changed' => 0, 'done' => true );
		}
		$text_types = array( 'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'clob', 'string' );
		$targets = array();
		foreach ( $columns as $col ) {
			$type = strtolower( (string) ( $col['Type'] ?? '' ) );
			foreach ( $text_types as $tt ) {
				if ( '' !== $type && false !== strpos( $type, $tt ) ) {
					$targets[] = (string) $col['Field'];
					break;
				}
			}
			if ( '' === $type && ! in_array( (string) $col['Field'], $targets, true ) ) {
				$targets[] = (string) $col['Field'];
			}
		}
		if ( empty( $targets ) ) {
			return array( 'cursor' => $cursor, 'rows' => 0, 'changed' => 0, 'done' => true );
		}
		if ( empty( $cursor ) ) {
			$cursor = $planner->plan( $table );
		}
		/* A keyless table was never writable by this routine because there is no safe
		 * row identity for an UPDATE. Do not spend minutes paging every row just to
		 * discard every potential change. */
		if ( 'keyset' !== (string) ( $cursor['strategy'] ?? '' ) ) {
			return array( 'cursor' => $cursor, 'rows' => 0, 'changed' => 0, 'done' => true );
		}
		$key_columns = array_values( array_map( 'strval', (array) ( $cursor['columns'] ?? array() ) ) );
		$select_columns = array_values( array_unique( array_merge( $key_columns, $targets ) ) );
		$select_placeholders = implode( ',', array_fill( 0, count( $select_columns ), '%i' ) );
		$conditions = array();
		$value_args = array();
		$predicate = $planner->predicate( $key_columns, (array) ( $cursor['values'] ?? array() ) );
		if ( '' !== $predicate['sql'] ) {
			$conditions[] = $predicate['sql'];
			$value_args = $predicate['args'];
		}
		/* Let the database discard rows that cannot possibly change. This keeps large
		 * post/meta tables on the server instead of transferring every row through PHP. */
		$like = '%' . $wpdb->esc_like( (string) $search ) . '%';
		$match = array();
		foreach ( $targets as $field ) {
			$match[] = '%i LIKE %s';
		}
		$conditions[] = '(' . implode( ' OR ', $match ) . ')';
		$order_placeholders = implode( ',', array_fill( 0, count( $key_columns ), '%i' ) );
		$sql = 'SELECT ' . $select_placeholders . ' FROM %i WHERE ' . implode( ' AND ', $conditions ) . ' ORDER BY ' . $order_placeholders . ' ASC LIMIT %d';
		$args = array_merge( $select_columns, array( $table ), $value_args );
		foreach ( $targets as $field ) { $args[] = $field; $args[] = $like; }
		$args = array_merge( $args, $key_columns, array( max( 1, (int) $limit ) ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Template has generated placeholder counts; all identifiers and values are prepare arguments.
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array( 'cursor' => $cursor, 'rows' => 0, 'changed' => 0, 'done' => true );
		}
		$operations = array();
		$last = null;
		foreach ( $rows as $row ) {
			$update = array();
			foreach ( $targets as $field ) {
				if ( ! isset( $row[ $field ] ) || ! is_string( $row[ $field ] ) ) {
					continue;
				}
				$new = $this->replace_string( $row[ $field ], $search, $replace );
				if ( $new !== $row[ $field ] ) {
					$update[ $field ] = $new;
				}
			}
			if ( ! empty( $update ) ) {
				$where_update = array();
				foreach ( $key_columns as $key ) {
					$where_update[ $key ] = $row[ $key ];
				}
				$operations[] = array( 'data' => $update, 'where' => $where_update );
			}
			$last = $row;
		}
		$changed = 0;
		if ( ! empty( $operations ) ) {
			/* One transaction per changed window avoids a durable commit for every
			 * option/meta row. Empty windows do not open a transaction at all. */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Literal transaction-control statement wrapping prepared $wpdb->update() calls below; not cacheable.
			$transaction = false !== $wpdb->query( 'START TRANSACTION' );
			try {
				foreach ( $operations as $operation ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->update() escapes values internally; not cacheable.
					$result = $wpdb->update( $table, $operation['data'], $operation['where'] );
					if ( false === $result ) {
						throw new \RuntimeException( esc_html__( 'Database search and replace update failed.', 'nobash-backup' ) );
					}
					$changed += (int) $result;
				}
				if ( $transaction ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Literal transaction-control statement; not cacheable.
					$wpdb->query( 'COMMIT' );
				}
			} catch ( \Throwable $error ) {
				if ( $transaction ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Literal transaction-control statement; not cacheable.
					$wpdb->query( 'ROLLBACK' );
				}
				throw $error;
			}
		}
		if ( is_array( $last ) ) {
			$cursor['values'] = array();
			foreach ( $key_columns as $key ) {
				$cursor['values'][] = $last[ $key ];
			}
		}
		$cursor['rows'] = (int) ( $cursor['rows'] ?? 0 ) + count( $rows );
		return array( 'cursor' => $cursor, 'rows' => count( $rows ), 'changed' => $changed, 'done' => count( $rows ) < max( 1, (int) $limit ) );
	}

}

<?php
/**
 * Resumable delimiter-aware SQL statement reader.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Database;

defined( 'ABSPATH' ) || exit;

/** Reads complete SQL statements and never checkpoints a partial statement. */
final class SqlStatementReader {
	const DEFAULT_DELIMITER = ';';
	const READ_BUFFER_BYTES = 262144;
	const ABSOLUTE_MAX_STATEMENT_BYTES = 536870912;

	/**
	 * Read one complete statement from a committed byte offset.
	 *
	 * Parser state is intentionally small and JSON serializable so a restore can
	 * resume after PHP, the browser, WP-Cron, or the web server restarts.
	 *
	 * @param resource            $handle Open file handle.
	 * @param int                 $offset Committed offset.
	 * @param array<string,mixed> $state Parser state.
	 * @return array{sql:string,offset:int,eof:bool,state:array<string,mixed>,directive:bool}|null
	 */
	public function next( $handle, $offset, array $state = array() ) {
		$offset = max( 0, (int) $offset );
		if ( 0 !== fseek( $handle, $offset ) ) {
			throw new \RuntimeException( esc_html__( 'Could not seek within the SQL dump.', 'nobash-backup' ) );
		}

		$delimiter = isset( $state['delimiter'] ) ? (string) $state['delimiter'] : self::DEFAULT_DELIMITER;
		if ( '' === $delimiter || strlen( $delimiter ) > 32 || preg_match( '/\s/', $delimiter ) ) {
			$delimiter = self::DEFAULT_DELIMITER;
		}

		$directive = $this->read_delimiter_directive( $handle, $offset, $delimiter );
		if ( null !== $directive ) {
			return $directive;
		}
		if ( 0 !== fseek( $handle, $offset ) ) {
			throw new \RuntimeException( esc_html__( 'Could not seek within the SQL dump.', 'nobash-backup' ) );
		}

		$sql           = '';
		$quote         = '';
		$escaped       = false;
		$line_comment  = false;
		$block_comment = false;
		$previous      = '';
		$max_bytes     = $this->max_statement_bytes();
		$delimiter_len = strlen( $delimiter );
		$delimiter_end = substr( $delimiter, -1 );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread -- WP_Filesystem has no fseek()/streaming primitives; resumable statement-by-statement parsing requires reading fixed-size chunks from an externally managed file handle.
		while ( ! feof( $handle ) ) {
			$chunk_start = (int) ftell( $handle );
			$chunk       = fread( $handle, self::READ_BUFFER_BYTES );
			if ( false === $chunk ) {
				throw new \RuntimeException( esc_html__( 'Could not read the SQL dump.', 'nobash-backup' ) );
			}
			if ( '' === $chunk ) {
				break;
			}
			$base_length = strlen( $sql );
			$sql .= $chunk;
			$length = strlen( $chunk );
			for ( $i = 0; $i < $length; ++$i ) {
				$char = $chunk[ $i ];
				$current_length = $base_length + $i + 1;
				if ( $current_length > $max_bytes ) {
					throw new \RuntimeException(
						sprintf(
							/* translators: %s: formatted byte limit. */
							esc_html__( 'A single SQL statement exceeds this server\'s safe resumable import limit (%s). Increase PHP memory or split the source dump.', 'nobash-backup' ),
							esc_html( size_format( $max_bytes ) )
						)
					);
				}

				if ( $line_comment ) {
					if ( "\n" === $char || "\r" === $char ) {
						$line_comment = false;
					}
					$previous = $char;
					continue;
				}
				if ( $block_comment ) {
					if ( '*' === $previous && '/' === $char ) {
						$block_comment = false;
					}
					$previous = $char;
					continue;
				}
				if ( '' !== $quote ) {
					if ( $escaped ) {
						$escaped = false;
						$previous = $char;
						continue;
					}
					if ( '\\' === $char ) {
						$escaped = true;
						$previous = $char;
						continue;
					}
					if ( $char === $quote ) {
						$next = ( $i + 1 < $length ) ? $chunk[ $i + 1 ] : fgetc( $handle );
						if ( $next === $quote ) {
							if ( $i + 1 < $length ) { ++$i; } else { $sql .= $next; }
							$previous = $next;
							continue;
						}
						if ( false !== $next && $i + 1 >= $length ) { fseek( $handle, -1, SEEK_CUR ); }
						$quote = '';
					}
					$previous = $char;
					continue;
				}

				if ( '#' === $char || ( '-' === $previous && '-' === $char ) ) {
					$line_comment = true;
					$previous = $char;
					continue;
				}
				if ( '/' === $previous && '*' === $char ) {
					$block_comment = true;
					$previous = $char;
					continue;
				}
				if ( "'" === $char || '"' === $char || '`' === $char ) {
					$quote = $char;
					$previous = $char;
					continue;
				}

				if ( $char === $delimiter_end && $current_length >= $delimiter_len && $delimiter === substr( $sql, $current_length - $delimiter_len, $delimiter_len ) ) {
					$committed = $chunk_start + $i + 1;
					if ( 0 !== fseek( $handle, $committed ) ) {
						throw new \RuntimeException( esc_html__( 'Could not checkpoint the SQL dump.', 'nobash-backup' ) );
					}
					$statement_bytes = substr( $sql, 0, $current_length );
					$statement = self::DEFAULT_DELIMITER === $delimiter ? trim( $statement_bytes ) : trim( substr( $statement_bytes, 0, -$delimiter_len ) );
					return array(
						'sql'       => $statement,
						'offset'    => $committed,
						'eof'       => false,
						'state'     => array( 'delimiter' => $delimiter ),
						'directive' => false,
					);
				}
				$previous = $char;
			}
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fread

		$trimmed = trim( $sql );
		if ( '' === $trimmed || preg_match( '/^(?:--[^\n]*(?:\n|$)|#[^\n]*(?:\n|$)|\/\*[\s\S]*?\*\/\s*)+$/', $trimmed ) ) {
			return null;
		}
		return array(
			'sql'       => $trimmed,
			'offset'    => (int) ftell( $handle ),
			'eof'       => true,
			'state'     => array( 'delimiter' => $delimiter ),
			'directive' => false,
		);
	}

	/** @return array<string,mixed>|null */
	private function read_delimiter_directive( $handle, $offset, $current ) {
		if ( 0 !== fseek( $handle, (int) $offset ) ) {
			return null;
		}
		$cursor = (int) $offset;
		while ( ! feof( $handle ) ) {
			$line_start = (int) ftell( $handle );
			$line       = fgets( $handle, 4096 );
			if ( false === $line ) {
				return null;
			}
			$trimmed = trim( $line );
			if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '#' ) ) {
				$cursor = (int) ftell( $handle );
				continue;
			}
			if ( preg_match( '/^DELIMITER\s+(\S+)\s*$/i', $trimmed, $match ) ) {
				$new = (string) $match[1];
				if ( strlen( $new ) > 32 || preg_match( '/\s/', $new ) ) {
					throw new \RuntimeException( esc_html__( 'The SQL dump contains an invalid DELIMITER directive.', 'nobash-backup' ) );
				}
				return array(
					'sql'       => '',
					'offset'    => (int) ftell( $handle ),
					'eof'       => false,
					'state'     => array( 'delimiter' => $new ),
					'directive' => true,
				);
			}
			// The first meaningful line is SQL. Rewind to the original checkpoint so
			// comments/whitespace remain part of the statement and offsets stay exact.
			fseek( $handle, (int) $offset );
			return null;
		}
		fseek( $handle, $cursor );
		return null;
	}

	/** @return int */
	private function max_statement_bytes() {
		$limit = $this->memory_limit_bytes();
		if ( $limit <= 0 ) {
			return 134217728;
		}
		$available = max( 0, $limit - (int) memory_get_usage( true ) );
		// Keep room for WordPress, prefix rewriting, query execution and error data.
		$calculated = (int) floor( $available * 0.35 );
		return max( 16777216, min( self::ABSOLUTE_MAX_STATEMENT_BYTES, $calculated ) );
	}

	/** @return int */
	private function memory_limit_bytes() {
		$value = trim( (string) ini_get( 'memory_limit' ) );
		if ( '' === $value || '-1' === $value ) {
			return 0;
		}
		$unit   = strtolower( substr( $value, -1 ) );
		$number = (float) $value;
		if ( 'g' === $unit ) {
			return (int) ( $number * 1073741824 );
		}
		if ( 'm' === $unit ) {
			return (int) ( $number * 1048576 );
		}
		if ( 'k' === $unit ) {
			return (int) ( $number * 1024 );
		}
		return (int) $number;
	}
}

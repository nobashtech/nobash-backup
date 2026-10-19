<?php
/**
 * Disk-backed file inventory for large-site jobs.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Filesystem;

defined( 'ABSPATH' ) || exit;

/**
 * Persist discovered directories and files without growing the job cursor.
 */
class FileInventoryRepository {

	/** @return string */
	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'nobash_file_inventory';
	}

	/**
	 * Insert an inventory entry if it is not already present.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $path Absolute path.
	 * @param string $relative Archive-relative path.
	 * @param string $type file|directory.
	 * @param int    $size Size.
	 * @param int    $mtime Modified time.
	 * @return int Entry ID, or zero on failure.
	 */
	public function add( $job_id, $path, $relative, $type, $size = 0, $mtime = 0 ) {
		global $wpdb;
		$normalized = wp_normalize_path( $path );
		$type       = 'directory' === $type ? 'directory' : 'file';
		$state      = 'directory' === $type ? 'pending' : 'ready';
		$hash       = hash( 'sha256', $normalized );
		$table      = $this->table();
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (job_id,path_hash,entry_type,state,absolute_path,relative_path,size_bytes,modified_at,created_at,updated_at) VALUES (%d,%s,%s,%s,%s,%s,%d,%d,%s,%s)',
				$table,
				absint( $job_id ),
				$hash,
				$type,
				$state,
				$normalized,
				ltrim( str_replace( '\\', '/', (string) $relative ), '/' ),
				max( 0, (int) $size ),
				max( 0, (int) $mtime ),
				current_time( 'mysql', true ),
				current_time( 'mysql', true )
			)
		);
		if ( $wpdb->insert_id ) {
			return (int) $wpdb->insert_id;
		}
		$result = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE job_id=%d AND path_hash=%s', $table, absint( $job_id ), $hash ) );
		return $result;
	}


	/**
	 * Insert a bounded batch of inventory entries.
	 *
	 * @param int $job_id Job ID.
	 * @param array<int,array<string,mixed>> $entries Entries.
	 * @return void
	 */
	public function add_many( $job_id, array $entries ) {
		global $wpdb;
		if ( empty( $entries ) ) {
			return;
		}
		$table = $this->table();
		foreach ( array_chunk( $entries, 500 ) as $chunk ) {
			$placeholders = array();
			$values       = array();
			$now          = current_time( 'mysql', true );
			foreach ( $chunk as $entry ) {
				$path       = wp_normalize_path( (string) ( $entry['path'] ?? '' ) );
				$relative   = ltrim( str_replace( '\\', '/', (string) ( $entry['relative'] ?? '' ) ), '/' );
				$type       = 'directory' === (string) ( $entry['type'] ?? '' ) ? 'directory' : 'file';
				$state      = 'directory' === $type ? 'pending' : 'ready';
				$size       = max( 0, (int) ( $entry['size'] ?? 0 ) );
				$mtime      = max( 0, (int) ( $entry['mtime'] ?? 0 ) );
				if ( '' === $path ) {
					continue;
				}
				$placeholders[] = '(%d,%s,%s,%s,%s,%s,%d,%d,%s,%s)';
				array_push( $values, absint( $job_id ), hash( 'sha256', $path ), $type, $state, $path, $relative, $size, $mtime, $now, $now );
			}
			if ( empty( $placeholders ) ) {
				continue;
			}
			$sql = 'INSERT IGNORE INTO %i (job_id,path_hash,entry_type,state,absolute_path,relative_path,size_bytes,modified_at,created_at,updated_at) VALUES ' . implode( ',', $placeholders );
			array_unshift( $values, $table );
			$result = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Locally generated fragments contain only placeholders and fixed SQL; all identifiers and values are passed to prepare().
			if ( false === $result ) {
				throw new \RuntimeException( esc_html__( 'Could not persist the resumable file inventory.', 'nobash-backup' ) );
			}
		}
	}

	/** @param int $job_id Job ID. @return array<string,mixed>|null */
	public function next_directory( $job_id ) {
		global $wpdb;
		$table = $this->table();
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM %i WHERE job_id=%d AND entry_type='directory' AND state='pending' ORDER BY id ASC LIMIT 1", $table, absint( $job_id ) ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Refresh the discovered metadata for a file that changed before packing.
	 *
	 * The inventory is a work queue, not an immutable snapshot. Keeping the size
	 * current prevents progress/byte totals from drifting when WordPress writes a
	 * file between discovery and the first archive chunk.
	 *
	 * @param int $id Entry ID.
	 * @param int $size Current size.
	 * @param int $mtime Current modification time.
	 * @return bool
	 */
	public function refresh_file_metadata( $id, $size, $mtime ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table via $wpdb->update().
		$result = $wpdb->update(
			$this->table(),
			array(
				'size_bytes' => max( 0, (int) $size ),
				'modified_at' => max( 0, (int) $mtime ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => absint( $id ), 'entry_type' => 'file' ),
			array( '%d', '%d', '%s' ),
			array( '%d', '%s' )
		);
		return false !== $result;
	}

	/** @param int $id Entry ID. @param string $state State. @param string $error Safe error. @return bool */
	public function set_state( $id, $state, $error = '' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table via $wpdb->update().
		$result = $wpdb->update(
			$this->table(),
			array( 'state' => sanitize_key( $state ), 'error_message' => sanitize_text_field( $error ), 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	/**
	 * Return a bounded page of ready files after an inventory ID.
	 *
	 * @param int $job_id Job ID.
	 * @param int $after_id Last processed ID.
	 * @param int $limit Limit.
	 * @return array<int,array<string,mixed>>
	 */
	public function ready_files( $job_id, $after_id, $limit ) {
		global $wpdb;
		$table = $this->table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE job_id=%d AND entry_type='file' AND state='ready' AND id>%d ORDER BY id ASC LIMIT %d",
				$table,
				absint( $job_id ),
				absint( $after_id ),
				max( 1, min( 5000, absint( $limit ) ) )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}


	/**
	 * Reset file states that were written after the last durable job cursor.
	 * This makes inventory updates safe if PHP exits after a file was marked but
	 * before the corresponding archive cursor was persisted.
	 *
	 * @param int $job_id Job ID.
	 * @param int $after_id Last durably completed inventory ID.
	 * @return void
	 */
	public function rewind_file_states_after( $job_id, $after_id ) {
		global $wpdb;
		$table = $this->table();
		$wpdb->query(
			$wpdb->prepare( "UPDATE %i SET state='ready',checksum_sha256='',error_message='',updated_at=%s WHERE job_id=%d AND entry_type='file' AND id>%d AND state IN ('packed','skipped','unchanged')", $table, current_time( 'mysql', true ), absint( $job_id ), absint( $after_id ) )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/** @param array<int,string> $checksums Map of inventory ID to checksum. @return void */
	public function mark_packed_many( array $checksums ) {
		global $wpdb;
		if ( empty( $checksums ) ) { return; }
		$table = $this->table();
		foreach ( array_chunk( $checksums, 250, true ) as $chunk ) {
			$cases = array(); $ids = array(); $args = array();
			foreach ( $chunk as $id => $checksum ) {
				$id = absint( $id );
				if ( $id < 1 || ! preg_match( '/^[a-f0-9]{64}$/i', (string) $checksum ) ) { continue; }
				$cases[] = 'WHEN %d THEN %s'; $args[] = $id; $args[] = strtolower( (string) $checksum ); $ids[] = $id;
			}
			if ( empty( $ids ) ) { continue; }
			$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$sql = "UPDATE %i SET state='packed',checksum_sha256=CASE id " . implode( ' ', $cases ) . " ELSE checksum_sha256 END,updated_at=%s WHERE id IN ({$id_placeholders})";
			$args[] = current_time( 'mysql', true );
			$args = array_merge( array( $table ), $args, $ids );
			$result = $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Locally generated fragments contain only placeholders and fixed SQL; all identifiers and values are passed to prepare().
			if ( false === $result ) {
				throw new \RuntimeException( esc_html__( 'Could not checkpoint packed file inventory state.', 'nobash-backup' ) );
			}
		}
	}

	/** @param int $id Entry ID. @param string $checksum SHA256. @return void */
	public function mark_packed( $id, $checksum ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table via $wpdb->update().
		$wpdb->update(
			$this->table(),
			array( 'state' => 'packed', 'checksum_sha256' => $checksum, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/** @param int $job_id Job ID. @return array<string,int> */
	public function stats( $job_id ) {
		global $wpdb;
		$table = $this->table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(CASE WHEN entry_type='file' THEN 1 END) AS files, COALESCE(SUM(CASE WHEN entry_type='file' THEN size_bytes ELSE 0 END),0) AS bytes, COUNT(CASE WHEN entry_type='file' AND state='packed' THEN 1 END) AS packed, COALESCE(SUM(CASE WHEN entry_type='file' AND state='packed' THEN size_bytes ELSE 0 END),0) AS packed_bytes, COUNT(CASE WHEN entry_type='directory' AND state='pending' THEN 1 END) AS pending_dirs, COUNT(CASE WHEN entry_type='file' AND state='skipped' THEN 1 END) AS skipped, COUNT(CASE WHEN entry_type='file' AND state='unchanged' THEN 1 END) AS unchanged FROM %i WHERE job_id=%d",
				$table,
				absint( $job_id )
			),
			ARRAY_A
		);
		return array(
			'files'        => isset( $row['files'] ) ? (int) $row['files'] : 0,
			'bytes'        => isset( $row['bytes'] ) ? (int) $row['bytes'] : 0,
			'packed'       => isset( $row['packed'] ) ? (int) $row['packed'] : 0,
			'packed_bytes' => isset( $row['packed_bytes'] ) ? (int) $row['packed_bytes'] : 0,
			'pending_dirs' => isset( $row['pending_dirs'] ) ? (int) $row['pending_dirs'] : 0,
			'skipped'      => isset( $row['skipped'] ) ? (int) $row['skipped'] : 0,
			'unchanged'    => isset( $row['unchanged'] ) ? (int) $row['unchanged'] : 0,
		);
	}

	/**
	 * Return the baseline file row for a WordPress-relative path.
	 *
	 * Backup inventory paths are sourced from the production WordPress installation root,
	 * so the existing job/path hash index can be reused without adding another
	 * large feature-specific table.
	 *
	 * @param int    $job_id   Backup inventory job ID.
	 * @param string $relative WordPress-relative file path.
	 * @return array<string,mixed>|null
	 */

	/**
	 * Fetch baseline rows for a bounded batch of WordPress-relative paths.
	 *
	 * @param int               $job_id    Backup inventory job ID.
	 * @param array<int,string> $relatives Relative paths.
	 * @return array<string,array<string,mixed>> Rows keyed by relative path.
	 */

	/**
	 * Page through successfully copied baseline files.
	 *
	 * @param int $job_id   Backup inventory job ID.
	 * @param int $after_id Last returned inventory ID.
	 * @param int $limit    Page size.
	 * @return array<int,array<string,mixed>>
	 */

	/** @param int $job_id Job ID. @return void */
	public function delete_job( $job_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned table via $wpdb->delete().
		$wpdb->delete( $this->table(), array( 'job_id' => absint( $job_id ) ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}

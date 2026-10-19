<?php
/** File snapshot index for incremental change detection. @package Nobash\Backup */
namespace Nobash\Backup\Incremental;
defined('ABSPATH') || exit;
class SnapshotRepository {
	private function table() {
		global$wpdb;
		return $wpdb->prefix . 'nobash_file_snapshots';
	}
	/** Save/replace one file fingerprint for a verified point. */
	public function put($point_id, $path_hash, $relative_path, $size, $mtime, $sha256) {
		global$wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct write to the plugin's own incremental-snapshot table; not cacheable.
		$wpdb->replace($this->table(), array('point_id' => (string)$point_id, 'path_hash' => (string)$path_hash, 'relative_path' => (string)$relative_path, 'size_bytes' => (int)$size, 'modified_at' => (int)$mtime, 'checksum_sha256' => (string)$sha256), array('%s', '%s', '%s', '%d', '%d', '%s'));
	}
	/** Compare current metadata against a parent point without loading its snapshot into memory. */
	public function changed($parent_point_id, $path_hash, $size, $mtime) {
		global$wpdb;
		$t = $this->table();
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT size_bytes,modified_at FROM %i WHERE point_id=%s AND path_hash=%s', $t, (string)$parent_point_id, (string)$path_hash ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return!is_array($row) || (int)$row['size_bytes'] !== (int)$size || (int)$row['modified_at'] !== (int)$mtime;
	}
	/** Stream deleted files into NDJSON. @return int */
	public function write_deleted($parent_point_id, $current_job_id, $output) {
		global$wpdb;
		$snap = $this->table();
		$inv = $wpdb->prefix . 'nobash_file_inventory';
		$after = '';
		$count = 0;
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT s.path_hash,s.relative_path FROM %i s LEFT JOIN %i i ON i.job_id=%d AND i.path_hash=s.path_hash WHERE s.point_id=%s AND s.path_hash>%s AND i.id IS NULL ORDER BY s.path_hash ASC LIMIT 500', $snap, $inv, (int)$current_job_id, (string)$parent_point_id, $after ),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ((array)$rows as $row) {
				$line = wp_json_encode(array('path' => (string)$row['relative_path'], 'deleted' => true)) . "\n";
				// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem::put_contents() has no append mode; this incrementally appends NDJSON lines across a bounded paging loop.
				if (false === file_put_contents($output, $line, FILE_APPEND | LOCK_EX)) throw new \RuntimeException(esc_html__('Could not write the deleted-file index.', 'nobash-backup'));
				// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$after = (string)$row['path_hash'];
				$count++;
			}
		} while (count((array)$rows) === 500);
		return $count;
	}
	/** Persist a verified point snapshot from a job inventory in bounded pages. @return int */
	public function capture_inventory($point_id, $job_id) {
		global$wpdb;
		$inventory = $wpdb->prefix . 'nobash_file_inventory';
		$after = 0;
		$count = 0;
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT id,path_hash,relative_path,size_bytes,modified_at,checksum_sha256 FROM %i WHERE job_id=%d AND entry_type='file' AND id>%d ORDER BY id ASC LIMIT 500", $inventory, absint($job_id), $after ),
				ARRAY_A
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ((array)$rows as $row) {
				$this->put($point_id, $row['path_hash'], $row['relative_path'], $row['size_bytes'], $row['modified_at'], (string)$row['checksum_sha256']);
				$after = (int)$row['id'];
				++$count;
			}
		} while (count((array)$rows) === 500);
		return $count;
	}
}

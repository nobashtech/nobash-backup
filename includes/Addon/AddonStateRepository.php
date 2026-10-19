<?php
/** Persist add-on health and migration state. @package Nobash\Backup */
namespace Nobash\Backup\Addon;
defined('ABSPATH') || exit;
class AddonStateRepository {
	private function table() {
		global$wpdb;
		return $wpdb->prefix . 'nobash_addons';
	}
	public function get($id) {
		global$wpdb;
		$t = $this->table();
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE addon_id=%s', $t, $id ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned state table.
	}
	public function save($id, array $data) {
		global$wpdb;
		$t = $this->table();
		$now = current_time('mysql', true);
		$row = array_merge(array('addon_id' => $id, 'status' => 'active', 'version' => '', 'api_version' => '', 'migration_version' => '', 'last_error' => '', 'updated_at' => $now), $data);
		$existing = $this->get($id);
		if ($existing) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct write to a plugin-owned state table; not cacheable.
			$wpdb->update($t, $row, array('addon_id' => $id));
		} else {
			$row['created_at'] = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct write to a plugin-owned state table.
			$wpdb->insert($t, $row);
		}
	}
	public function mark_error($id, $message) {
		$this->save($id, array('status' => 'error', 'last_error' => sanitize_text_field((string)$message)));
	}
	public function mark_active($id, array $manifest, $migration_version = '') {
		$this->save($id, array('status' => 'active', 'version' => (string)$manifest['version'], 'api_version' => (string)$manifest['api_version'], 'migration_version' => $migration_version, 'last_error' => ''));
	}
	public function all() {
		global$wpdb;
		$t = $this->table();
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY addon_id ASC', $t ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned state table.
		return is_array($rows)?$rows: array();
	}
}

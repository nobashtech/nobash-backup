<?php
/** Storage-aware archive retention and cleanup. @package Nobash\Backup */
namespace Nobash\Backup\Schedule;
use Nobash\Backup\Security\AuditLog;
use Nobash\Backup\Security\SafeTreeRemoval;
use Nobash\Backup\Storage\LocalStorage;
defined('ABSPATH') || exit;
final class RetentionManager {
	public function run() {
		$settings = get_option('nobash_backup_settings', array());
		if (!is_array($settings)) $settings = array();
		$count = max(1, min(500, absint($settings['retention_count'] ?? 10)));
		$days = max(1, min(3650, absint($settings['retention_days'] ?? 30)));
		$min_free = max(0, absint($settings['retention_min_free_mb'] ?? 1024)) * 1048576;
		$storage = new LocalStorage();
		$archives = $storage->list_archives();
		$protected = $this->protected_filenames();
		$deleted = array();
		$cutoff = time() - ($days * DAY_IN_SECONDS);
		foreach ($archives as $archive) {
			if (isset($protected[$archive['filename']]))continue;
			if ((int)$archive['modified'] < $cutoff && count($archives) - count($deleted) > 1) {
				if ($storage->delete_archive($archive['filename'])) $deleted[] = $archive['filename'];
			}
		}
		$remaining = array_values(array_filter($storage->list_archives(), static function ($a) use($protected) {
			return!isset($protected[$a['filename']]);
		}));
		if (count($remaining) > $count) {
			foreach (array_slice($remaining, $count) as $archive) {
				if ($storage->delete_archive($archive['filename'])) $deleted[] = $archive['filename'];
			}
		}
		$dir = LocalStorage::ensure_directory();
		$free = @disk_free_space($dir);
		if (false !== $free && $min_free > 0 && $free < $min_free) {
			$remaining = array_reverse($storage->list_archives());
			foreach ($remaining as $archive) {
				if (isset($protected[$archive['filename']]) || count($remaining) <= 1)continue;
				if ($storage->delete_archive($archive['filename'])) {
					$deleted[] = $archive['filename'];
					$free = @disk_free_space($dir);
					if (false !== $free && $free >= $min_free)break;
				}
			}
		}
		$this->cleanup_temporary(absint($settings['temporary_retention_hours'] ?? 48));
		if ($deleted) AuditLog::record('retention_purged', 'archive', (string)count($deleted), array('files' => array_slice(array_values(array_unique($deleted)), 0, 50)));
		return array_values(array_unique($deleted));
	}
	/** Return a non-destructive retention plan for the current local archives. */
	public function preview() {
		$settings = get_option('nobash_backup_settings', array());
		if (!is_array($settings)) {
			$settings = array();
		}
		$count = max(1, min(500, absint($settings['retention_count'] ?? 10)));
		$days = max(1, min(3650, absint($settings['retention_days'] ?? 30)));
		$cutoff = time() - $days * DAY_IN_SECONDS;
		$archives = (new LocalStorage())->list_archives();
		$protected = $this->protected_filenames();
		$keep = array();
		$delete = array();
		$unprotected_seen = 0;
		foreach ($archives as $archive) {
			$name = (string)$archive['filename'];
			if (isset($protected[$name])) {
				$archive['retention_reason'] = 'protected';
				$keep[] = $archive;
				continue;
			}
			++$unprotected_seen;
			$expired = (int)$archive['modified'] < $cutoff;
			$over_count = $unprotected_seen > $count;
			if (($expired || $over_count) && count($archives) - count($delete) > 1) {
				$archive['retention_reason'] = $expired?'older_than_limit': 'over_count_limit';
				$delete[] = $archive;
			} else {
				$archive['retention_reason'] = 'kept';
				$keep[] = $archive;
			}
		}
		return array('policy' => array('keep_count' => $count, 'keep_days' => $days, 'minimum_free_mb' => absint($settings['retention_min_free_mb'] ?? 1024)), 'keep_count' => count($keep), 'delete_count' => count($delete), 'reclaim_bytes' => array_sum(array_map(static function ($a) {
			return(int)$a['size'];
		}, $delete)), 'keep' => $keep, 'delete' => $delete,);
	}
	private function protected_filenames() {
		global$wpdb;
		$table = $wpdb->prefix . 'nobash_jobs';
		$rows = $wpdb->get_col(
			$wpdb->prepare( "SELECT cursor_data FROM %i WHERE status IN ('created','preflighting','queued','running','pausing','paused','retrying','verifying','cleaning')", $table )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$out = array();
		foreach ((array)$rows as $json) {
			$c = json_decode((string)$json, true);
			if (!is_array($c))continue;
			foreach (array('filename', 'archive_name', 'safety_backup_filename') as $k) {
				if (!empty($c[$k])) $out[basename((string)$c[$k])] = true;
			}
		}
		$points = $wpdb->prefix . 'nobash_restore_points';
		$chains = $wpdb->prefix . 'nobash_backup_chains';
		$chain_files = $wpdb->get_col(
			$wpdb->prepare( "SELECT p.filename FROM %i p INNER JOIN %i c ON c.chain_id=p.chain_id WHERE c.status='active' AND p.status='verified'", $points, $chains )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ((array)$chain_files as $file) {
			$out[basename((string)$file)] = true;
		}
		return $out;
	}
	private function cleanup_temporary($hours) {
		$hours = max(6, min(720, $hours));
		$root = trailingslashit(LocalStorage::ensure_directory()) . 'tmp';
		if (!is_dir($root)) return;
		$cutoff = time() - ($hours * HOUR_IN_SECONDS);
		$active = $this->active_job_ids();
		$items = @scandir($root);
		foreach ((array)$items as $name) {
			if ('.' === $name || '..' === $name || in_array($name, array('index.php', 'index.html', '.htaccess', 'web.config', 'nginx.conf', '.litespeed_deny'), true))continue;
			if (preg_match('/(?:job|restore|import)-(\d+)/', $name, $m) && isset($active[(int)$m[1]]))continue;
			$path = $root . '/' . $name;
			$mtime = @filemtime($path);
			if (false === $mtime || $mtime > $cutoff)continue;
			$this->remove_tree($path);
		}
	}
	private function active_job_ids() {
		global$wpdb;
		$table = $wpdb->prefix . 'nobash_jobs';
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM %i WHERE status NOT IN ('completed','completed_with_warnings','failed','cancelled')", $table )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$out = array();
		foreach ((array)$ids as $id) $out[(int)$id] = true;
		return $out;
	}
	private function remove_tree($path) {
		$root = dirname((string)$path);
		if (is_link($path) || is_file($path)) {
			$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
			if (!$wp_filesystem->delete($path, false, 'f')) throw new \RuntimeException(esc_html__('A retained temporary file could not be removed.','nobash-backup'));
			return;
		}
		if (!is_dir($path)) return;
		SafeTreeRemoval::remove($path, $root);
	}
}

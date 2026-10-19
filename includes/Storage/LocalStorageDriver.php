<?php
/**
 * Local filesystem storage driver (default).
 *
 * @package Nobash\Backup
 */
namespace Nobash\Backup\Storage;
use Nobash\Backup\Archive\ArchiveFingerprint;
defined('ABSPATH') || exit;
/**
 * Class LocalStorageDriver
 */
class LocalStorageDriver implements StorageDriverInterface {
	/**
			 * Local storage helper.
			 *
			 * @var LocalStorage
			 */
	private $storage;
	/**
			 * Constructor.
			 *
			 * @param LocalStorage $storage Storage.
			 */
	public function __construct(LocalStorage $storage) {
		$this->storage = $storage;
	}
	/**
			 * Ensure WP_Filesystem is initialized and return the direct filesystem API.
			 *
			 * @return \WP_Filesystem_Base
			 */
	private static function filesystem() {
		return \Nobash\Backup\Filesystem\DirectFilesystem::get();
	}
	/**
			 * {@inheritdoc}
			 */
	public function id() {
		return 'local';
	}
	/**
			 * {@inheritdoc}
			 */
	public function label() {
		return __('Local storage', 'nobash-backup');
	}
	public function capabilities() {
		return array('upload' => true, 'multipart' => true, 'download' => true, 'range_download' => true, 'delete' => true, 'list' => true, 'metadata' => true, 'capacity' => true, 'server_side_copy' => false, 'token_refresh' => false);
	}
	public function test_connection(array $config = array()) {
		$dir = LocalStorage::ensure_directory();
		$writable = self::filesystem()->is_writable($dir);
		return array('ok' => is_dir($dir) && $writable, 'message' => $writable?__('Local storage is writable.', 'nobash-backup'): __('Local storage is not writable.', 'nobash-backup'));
	}
	public function available_capacity(array $config = array()) {
		$free = @disk_free_space(LocalStorage::ensure_directory());
		return false === $free?null: (int)$free;
	}
	/**
			 * {@inheritdoc}
			 */
	public function put($local_path, array $args = array()) {
		$dest_dir = LocalStorage::ensure_directory();
		$name = isset($args['filename'])?basename((string)$args['filename']): basename($local_path);
		$dest = trailingslashit($dest_dir) . $name;
		if ($local_path !== $dest) {
			if (!self::filesystem()->move($local_path, $dest, true)) {
				if (!self::filesystem()->copy($local_path, $dest, true)) {
					throw new \RuntimeException(esc_html__('Failed to store backup archive locally.', 'nobash-backup'));
				}
				wp_delete_file($local_path);
			}
		}
		return $name;
	}
	/**
			 * {@inheritdoc}
			 */
	public function get($remote_id, $dest) {
		$path = $this->storage->resolve_archive($remote_id);
		if (null === $path) {
			throw new \RuntimeException(esc_html__('Archive not found.', 'nobash-backup'));
		}
		if (!self::filesystem()->copy($path, $dest, true)) {
			throw new \RuntimeException(esc_html__('Failed to copy archive.', 'nobash-backup'));
		}
	}
	/**
			 * {@inheritdoc}
			 */
	public function delete($remote_id) {
		$this->storage->delete_archive($remote_id);
	}
	/**
			 * {@inheritdoc}
			 */
	public function list_objects(array $args = array()) {
		return $this->storage->list_archives();
	}
	public function initiate_multipart($remote_id, $size, array $args = array()) {
		$id = wp_generate_uuid4();
		$dir = trailingslashit(LocalStorage::ensure_directory()) . 'tmp/multipart-' . sanitize_file_name($id);
		wp_mkdir_p($dir);
		LocalStorage::harden($dir);
		self::filesystem()->put_contents(trailingslashit($dir) . 'meta.json', wp_json_encode(array('remote_id' => basename($remote_id), 'size' => (int)$size)));
		return $id;
	}
	private function multipart_dir($upload_id) {
		return trailingslashit(LocalStorage::ensure_directory()) . 'tmp/multipart-' . sanitize_file_name($upload_id);
	}
	public function upload_part($upload_id, $part_number, $bytes, array $args = array()) {
		$dir = $this->multipart_dir($upload_id);
		if (!is_dir($dir)) throw new \RuntimeException(esc_html__('Multipart upload not found.', 'nobash-backup'));
		$path = trailingslashit($dir) . sprintf('part-%08d', absint($part_number));
		if (false === self::filesystem()->put_contents($path, $bytes)) throw new \RuntimeException(esc_html__('Could not store multipart upload part.', 'nobash-backup'));
		return array('part_number' => absint($part_number), 'size' => strlen($bytes), 'etag' => hash('sha256', $bytes));
	}
	public function list_uploaded_parts($upload_id, array $args = array()) {
		$files = glob(trailingslashit($this->multipart_dir($upload_id)) . 'part-*');
		$out = array();
		foreach (is_array($files)?$files: array() as $f) {
			$out[] = array('part_number' => (int)substr(basename($f), 5), 'size' => (int)filesize($f), 'etag' => hash_file('sha256', $f));
		}
		usort($out, static function ($a, $b) {
			return $a['part_number'] <=> $b['part_number'];
		});
		return $out;
	}
	public function complete_multipart($upload_id, array $parts, array $args = array()) {
		$dir = $this->multipart_dir($upload_id);
		$meta_raw = self::filesystem()->get_contents(trailingslashit($dir) . 'meta.json');
		$meta = json_decode((string)$meta_raw, true);
		if (!is_array($meta)) throw new \RuntimeException(esc_html__('Multipart metadata is invalid.', 'nobash-backup'));
		$tmp = trailingslashit($dir) . 'complete.partial';
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Assembling multi-GB archives from chunked parts via stream_copy_to_stream() requires direct file handles; WP_Filesystem has no streaming copy-between-handles primitive.
		$out = fopen($tmp, 'wb');
		if (!$out) throw new \RuntimeException(esc_html__('Could not finalize multipart upload.', 'nobash-backup'));
		foreach ($this->list_uploaded_parts($upload_id) as $part) {
			$in = fopen(trailingslashit($dir) . sprintf('part-%08d', $part['part_number']), 'rb');
			if (!$in || false === stream_copy_to_stream($in, $out)) {
				if ($in) fclose($in);
				fclose($out);
				throw new \RuntimeException(esc_html__('Could not assemble multipart upload.', 'nobash-backup'));
			}
			fclose($in);
		}
		fflush($out);
		fclose($out);
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$name = basename((string)$meta['remote_id']);
		$result = $this->put($tmp, array('filename' => $name));
		$this->abort_multipart($upload_id);
		return $result;
	}
	public function abort_multipart($upload_id, array $args = array()) {
		$dir = $this->multipart_dir($upload_id);
		if (!is_dir($dir)) return;
		foreach (glob(trailingslashit($dir) . '*')?: array() as $f) {
			if (is_file($f)) wp_delete_file($f);
		}
		self::filesystem()->rmdir($dir);
	}
	public function get_range($remote_id, $offset, $length, array $args = array()) {
		$path = $this->storage->resolve_archive($remote_id);
		if (null === $path) throw new \RuntimeException(esc_html__('Archive not found.', 'nobash-backup'));
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Random-access ranged read (fseek) from potentially multi-GB archives for chunked remote uploads; WP_Filesystem has no seek/range-read primitive.
		$fh = fopen($path, 'rb');
		if (!$fh || 0 !== fseek($fh, max(0, (int)$offset))) {
			if ($fh) fclose($fh);
			throw new \RuntimeException(esc_html__('Could not read archive range.', 'nobash-backup'));
		}
		$data = (string)fread($fh, max(1, (int)$length));
		fclose($fh);
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $data;
	}
	public function metadata($remote_id, array $args = array()) {
		$path = $this->storage->resolve_archive($remote_id);
		if (null === $path) throw new \RuntimeException(esc_html__('Archive not found.', 'nobash-backup'));
		$fingerprint = ArchiveFingerprint::inspect($path);
		return array('id' => basename($path), 'size' => (int)filesize($path), 'modified' => (int)filemtime($path), 'sha256' => (string)$fingerprint['fingerprint'], 'checksum_scheme' => (string)$fingerprint['checksum_scheme']);
	}
}

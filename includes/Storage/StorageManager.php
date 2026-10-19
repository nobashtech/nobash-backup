<?php
namespace Nobash\Backup\Storage;
use Nobash\Backup\Addon\Registry;
defined('ABSPATH') || exit;
final class StorageManager {
	private $registry;
	public function __construct(Registry $registry) {
		$this->registry = $registry;
	}
	public function driver($id) {
		$driver = $this->registry->get_storage(sanitize_key($id));
		if (!$driver) throw new \RuntimeException(esc_html__('Storage provider is not available.', 'nobash-backup'));
		return $driver;
	}
	public function providers() {
		$out = array();
		foreach ($this->registry->storage_drivers() as $id => $driver) {
			$out[] = array('id' => $id, 'label' => $driver->label(), 'capabilities' => $driver->capabilities());
		}
		return $out;
	}
}

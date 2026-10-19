<?php
/**
 * Coordinates the public, versioned Nobash Backup extension API.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Extension;

use Nobash\Backup\Container\Container;

defined( 'ABSPATH' ) || exit;

final class ExtensionManager {
	const API_VERSION = '1.0.0';

	/** @var Container */
	private $container;
	/** @var ModuleRegistry */
	private $modules;
	/** @var StorageProviderRegistry */
	private $storage;
	/** @var JobTypeRegistry */
	private $jobs;
	/** @var SettingsRegistry */
	private $settings;
	/** @var RouteRegistry */
	private $routes;
	/** @var CapabilityRegistry */
	private $capabilities;
	/** @var bool */
	private $booted = false;

	public function __construct( Container $container ) {
		$this->container    = $container;
		$this->modules      = new ModuleRegistry();
		$this->storage      = new StorageProviderRegistry();
		$this->jobs         = new JobTypeRegistry();
		$this->settings     = new SettingsRegistry();
		$this->routes       = new RouteRegistry();
		$this->capabilities = new CapabilityRegistry();
	}

	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$groups = array(
			'nobash_backup_register_modules'           => $this->modules,
			'nobash_backup_register_storage_providers' => $this->storage,
			'nobash_backup_register_job_types'         => $this->jobs,
			'nobash_backup_register_settings'          => $this->settings,
			'nobash_backup_register_admin_routes'      => $this->routes,
			'nobash_backup_register_capabilities'      => $this->capabilities,
		);

		foreach ( $groups as $hook => $registry ) {
			try {
				do_action( $hook, $registry ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- $hook is a key from the hardcoded nobash_backup_ registration map immediately above.
			} catch ( \Throwable $exception ) {
				do_action( 'nobash_backup_extension_registration_failed', $hook, $exception );
			}
		}

		do_action( 'nobash_backup_core_ready', $this );
	}

	public function api_version() {
		return self::API_VERSION;
	}

	public function supports_api( $minimum_version ) {
		return version_compare( self::API_VERSION, (string) $minimum_version, '>=' );
	}

	public function modules() { return $this->modules; }
	public function storage_providers() { return $this->storage; }
	public function job_types() { return $this->jobs; }
	public function settings() { return $this->settings; }
	public function routes() { return $this->routes; }
	public function capabilities() { return $this->capabilities; }
	public function container() { return $this->container; }
}

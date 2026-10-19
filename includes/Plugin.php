<?php
/**
 * Main plugin bootstrap.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup;

use Nobash\Backup\Addon\Registry as AddonRegistry;
use Nobash\Backup\Admin\AdminPage;
use Nobash\Backup\Cli\Commands;
use Nobash\Backup\Container\Container;
use Nobash\Backup\Database\Installer;
use Nobash\Backup\Extension\ExtensionManager;
use Nobash\Backup\Jobs\JobEngine;
use Nobash\Backup\Privacy\PrivacyPolicy;
use Nobash\Backup\Rest\RestController;
use Nobash\Backup\Schedule\Scheduler;
use Nobash\Backup\Storage\LocalStorage;
use Nobash\Backup\Storage\StorageManager;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private $container;

	/** @var ExtensionManager */
	private $extensions;

	/**
	 * Get singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->container = new Container();
		$this->extensions = new ExtensionManager( $this->container );
	}

	/**
	 * Get the container.
	 *
	 * @return Container
	 */
	public function container() {
		return $this->container;
	}

	/** Get the stable public extension manager. @return ExtensionManager */
	public function extensions() {
		return $this->extensions;
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		Installer::install();
		LocalStorage::ensure_directory();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		/* Deactivation must never be blocked by a scheduler/storage edge case.
		 * Cleanup is best-effort; WordPress must always be allowed to complete
		 * the plugin deactivation transaction. */
		try {
			Scheduler::clear_scheduled_events();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Leave any orphaned event for the next cleanup pass.
		}

		try {
			flush_rewrite_rules();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// A rewrite flush failure must not prevent deactivation.
		}
	}

	/**
	 * Boot the plugin.
	 *
	 * @return void
	 */
	public function boot() {
		$this->register_services();

		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 1 );

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'rest_api_init', array( $this->container->get( RestController::class ), 'register_routes' ) );

		if ( is_admin() ) {
			$this->container->get( AdminPage::class )->hooks();
			PrivacyPolicy::register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Commands::register();
		}

		$this->container->get( Scheduler::class )->hooks();
		$this->container->get( AddonRegistry::class )->boot();
		$this->extensions->boot();
	}

	/**
	 * Init hook.
	 *
	 * @return void
	 */
	public function init() {
		/**
		 * Fires after Nobash Backup has initialized.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'nobash_backup_init', $this );
	}

	/**
	 * Register core services.
	 *
	 * @return void
	 */
	private function register_services() {
		$c = $this->container;

		$c->singleton( Container::class, function () {
			return $this->container;
		} );

		$c->singleton( AddonRegistry::class, function () use ( $c ) {
			return new AddonRegistry( $c );
		} );

		$c->singleton( LocalStorage::class, static function () {
			return new LocalStorage();
		} );

		$c->singleton( StorageManager::class, function () use ( $c ) {
			return new StorageManager( $c->get( AddonRegistry::class ) );
		} );

		$c->singleton( JobEngine::class, function () use ( $c ) {
			return new JobEngine( $c );
		} );

		$c->singleton( RestController::class, function () use ( $c ) {
			return new RestController( $c );
		} );

		$c->singleton( AdminPage::class, static function () {
			return new AdminPage();
		} );

		$c->singleton( Scheduler::class, function () use ( $c ) {
			return new Scheduler( $c );
		} );

		/**
		 * Allow addons to register additional container bindings.
		 *
		 * @param Container $container Service container.
		 */
		do_action( 'nobash_backup_register_services', $c );
	}
}

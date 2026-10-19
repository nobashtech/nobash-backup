<?php
/**
 * Public Nobash Backup extension API functions.
 *
 * These helpers provide a stable procedural bridge for companion plugins and
 * third-party integrations without exposing the internal service container.
 *
 * @package Nobash\Backup
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'nobash_backup_extension_manager' ) ) {
	/**
	 * Get the extension manager.
	 *
	 * @return \Nobash\Backup\Extension\ExtensionManager
	 */
	function nobash_backup_extension_manager() {
		return \Nobash\Backup\Plugin::instance()->extensions();
	}
}

if ( ! function_exists( 'nobash_backup_register_module' ) ) {
	/**
	 * Register an extension module.
	 *
	 * @param string              $id       Module identifier.
	 * @param array<string,mixed> $metadata Module metadata.
	 * @param string              $owner    Extension owner.
	 * @return mixed
	 */
	function nobash_backup_register_module( $id, array $metadata, $owner = 'third-party' ) {
		return nobash_backup_extension_manager()->modules()->register( $id, $metadata, $owner );
	}
}

if ( ! function_exists( 'nobash_backup_register_storage_provider' ) ) {
	/**
	 * Register a storage provider.
	 *
	 * @param string $id       Provider identifier.
	 * @param mixed  $provider Provider implementation.
	 * @param string $owner    Extension owner.
	 * @return mixed
	 */
	function nobash_backup_register_storage_provider( $id, $provider, $owner = 'third-party' ) {
		return nobash_backup_extension_manager()->storage_providers()->register( $id, $provider, $owner );
	}
}

if ( ! function_exists( 'nobash_backup_register_job_type' ) ) {
	/**
	 * Register a job handler.
	 *
	 * @param string   $id      Job type identifier.
	 * @param callable $handler Job handler.
	 * @param string   $owner   Extension owner.
	 * @return mixed
	 */
	function nobash_backup_register_job_type( $id, callable $handler, $owner = 'third-party' ) {
		return nobash_backup_extension_manager()->job_types()->register( $id, $handler, $owner );
	}
}

if ( ! function_exists( 'nobash_backup_register_settings_section' ) ) {
	/**
	 * Register a settings section.
	 *
	 * @param string              $id      Section identifier.
	 * @param array<string,mixed> $section Section definition.
	 * @param string              $owner   Extension owner.
	 * @return mixed
	 */
	function nobash_backup_register_settings_section( $id, array $section, $owner = 'third-party' ) {
		return nobash_backup_extension_manager()->settings()->register( $id, $section, $owner );
	}
}

if ( ! function_exists( 'nobash_backup_register_admin_route' ) ) {
	/**
	 * Register an admin route.
	 *
	 * @param string              $id    Route identifier.
	 * @param array<string,mixed> $route Route definition.
	 * @param string              $owner Extension owner.
	 * @return mixed
	 */
	function nobash_backup_register_admin_route( $id, array $route, $owner = 'third-party' ) {
		return nobash_backup_extension_manager()->routes()->register( $id, $route, $owner );
	}
}

if ( ! function_exists( 'nobash_backup_register_capability' ) ) {
	/**
	 * Register a capability definition.
	 *
	 * @param string              $id         Capability identifier.
	 * @param array<string,mixed> $definition Capability definition.
	 * @param string              $owner      Extension owner.
	 * @return mixed
	 */
	function nobash_backup_register_capability( $id, array $definition, $owner = 'third-party' ) {
		return nobash_backup_extension_manager()->capabilities()->register( $id, $definition, $owner );
	}
}

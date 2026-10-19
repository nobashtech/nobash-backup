<?php
/** Restricted registration facade for add-ons. @package Nobash\Backup */
namespace Nobash\Backup\Addon;
use Nobash\Backup\Container\Container;
use Nobash\Backup\Storage\StorageDriverInterface;
defined( 'ABSPATH' ) || exit;
final class AddonContext {
	/** @var Registry */ private $registry;
	/** @var string */ private $addon_id;
	/** @var Container */ private $container;
	public function __construct( Registry $registry, $addon_id, Container $container ) { $this->registry=$registry; $this->addon_id=$addon_id; $this->container=$container; }
	public function addon_id(){ return $this->addon_id; }
	public function container(){ return $this->container; }
	public function register_service( $id, callable $factory ){ $this->registry->register_service( $this->addon_id, $id, $factory ); }
	public function register_job_type( $type, callable $handler, array $public_result_keys=array() ){ $this->registry->register_job_type( $type, $handler, $this->addon_id, $public_result_keys ); }
	public function register_storage( StorageDriverInterface $driver ){ $this->registry->register_storage( $driver, $this->addon_id ); }
	public function register_feature( $feature, array $meta=array() ){ $this->registry->register_feature( $feature, $meta, $this->addon_id ); }
	public function register_ui_extension( $slot, callable $renderer ){ $this->registry->register_ui_extension( $slot, $renderer, $this->addon_id ); }
	public function register_rest_route( $namespace, $route, array $args ){ $this->registry->register_rest_route( $namespace, $route, $args, $this->addon_id ); }
	public function register_migration( MigrationInterface $migration ){ $this->registry->register_migration( $migration, $this->addon_id ); }
}

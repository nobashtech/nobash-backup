<?php
/**
 * Lightweight service container.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Container;

defined( 'ABSPATH' ) || exit;

/**
 * Class Container
 */
class Container {

	/**
	 * Shared instances.
	 *
	 * @var array<string, mixed>
	 */
	private $instances = array();

	/**
	 * Factories.
	 *
	 * @var array<string, callable>
	 */
	private $factories = array();

	/**
	 * Register a singleton factory.
	 *
	 * @param string   $id      Service id (usually FQCN).
	 * @param callable $factory Factory.
	 * @return void
	 */
	public function singleton( $id, $factory ) {
		$this->factories[ $id ] = $factory;
	}

	/**
	 * Register an instance.
	 *
	 * @param string $id       Service id.
	 * @param mixed  $instance Instance.
	 * @return void
	 */
	public function instance( $id, $instance ) {
		$this->instances[ $id ] = $instance;
	}

	/**
	 * Resolve a service.
	 *
	 * @param string $id Service id.
	 * @return mixed
	 */
	public function get( $id ) {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'Service not registered: %s', $id ) ) );
		}

		$this->instances[ $id ] = call_user_func( $this->factories[ $id ], $this );

		return $this->instances[ $id ];
	}

	/**
	 * Whether a service is registered.
	 *
	 * @param string $id Service id.
	 * @return bool
	 */
	public function has( $id ) {
		return isset( $this->instances[ $id ] ) || isset( $this->factories[ $id ] );
	}
}

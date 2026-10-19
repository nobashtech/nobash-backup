<?php
namespace Nobash\Backup\Extension;
defined( 'ABSPATH' ) || exit;
final class RouteRegistry extends AbstractRegistry {
    protected function validate( $id, $value ) { if ( ! is_array( $value ) || empty( $value['route'] ) || empty( $value['permission_callback'] ) || ! is_callable( $value['permission_callback'] ) ) { throw new \InvalidArgumentException( 'Admin route requires route and permission callback.' ); } }
}

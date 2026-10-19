<?php
namespace Nobash\Backup\Extension;
defined( 'ABSPATH' ) || exit;
final class JobTypeRegistry extends AbstractRegistry {
    protected function validate( $id, $value ) { if ( ! is_callable( $value ) ) { throw new \InvalidArgumentException( 'Job type handler must be callable.' ); } }
}

<?php
namespace Nobash\Backup\Extension;
defined( 'ABSPATH' ) || exit;
final class CapabilityRegistry extends AbstractRegistry {
    protected function validate( $id, $value ) { if ( ! is_array( $value ) || empty( $value['capability'] ) ) { throw new \InvalidArgumentException( 'Capability registration requires capability.' ); } }
}

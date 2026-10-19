<?php
namespace Nobash\Backup\Extension;
defined( 'ABSPATH' ) || exit;
final class ModuleRegistry extends AbstractRegistry {
    protected function validate( $id, $value ) { if ( ! is_array( $value ) || empty( $value['label'] ) ) { throw new \InvalidArgumentException( 'Module metadata requires a label.' ); } }
}

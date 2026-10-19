<?php
namespace Nobash\Backup\Extension;
defined( 'ABSPATH' ) || exit;
final class SettingsRegistry extends AbstractRegistry {
    protected function validate( $id, $value ) { if ( ! is_array( $value ) || empty( $value['label'] ) ) { throw new \InvalidArgumentException( 'Settings section requires a label.' ); } }
}

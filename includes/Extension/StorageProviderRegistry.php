<?php
namespace Nobash\Backup\Extension;
defined( 'ABSPATH' ) || exit;
final class StorageProviderRegistry extends AbstractRegistry {
    protected function validate( $id, $value ) { if ( ! is_object( $value ) || ! is_callable( array( $value, 'id' ) ) ) { throw new \InvalidArgumentException( 'Storage provider must expose id().' ); } if ( sanitize_key( (string) $value->id() ) !== $id ) { throw new \InvalidArgumentException( 'Storage provider id mismatch.' ); } }
}

<?php
/** Public extension registry base. @package Nobash\Backup */
namespace Nobash\Backup\Extension;
defined( 'ABSPATH' ) || exit;
abstract class AbstractRegistry {
    /** @var array<string,mixed> */ protected $items = array();
    /** @var array<string,string> */ protected $owners = array();
    public function register( $id, $value, $owner = 'third-party' ) {
        $id = sanitize_key( (string) $id );
        if ( '' === $id ) { throw new \InvalidArgumentException( 'Extension id is required.' ); }
        if ( isset( $this->items[ $id ] ) ) { throw new \RuntimeException( esc_html( 'Extension already registered: ' . $id ) ); }
        $this->validate( $id, $value );
        $this->items[ $id ] = $value;
        $this->owners[ $id ] = sanitize_key( (string) $owner ) ?: 'third-party';
        return $value;
    }
    public function get( $id ) { $id = sanitize_key( (string) $id ); return isset( $this->items[ $id ] ) ? $this->items[ $id ] : null; }
    public function has( $id ) { return null !== $this->get( $id ); }
    public function all() { return $this->items; }
    public function owner( $id ) { $id = sanitize_key( (string) $id ); return isset( $this->owners[ $id ] ) ? $this->owners[ $id ] : ''; }
    protected function validate( $id, $value ) { unset( $id, $value ); }
}

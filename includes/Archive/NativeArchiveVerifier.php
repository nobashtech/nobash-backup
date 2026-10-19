<?php
/** Native archive verification facade. @package Nobash\Backup */
namespace Nobash\Backup\Archive;
defined( 'ABSPATH' ) || exit;
class NativeArchiveVerifier {
	/** @param string $path Archive. @return array<string,mixed> */
	public function verify( $path ) { return ( new NativeArchiveReader( $path ) )->verify(); }
}

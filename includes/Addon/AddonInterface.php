<?php
/** Add-on contract. @package Nobash\Backup */
namespace Nobash\Backup\Addon;
defined( 'ABSPATH' ) || exit;
interface AddonInterface {
	/** @return array<string,mixed> */
	public function manifest();
	/** @param AddonContext $context Context. @return void */
	public function register( AddonContext $context );
}

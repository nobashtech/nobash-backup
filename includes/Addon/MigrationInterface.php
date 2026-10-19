<?php
/** Add-on database migration contract. @package Nobash\Backup */
namespace Nobash\Backup\Addon;
defined( 'ABSPATH' ) || exit;
interface MigrationInterface {
	/** Stable monotonically increasing migration version. @return string */
	public function version();
	/** Apply migration idempotently. @return void */
	public function up();
}

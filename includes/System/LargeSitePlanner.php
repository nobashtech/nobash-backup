<?php
/** Capacity planning for large backup and restore jobs. @package Nobash\Backup */
namespace Nobash\Backup\System;

use Nobash\Backup\Compat\Environment;
use Nobash\Backup\Storage\LocalStorage;

defined( 'ABSPATH' ) || exit;

final class LargeSitePlanner {
	/** 64-bit PHP is required for reliable offsets beyond 2 GiB. */
	public function supports_large_offsets() { return PHP_INT_SIZE >= 8; }

	/** Best-effort physical database size. @return int */
	public function database_bytes() {
		global $wpdb;
		if ( 'sqlite' === Environment::db_engine() ) {
			$path = defined( 'DB_FILE' ) ? (string) DB_FILE : '';
			return is_file( $path ) ? (int) filesize( $path ) : 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var( 'SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema = DATABASE()' );
		return max( 0, (int) $value );
	}

	/** Conservative local workspace reserve for a backup. @return int */
	public function backup_workspace_bytes() {
		$db = $this->database_bytes();
		// SQL text can exceed on-disk InnoDB size. Keep a bounded reserve while the
		// filesystem inventory determines the exact archive size incrementally.
		return max( 268435456, (int) ceil( $db * 1.75 ) );
	}

	/** @return int|false */
	public function free_bytes() { return @disk_free_space( LocalStorage::ensure_directory() ); }
}

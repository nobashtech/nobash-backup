<?php
/** Incremental mode validation. @package Nobash\Backup */
namespace Nobash\Backup\Incremental;
defined('ABSPATH') || exit;
final class BackupMode {
	const FULL = 'full';
	const INCREMENTAL = 'incremental';
	const DIFFERENTIAL = 'differential';
	public static function normalize($mode) {
		$mode = sanitize_key((string)$mode);
		return in_array($mode, array(self::FULL, self::INCREMENTAL, self::DIFFERENTIAL), true)?$mode: self::FULL;
	}
	/** Differential points compare to base; incremental points compare to latest verified point. */
	public static function parent(array $chain, $mode) {
		return self::DIFFERENTIAL === $mode?(string)$chain['base_point_id']: (string)$chain['latest_point_id'];
	}
}

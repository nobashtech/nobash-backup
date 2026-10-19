<?php
/** Native WordPress uninstall entry point. @package Nobash\Backup */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
require_once __DIR__ . '/includes/Lifecycle/Uninstall.php';
\Nobash\Backup\Lifecycle\Uninstall::run();

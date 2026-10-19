<?php
/**
 * Explicit permanent job exception.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Mark a failure as unsafe or pointless to retry automatically.
 */
class PermanentJobException extends \RuntimeException {
}

<?php
/**
 * Explicit retryable job exception.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Mark a failure as safe to retry from the durable checkpoint.
 */
class RetryableJobException extends \RuntimeException {
}

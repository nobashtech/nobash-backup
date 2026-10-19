<?php
/**
 * Durable job state machine.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Valid states and transitions for all durable jobs.
 */
final class JobState {

	const CREATED                 = 'created';
	const PREFLIGHTING            = 'preflighting';
	const QUEUED                  = 'queued';
	const RUNNING                 = 'running';
	const PAUSING                 = 'pausing';
	const PAUSED                  = 'paused';
	const RETRYING                = 'retrying';
	const VERIFYING               = 'verifying';
	const COMPLETED               = 'completed';
	const COMPLETED_WITH_WARNINGS = 'completed_with_warnings';
	const FAILED                  = 'failed';
	const CANCELLED               = 'cancelled';
	const CLEANING                = 'cleaning';

	/**
	 * Normalize a job state.
	 *
	 * @param string $state State.
	 * @return string
	 */
	public static function normalize( $state ) {
		$state = (string) $state;
		return in_array( $state, self::all(), true ) ? $state : self::CREATED;
	}

	/**
	 * All states.
	 *
	 * @return array<int, string>
	 */
	public static function all() {
		return array(
			self::CREATED,
			self::PREFLIGHTING,
			self::QUEUED,
			self::RUNNING,
			self::PAUSING,
			self::PAUSED,
			self::RETRYING,
			self::VERIFYING,
			self::COMPLETED,
			self::COMPLETED_WITH_WARNINGS,
			self::FAILED,
			self::CANCELLED,
			self::CLEANING,
		);
	}

	/**
	 * Whether a state is terminal.
	 *
	 * @param string $state State.
	 * @return bool
	 */
	public static function is_terminal( $state ) {
		return in_array(
			self::normalize( $state ),
			array( self::COMPLETED, self::COMPLETED_WITH_WARNINGS, self::FAILED, self::CANCELLED ),
			true
		);
	}

	/**
	 * Whether a state may be offered to a runner.
	 *
	 * @param string $state State.
	 * @return bool
	 */
	public static function is_runnable( $state ) {
		return in_array(
			self::normalize( $state ),
			array( self::CREATED, self::PREFLIGHTING, self::QUEUED, self::RUNNING, self::RETRYING, self::VERIFYING, self::PAUSING, self::CLEANING ),
			true
		);
	}

	/**
	 * Validate a state transition.
	 *
	 * @param string $from Current state.
	 * @param string $to   Target state.
	 * @return bool
	 */
	public static function can_transition( $from, $to ) {
		$from = self::normalize( $from );
		$to   = self::normalize( $to );

		if ( $from === $to ) {
			return ! self::is_terminal( $from );
		}

		$map = array(
			self::CREATED                 => array( self::PREFLIGHTING, self::QUEUED, self::PAUSING, self::CLEANING, self::CANCELLED ),
			self::PREFLIGHTING            => array( self::QUEUED, self::RUNNING, self::PAUSING, self::RETRYING, self::FAILED, self::CLEANING, self::CANCELLED ),
			self::QUEUED                  => array( self::RUNNING, self::PAUSING, self::CLEANING, self::CANCELLED ),
			self::RUNNING                 => array( self::PAUSING, self::RETRYING, self::VERIFYING, self::COMPLETED, self::COMPLETED_WITH_WARNINGS, self::FAILED, self::CLEANING, self::CANCELLED ),
			self::PAUSING                 => array( self::PAUSED, self::QUEUED, self::RUNNING, self::CLEANING, self::CANCELLED ),
			self::PAUSED                  => array( self::QUEUED, self::CLEANING, self::CANCELLED ),
			self::RETRYING                => array( self::QUEUED, self::RUNNING, self::PAUSING, self::FAILED, self::CLEANING, self::CANCELLED ),
			self::VERIFYING               => array( self::RUNNING, self::PAUSING, self::RETRYING, self::COMPLETED, self::COMPLETED_WITH_WARNINGS, self::FAILED, self::CLEANING, self::CANCELLED ),
			self::CLEANING                => array( self::CANCELLED, self::FAILED ),
			self::COMPLETED               => array(),
			self::COMPLETED_WITH_WARNINGS => array(),
			self::FAILED                  => array( self::QUEUED ),
			self::CANCELLED               => array(),
		);

		return isset( $map[ $from ] ) && in_array( $to, $map[ $from ], true );
	}
}

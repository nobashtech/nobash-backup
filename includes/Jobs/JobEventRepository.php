<?php
/**
 * Bounded durable job event history.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Store a bounded, sanitized history of job lifecycle events.
 */
class JobEventRepository {

	const MAX_EVENTS_PER_JOB = 200;

	/**
	 * Event table name.
	 *
	 * @return string
	 */
	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'nobash_job_events';
	}

	/**
	 * Append an event and trim old entries.
	 *
	 * @param int                  $job_id  Job ID.
	 * @param string               $event   Event name.
	 * @param array<string, mixed> $context Safe context.
	 * @return void
	 */
	public function append( $job_id, $event, array $context = array() ) {
		global $wpdb;
		$job_id = absint( $job_id );
		if ( $job_id < 1 ) {
			return;
		}

		$message = isset( $context['message'] ) ? JobError::sanitize_message( (string) $context['message'] ) : '';
		unset( $context['message'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$this->table(),
			array(
				'job_id'       => $job_id,
				'event_type'   => sanitize_key( $event ),
				'status'       => isset( $context['status'] ) ? sanitize_key( (string) $context['status'] ) : '',
				'step'         => isset( $context['step'] ) ? sanitize_key( (string) $context['step'] ) : '',
				'attempt'      => isset( $context['attempt'] ) ? absint( $context['attempt'] ) : 0,
				'message'      => $message,
				'context_data' => wp_json_encode( $this->sanitize_context( $context ) ),
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		/* Trimming after every heartbeat/slice event adds an expensive nested DELETE
		 * to the hot path. Allow a small bounded overflow and compact periodically,
		 * always compacting terminal/failure events so the visible history remains
		 * capped without penalizing every 20-second worker slice. */
		$insert_id = (int) $wpdb->insert_id;
		$terminal  = in_array( sanitize_key( (string) ( $context['status'] ?? '' ) ), array( 'completed', 'completed_with_warnings', 'failed', 'cancelled' ), true );
		$important = $terminal || in_array( sanitize_key( $event ), array( 'state_changed', 'retry_scheduled', 'stale_worker_recovered' ), true );
		if ( $important || ( $insert_id > 0 && 0 === $insert_id % 16 ) ) {
			$table = $this->table();
			// Keep only the newest bounded set.
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE job_id = %d AND id NOT IN (SELECT id FROM (SELECT id FROM %i WHERE job_id = %d ORDER BY id DESC LIMIT %d) newest)',
					$table,
					$job_id,
					$table,
					$job_id,
					self::MAX_EVENTS_PER_JOB
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}

	/**
	 * Delete all events for a job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function purge( $job_id ) {
		global $wpdb;
		$table = $this->table();
		$result = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE job_id = %d', $table, absint( $job_id ) )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $result;
	}

	/**
	 * Recent events for a job.
	 *
	 * @param int $job_id Job ID.
	 * @param int $limit  Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( $job_id, $limit = 50 ) {
		global $wpdb;
		$table = $this->table();
		$limit = max( 1, min( self::MAX_EVENTS_PER_JOB, absint( $limit ) ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE job_id = %d ORDER BY id DESC LIMIT %d', $table, absint( $job_id ), $limit ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$context = json_decode( (string) $row['context_data'], true );
			$out[]   = array(
				'id'      => (int) $row['id'],
				'event'   => (string) $row['event_type'],
				'status'  => (string) $row['status'],
				'step'    => (string) $row['step'],
				'attempt' => (int) $row['attempt'],
				'message' => (string) $row['message'],
				'context' => is_array( $context ) ? $context : array(),
				'created' => (string) $row['created_at'],
			);
		}
		return $out;
	}

	/**
	 * Limit event context to scalar, non-secret values.
	 *
	 * @param array<string, mixed> $context Context.
	 * @return array<string, mixed>
	 */
	private function sanitize_context( array $context ) {
		$out = array();
		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || preg_match( '/password|secret|token|key|credential/i', $key ) ) {
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = is_string( $value ) ? JobError::sanitize_message( $value ) : $value;
			}
		}
		return $out;
	}
}

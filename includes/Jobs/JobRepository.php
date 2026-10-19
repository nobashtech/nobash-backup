<?php
/**
 * Durable job repository (custom table).
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Persist durable jobs and enforce atomic state/lease changes.
 */
class JobRepository {

	/** @var JobEventRepository */
	private $events;

	public function __construct( ?JobEventRepository $events = null ) {
		$this->events = $events ? $events : new JobEventRepository();
	}

	/** @return string */
	public function table() {
		global $wpdb;
		return $wpdb->prefix . 'nobash_jobs';
	}

	/** @return JobEventRepository */
	public function events() {
		return $this->events;
	}

	/**
	 * Create a durable job.
	 *
	 * @param string               $type   Job type.
	 * @param array<string, mixed> $cursor Initial cursor.
	 * @param int                  $max_attempts Maximum failed attempts.
	 * @return int
	 */
	public function create( $type, array $cursor = array(), $max_attempts = RetryPolicy::DEFAULT_MAX_ATTEMPTS ) {
		global $wpdb;
		$now          = current_time( 'mysql', true );
		$operation_id = wp_generate_uuid4();
		$step         = isset( $cursor['phase'] ) ? sanitize_key( (string) $cursor['phase'] ) : 'init';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$this->table(),
			array(
				'operation_id'     => $operation_id,
				'type'             => sanitize_key( $type ),
				'status'           => JobState::CREATED,
				'current_step'     => $step,
				'progress'         => 0,
				'attempt_count'    => 0,
				'max_attempts'     => max( 1, min( 20, absint( $max_attempts ) ) ),
				'available_at'     => $now,
				'heartbeat_at'     => $now,
				'pause_requested'  => 0,
				'cancel_requested' => 0,
				'cursor_data'      => wp_json_encode( $cursor ),
				'result_data'      => null,
				'error_code'       => null,
				'error_message'    => null,
				'created_at'       => $now,
				'updated_at'       => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$id = (int) $wpdb->insert_id;
		if ( $id > 0 ) {
			$this->events->append( $id, 'created', array( 'status' => JobState::CREATED, 'step' => $step ) );
		}
		return $id;
	}

	/**
	 * Delete a job row and its event history.
	 *
	 * @param int  $id           Job ID.
	 * @param bool $purge_events Whether to also purge events.
	 * @return bool
	 */
	public function delete( $id, $purge_events = true ) {
		global $wpdb;
		$id = absint( $id );
		if ( $id < 1 ) {
			return false;
		}
		if ( $purge_events ) {
			$this->events->purge( $id );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, -- Single-use job row delete through wpdb::delete(); not cacheable.
		$result = $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
		return false !== $result;
	}

	/** @param int $id Job ID. @return array<string, mixed>|null */
	public function find( $id ) {
		global $wpdb;
		$table = $this->table();
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, absint( $id ) ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * General field update. State changes should prefer transition().
	 *
	 * @param int                  $id   Job ID.
	 * @param array<string, mixed> $data Fields.
	 * @return bool
	 */
	public function update( $id, array $data ) {
		global $wpdb;
		$fields = array( 'updated_at' => current_time( 'mysql', true ) );
		$format = array( '%s' );
		$this->apply_fields( $fields, $format, $data );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( $this->table(), $fields, array( 'id' => absint( $id ) ), $format, array( '%d' ) );
	}

	/**
	 * Commit an in-progress slice atomically while the caller still owns a live lease.
	 *
	 * This is intentionally cheaper than transition(): normal RUNNING -> RUNNING
	 * checkpoints do not need another state lookup or lifecycle event. The WHERE
	 * clause also prevents a worker whose lease expired/reassigned from publishing
	 * a stale cursor after another worker has resumed the same job.
	 *
	 * @param int                  $id              Job ID.
	 * @param string               $lease_owner     Current lease owner.
	 * @param string               $expected_status Status observed before processing.
	 * @param array<string, mixed> $data            Checkpoint fields.
	 * @param int                  $lease_ttl        Lease renewal seconds.
	 * @return bool
	 */
	public function checkpoint( $id, $lease_owner, $expected_status, array $data, $lease_ttl = 180 ) {
		global $wpdb;
		$id              = absint( $id );
		$lease_owner     = (string) $lease_owner;
		$expected_status = JobState::normalize( $expected_status );
		if ( $id < 1 || '' === $lease_owner || JobState::is_terminal( $expected_status ) ) {
			return false;
		}

		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + max( 30, min( 300, absint( $lease_ttl ) ) ) );
		$fields  = array(
			'updated_at'       => $now,
			'heartbeat_at'     => $now,
			'lease_expires_at' => $expires,
		);
		$format  = array( '%s', '%s', '%s' );
		$this->apply_fields( $fields, $format, $data );

		$values    = array( $this->table() );
		$set_parts = $this->prepared_assignments( $fields, $format, $values );
		$values[]  = $id;
		$values[]  = $expected_status;
		$values[]  = $lease_owner;
		$values[]  = $now;
		$sql = 'UPDATE %i SET ' . implode( ', ', $set_parts ) . ' WHERE id = %d AND status = %s AND lease_owner = %s AND lease_expires_at IS NOT NULL AND lease_expires_at >= %s AND pause_requested = 0 AND cancel_requested = 0';
		return 1 === (int) $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Assignment fragments contain placeholders only; field identifiers and values are all prepare arguments.
	}

	/**
	 * Atomic, validated state transition.
	 *
	 * @param int                  $id          Job ID.
	 * @param string               $target      Target state.
	 * @param array<string, mixed> $data        Additional fields.
	 * @param string               $lease_owner Required lease owner, when supplied.
	 * @return bool
	 */
	public function transition( $id, $target, array $data = array(), $lease_owner = '' ) {
		global $wpdb;
		$job = $this->find( $id );
		if ( ! $job ) {
			return false;
		}
		$current = JobState::normalize( $job['status'] );
		$target  = JobState::normalize( $target );
		if ( ! JobState::can_transition( $current, $target ) ) {
			return false;
		}

		$fields = array(
			'status'     => $target,
			'updated_at' => current_time( 'mysql', true ),
		);
		$format = array( '%s', '%s' );
		if ( JobState::RUNNING === $target && empty( $job['started'] ) ) {
			$fields['started_at'] = current_time( 'mysql', true );
			$format[]             = '%s';
		}
		if ( JobState::is_terminal( $target ) ) {
			$fields['completed_at'] = current_time( 'mysql', true );
			$format[]               = '%s';
		} elseif ( JobState::is_terminal( $current ) ) {
			$fields['completed_at'] = null;
			$format[]               = '%s';
		}
		$this->apply_fields( $fields, $format, $data );

		$values    = array( $this->table() );
		$set_parts = $this->prepared_assignments( $fields, $format, $values );
		$values[]  = absint( $id );
		$values[]  = $current;
		if ( '' !== $lease_owner ) {
			$sql = 'UPDATE %i SET ' . implode( ', ', $set_parts ) . ' WHERE id = %d AND status = %s AND lease_owner = %s AND lease_expires_at IS NOT NULL AND lease_expires_at >= %s';
			$values[] = $lease_owner;
			$values[] = current_time( 'mysql', true );
		} else {
			$sql = 'UPDATE %i SET ' . implode( ', ', $set_parts ) . ' WHERE id = %d AND status = %s';
		}
		$changed = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Assignment fragments contain placeholders only; field identifiers and values are all prepare arguments.
		if ( 1 === (int) $changed ) {
			$this->events->append(
				$id,
				'state_changed',
				array(
					'status'  => $target,
					'step'    => isset( $data['step'] ) ? $data['step'] : $job['step'],
					'attempt' => isset( $data['attempt_count'] ) ? $data['attempt_count'] : $job['attempt'],
					'from'    => $current,
				)
			);
			return true;
		}
		return false;
	}

	/**
	 * Acquire a time-limited lease atomically.
	 *
	 * @param int    $id       Job ID.
	 * @param string $owner    Random worker owner.
	 * @param int    $ttl      Lease TTL seconds.
	 * @param string $runner           Runner name.
	 * @param bool   $respect_schedule Whether available_at must be due.
	 * @param bool   $include_paused   Whether a control action may lease a paused job.
	 * @return bool
	 */
	public function acquire_lease( $id, $owner, $ttl = 45, $runner = 'manual', $respect_schedule = true, $include_paused = false ) {
		global $wpdb;
		$now      = current_time( 'mysql', true );
		$expires  = gmdate( 'Y-m-d H:i:s', time() + max( 15, min( 300, absint( $ttl ) ) ) );
		$table    = $this->table();
		$values = array( $table, $owner, $expires, $now, sanitize_key( $runner ), $now, absint( $id ) );
		if ( $include_paused ) {
			$sql = $respect_schedule
				? "UPDATE %i SET lease_owner=%s,lease_expires_at=%s,heartbeat_at=%s,runner=%s,updated_at=%s WHERE id=%d AND status NOT IN ('completed','completed_with_warnings','failed','cancelled') AND (available_at IS NULL OR available_at<=%s) AND (lease_owner IS NULL OR lease_owner='' OR lease_expires_at IS NULL OR lease_expires_at<%s OR lease_owner=%s)"
				: "UPDATE %i SET lease_owner=%s,lease_expires_at=%s,heartbeat_at=%s,runner=%s,updated_at=%s WHERE id=%d AND status NOT IN ('completed','completed_with_warnings','failed','cancelled') AND (lease_owner IS NULL OR lease_owner='' OR lease_expires_at IS NULL OR lease_expires_at<%s OR lease_owner=%s)";
		} else {
			$sql = $respect_schedule
				? "UPDATE %i SET lease_owner=%s,lease_expires_at=%s,heartbeat_at=%s,runner=%s,updated_at=%s WHERE id=%d AND status NOT IN ('completed','completed_with_warnings','failed','cancelled','paused') AND (available_at IS NULL OR available_at<=%s) AND (lease_owner IS NULL OR lease_owner='' OR lease_expires_at IS NULL OR lease_expires_at<%s OR lease_owner=%s)"
				: "UPDATE %i SET lease_owner=%s,lease_expires_at=%s,heartbeat_at=%s,runner=%s,updated_at=%s WHERE id=%d AND status NOT IN ('completed','completed_with_warnings','failed','cancelled','paused') AND (lease_owner IS NULL OR lease_owner='' OR lease_expires_at IS NULL OR lease_expires_at<%s OR lease_owner=%s)";
		}
		if ( $respect_schedule ) { $values[] = $now; }
		$values[] = $now;
		$values[] = $owner;
		$changed = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query template is selected exclusively from fixed literals; all variable values and the table identifier are prepare arguments.
		if ( 1 === (int) $changed ) {
			$this->events->append( $id, 'lease_acquired', array( 'runner' => $runner ) );
			return true;
		}
		return false;
	}

	/** @param int $id Job ID. @param string $owner Owner. @param int $ttl TTL. @return bool */
	public function renew_lease( $id, $owner, $ttl = 45 ) {
		global $wpdb;
		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + max( 15, min( 300, absint( $ttl ) ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$changed = $wpdb->update(
			$this->table(),
			array( 'lease_expires_at' => $expires, 'heartbeat_at' => $now, 'updated_at' => $now ),
			array( 'id' => absint( $id ), 'lease_owner' => $owner ),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);
		return 1 === (int) $changed;
	}

	/** @param int $id Job ID. @param string $owner Owner. @return bool */
	public function release_lease( $id, $owner ) {
		global $wpdb;
		$table = $this->table();
		$changed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET lease_owner = NULL, lease_expires_at = NULL, updated_at = %s WHERE id = %d AND lease_owner = %s',
				$table,
				current_time( 'mysql', true ),
				absint( $id ),
				$owner
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return 1 === (int) $changed;
	}

	/** @param int $id Job ID. @param string $owner Owner. @return bool */
	public function owns_lease( $id, $owner ) {
		$job = $this->find( $id );
		return $job && hash_equals( (string) $job['lease_owner'], (string) $owner ) && ! $this->lease_expired( $job );
	}

	/** @param array<string, mixed> $job Job. @return bool */
	public function lease_expired( array $job ) {
		return empty( $job['lease_expires'] ) || strtotime( (string) $job['lease_expires'] . ' UTC' ) < time();
	}

	/** @param int $id Job ID. @return bool */
	public function request_pause( $id ) {
		return $this->update( $id, array( 'pause_requested' => 1 ) );
	}

	/** @param int $id Job ID. @return bool */
	public function clear_pause( $id ) {
		return $this->update( $id, array( 'pause_requested' => 0 ) );
	}

	/** @param int $id Job ID. @return bool */
	public function request_cancel( $id ) {
		return $this->update( $id, array( 'cancel_requested' => 1 ) );
	}

	/** @param int $id Job ID. @return bool */
	public function clear_cancel( $id ) {
		return $this->update( $id, array( 'cancel_requested' => 0 ) );
	}

	/**
	 * Jobs with expired leases or stale heartbeats.
	 *
	 * @param int $limit Limit.
	 * @param int $stale_after Seconds.
	 * @return array<int, array<string, mixed>>
	 */
	public function stale( $limit = 20, $stale_after = 300 ) {
		global $wpdb;
		$table  = $this->table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 30, absint( $stale_after ) ) );
		$now    = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is the hardcoded internal prefix, not user input; all values are prepared.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table identifier and values are prepared.
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status IN ('running','pausing','verifying','cleaning') AND ((lease_expires_at IS NOT NULL AND lease_expires_at < %s) OR heartbeat_at < %s) ORDER BY updated_at ASC LIMIT %d",
				$table,
				$now,
				$cutoff,
				max( 1, min( 100, absint( $limit ) ) )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? array_map( array( $this, 'hydrate' ), $rows ) : array();
	}

	/**
	 * Clear only an expired lease.
	 *
	 * @param int $id Job ID.
	 * @return bool
	 */
	public function clear_expired_lease( $id ) {
		global $wpdb;
		$table = $this->table();
		$now   = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $table is the hardcoded internal prefix, not user input; all values are prepared.
		$changed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table identifier and values are prepared.
			$wpdb->prepare(
				'UPDATE %i SET lease_owner = NULL, lease_expires_at = NULL, updated_at = %s WHERE id = %d AND (lease_expires_at IS NULL OR lease_expires_at < %s)',
				$table,
				$now,
				absint( $id ),
				$now
			)
		);
		return 1 === (int) $changed;
	}

	/**
	 * Permanently remove terminal job history and its event records.
	 *
	 * Active, queued and paused jobs are never removed. IDs supplied in
	 * $protected_ids are preserved even when their status is terminal.
	 *
	 * @param array<int, int> $protected_ids Job IDs that must remain available.
	 * @return array{deleted:int,preserved:int}
	 */
	public function clear_terminal_history( array $protected_ids = array() ) {
		global $wpdb;
		$table = $this->table();
		$protected_ids = array_values( array_filter( array_map( 'absint', $protected_ids ) ) );
		$exclude_sql = '';
		$args = array( $table );
		if ( $protected_ids ) {
			$exclude_sql = ' AND id NOT IN (' . implode( ',', array_fill( 0, count( $protected_ids ), '%d' ) ) . ')';
			$args = array_merge( $args, $protected_ids );
		}
		$query = "SELECT id FROM %i WHERE status IN ('completed','completed_with_warnings','failed','cancelled'){$exclude_sql} ORDER BY id ASC";
		$ids = $wpdb->get_col( $wpdb->prepare( $query, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Locally generated fragments contain only placeholders and fixed SQL; all identifiers and values are passed to prepare().
		$deleted = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			if ( $this->delete( absint( $id ), true ) ) {
				++$deleted;
			}
		}
		$preserved = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return array(
			'deleted'   => $deleted,
			'preserved' => $preserved,
		);
	}

	/** @param int $limit Limit. @return array<int, array<string, mixed>> */
	public function recent( $limit = 20 ) {
		global $wpdb;
		$table = $this->table();
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, absint( $limit ) ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $rows ) ? array_map( array( $this, 'hydrate' ), $rows ) : array();
	}

	/**
	 * Map supported update fields.
	 *
	 * @param array<string, mixed> $fields Output fields.
	 * @param array<int, string>   $format Output formats.
	 * @param array<string, mixed> $data   Input.
	 * @return void
	 */
	private function apply_fields( array &$fields, array &$format, array $data ) {
		$map = array(
			'progress'         => array( 'progress', '%d', static function ( $v ) { return max( 0, min( 100, (int) $v ) ); } ),
			'step'             => array( 'current_step', '%s', 'sanitize_key' ),
			'attempt_count'    => array( 'attempt_count', '%d', 'absint' ),
			'max_attempts'     => array( 'max_attempts', '%d', 'absint' ),
			'available_at'     => array( 'available_at', '%s', static function ( $v ) { return $v; } ),
			'heartbeat_at'     => array( 'heartbeat_at', '%s', static function ( $v ) { return $v; } ),
			'pause_requested'  => array( 'pause_requested', '%d', 'absint' ),
			'cancel_requested' => array( 'cancel_requested', '%d', 'absint' ),
			'processed_items'  => array( 'processed_items', '%d', 'absint' ),
			'total_items'      => array( 'total_items', '%d', 'absint' ),
			'processed_bytes'  => array( 'processed_bytes', '%d', 'absint' ),
			'total_bytes'      => array( 'total_bytes', '%d', 'absint' ),
			'runner'           => array( 'runner', '%s', 'sanitize_key' ),
			'error_code'       => array( 'error_code', '%s', 'sanitize_key' ),
			'error'            => array( 'error_message', '%s', static function ( $v ) { return null === $v ? null : JobError::sanitize_message( (string) $v ); } ),
		);
		foreach ( $map as $input => $spec ) {
			if ( array_key_exists( $input, $data ) ) {
				$fields[ $spec[0] ] = call_user_func( $spec[2], $data[ $input ] );
				$format[]           = $spec[1];
			}
		}
		if ( array_key_exists( 'cursor', $data ) ) {
			$fields['cursor_data'] = wp_json_encode( $data['cursor'] );
			$format[]              = '%s';
		}
		if ( array_key_exists( 'result', $data ) ) {
			$fields['result_data'] = wp_json_encode( $data['result'] );
			$format[]              = '%s';
		}
	}

	/**
	 * Build SET assignment placeholders while passing every field identifier through %i.
	 *
	 * @param array<string,mixed> $fields Field/value map from the internal allowlist.
	 * @param array<int,string>   $formats Value formats matching $fields order.
	 * @param array<int,mixed>    $args Prepare arguments, appended by reference.
	 * @return array<int,string>
	 */
	private function prepared_assignments( array $fields, array $formats, array &$args ) {
		$parts = array();
		$index = 0;
		foreach ( $fields as $field => $value ) {
			$placeholder = $this->placeholder_for( isset( $formats[ $index ] ) ? $formats[ $index ] : '%s' );
			++$index;
			if ( null === $value ) {
				$parts[] = '%i = NULL';
				$args[] = $field;
				continue;
			}
			$parts[] = '%i = ' . $placeholder;
			$args[] = $field;
			$args[] = $value;
		}
		return $parts;
	}

	/** @param string $format Format. @return string */
	private function placeholder_for( $format ) {
		return in_array( $format, array( '%d', '%f', '%s' ), true ) ? $format : '%s';
	}

	/**
	 * Hydrate DB row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ) {
		$cursor = json_decode( (string) ( $row['cursor_data'] ?? '' ), true );
		$result = json_decode( (string) ( $row['result_data'] ?? '' ), true );
		$status = JobState::normalize( (string) ( $row['status'] ?? '' ) );
		return array(
			'id'               => (int) $row['id'],
			'operation_id'     => (string) ( $row['operation_id'] ?? '' ),
			'type'             => (string) $row['type'],
			'status'           => $status,
			'step'             => (string) ( $row['current_step'] ?? ( $cursor['phase'] ?? '' ) ),
			'progress'         => (int) $row['progress'],
			'attempt'          => (int) ( $row['attempt_count'] ?? 0 ),
			'max_attempts'     => (int) ( $row['max_attempts'] ?? RetryPolicy::DEFAULT_MAX_ATTEMPTS ),
			'available_at'     => $row['available_at'] ?? null,
			'started'          => $row['started_at'] ?? null,
			'completed'        => $row['completed_at'] ?? null,
			'heartbeat'        => $row['heartbeat_at'] ?? null,
			'lease_owner'      => (string) ( $row['lease_owner'] ?? '' ),
			'lease_expires'    => $row['lease_expires_at'] ?? null,
			'runner'           => (string) ( $row['runner'] ?? '' ),
			'pause_requested'  => ! empty( $row['pause_requested'] ),
			'cancel_requested' => ! empty( $row['cancel_requested'] ),
			'processed_items'  => (int) ( $row['processed_items'] ?? 0 ),
			'total_items'      => (int) ( $row['total_items'] ?? 0 ),
			'processed_bytes'  => (int) ( $row['processed_bytes'] ?? 0 ),
			'total_bytes'      => (int) ( $row['total_bytes'] ?? 0 ),
			'cursor'           => is_array( $cursor ) ? $cursor : array(),
			'result'           => is_array( $result ) ? $result : array(),
			'error_code'       => (string) ( $row['error_code'] ?? '' ),
			'error'            => $row['error_message'] ?? null,
			'created'          => $row['created_at'],
			'updated'          => $row['updated_at'],
		);
	}
}

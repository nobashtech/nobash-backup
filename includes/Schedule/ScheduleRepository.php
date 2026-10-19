<?php
/**
 * Durable local backup schedules.
 *
 * @package Nobash\Backup
 */
namespace Nobash\Backup\Schedule;

defined( 'ABSPATH' ) || exit;

final class ScheduleRepository {
	/** @return string */
	private function table() { global $wpdb; return $wpdb->prefix . 'nobash_schedules'; }

	/** @return array<int,array<string,mixed>> */
	public function all() {
		global $wpdb;
		$table = $this->table();
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY enabled DESC,next_run_at ASC,id ASC', $table ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $rows ) ? array_map( array( $this, 'hydrate' ), $rows ) : array();
	}

	/** @return array<string,mixed>|null */
	public function find( $id ) {
		global $wpdb;
		$table = $this->table();
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $table, absint( $id ) ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/** @return array<string,mixed>|null */
	public function find_by_key( $key ) {
		global $wpdb;
		$table = $this->table();
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE schedule_key=%s', $table, sanitize_key( (string) $key ) ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Save a local schedule.
	 *
	 * @param array<string,mixed> $data Input.
	 * @return array<string,mixed>
	 */
	public function save( array $data ) {
		global $wpdb;
		$id = absint( $data['id'] ?? 0 );
		$existing = $id ? $this->find( $id ) : null;
		$key = $existing ? (string) $existing['schedule_key'] : sanitize_key( (string) ( $data['schedule_key'] ?? '' ) );
		if ( '' === $key ) { $key = 'schedule-' . strtolower( wp_generate_password( 10, false, false ) ); }
		$key = substr( $key, 0, 64 );
		$name = sanitize_text_field( (string) ( $data['name'] ?? ( $existing['name'] ?? __( 'Automatic backup', 'nobash-backup' ) ) ) );
		if ( '' === $name ) { throw new \InvalidArgumentException( esc_html__( 'Schedule name is required.', 'nobash-backup' ) ); }

		$recurrence = $this->normalize_recurrence( $data['recurrence'] ?? ( $existing['recurrence'] ?? 'daily' ) );
		$profile_id = absint( $data['profile_id'] ?? ( $existing['profile_id'] ?? 0 ) );
		$profile = $this->normalize_profile( $data['profile'] ?? ( $existing['profile'] ?? 'full' ) );
		if ( $profile_id ) {
			$profile_row = ( new \Nobash\Backup\BackupProfile\ProfileRepository() )->find( $profile_id );
			if ( ! $profile_row || empty( $profile_row['enabled'] ) ) {
				throw new \InvalidArgumentException( esc_html__( 'Choose an enabled backup profile.', 'nobash-backup' ) );
			}
			$profile = (string) $profile_row['content_type'];
		}
		$mode = \Nobash\Backup\Incremental\BackupMode::normalize( $data['backup_mode'] ?? ( $existing['backup_mode'] ?? 'full' ) );
		$clock = $this->normalize_clock( $data['run_time'] ?? $data['time'] ?? ( $existing['run_time'] ?? '02:00' ) );
		$weekday = max( 0, min( 6, (int) ( $data['weekday'] ?? ( $existing['weekday'] ?? 0 ) ) ) );
		$monthday = max( 1, min( 28, (int) ( $data['monthday'] ?? ( $existing['monthday'] ?? 1 ) ) ) );
		$overlap = sanitize_key( (string) ( $data['overlap_policy'] ?? ( $existing['overlap_policy'] ?? 'skip' ) ) );
		if ( ! in_array( $overlap, array( 'skip', 'queue' ), true ) ) { $overlap = 'skip'; }
		$timezone = sanitize_text_field( (string) ( $data['timezone'] ?? ( $existing['timezone'] ?? wp_timezone_string() ) ) );
		try { new \DateTimeZone( $timezone ); } catch ( \Throwable $e ) { $timezone = 'UTC'; }

		$enabled = isset( $data['enabled'] ) ? ! empty( $data['enabled'] ) : ( $existing ? ! empty( $existing['enabled'] ) : true );
		$next = $this->next_time( $recurrence, $clock, $weekday, $monthday, $timezone, time() );
		$row = array(
			'schedule_key'=>$key, 'name'=>$name, 'enabled'=>$enabled ? 1 : 0,
			'recurrence'=>$recurrence, 'profile'=>$profile, 'profile_id'=>$profile_id,
			'backup_mode'=>$mode, 'chain_id'=>sanitize_text_field( (string) ( $data['chain_id'] ?? ( $existing['chain_id'] ?? '' ) ) ),
			'storage_provider'=>'local', 'run_time'=>$clock, 'weekday'=>$weekday, 'monthday'=>$monthday,
			'timezone'=>$timezone, 'overlap_policy'=>$overlap,
			'retention_count'=>isset($data['retention_count']) ? absint($data['retention_count']) : ( $existing['retention_count'] ?? null ),
			'retention_days'=>isset($data['retention_days']) ? absint($data['retention_days']) : ( $existing['retention_days'] ?? null ),
			'next_run_at'=>gmdate( 'Y-m-d H:i:s', $next ), 'lock_expires_at'=>null,
			'updated_at'=>current_time( 'mysql', true ),
		);
		$table = $this->table();
		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->update( $table, $row, array( 'id'=>$id ) );
		} else {
			$row['created_at'] = $row['updated_at'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ok = $wpdb->insert( $table, $row );
			$id = (int) $wpdb->insert_id;
		}
		if ( false === $ok || $id < 1 || ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( esc_html__( 'The backup schedule could not be saved.', 'nobash-backup' ) );
		}
		return $this->find( $id );
	}

	/** Delete a schedule. */
	public function delete( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->delete( $this->table(), array( 'id'=>absint($id) ), array('%d') );
	}

	/** @return array<int,array<string,mixed>> */
	public function due( $limit = 10 ) {
		global $wpdb;
		$table = $this->table();
		$now = current_time( 'mysql', true );
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE enabled=1 AND next_run_at IS NOT NULL AND next_run_at<=%s ORDER BY next_run_at ASC LIMIT %d', $table, $now, max(1,min(50,absint($limit))) ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $rows ) ? array_map( array($this,'hydrate'), $rows ) : array();
	}

	/** Atomically claim one due schedule. */
	public function claim( $id, $expected_next ) {
		global $wpdb;
		$table = $this->table();
		$now = current_time( 'mysql', true );
		$lock = gmdate( 'Y-m-d H:i:s', time() + 10 * MINUTE_IN_SECONDS );
		return 1 === (int) $wpdb->query(
			$wpdb->prepare( 'UPDATE %i SET lock_expires_at=%s,updated_at=%s WHERE id=%d AND next_run_at=%s AND (lock_expires_at IS NULL OR lock_expires_at<%s)', $table, $lock,$now,absint($id),(string)$expected_next,$now )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/** Record dispatch and calculate the next wall-clock run. */
	public function mark_dispatched( array $schedule, $job_id ) {
		global $wpdb;
		$next = $this->next_time( $schedule['recurrence'], $schedule['run_time'], (int)$schedule['weekday'], (int)$schedule['monthday'], $schedule['timezone'], max(time(), strtotime((string)$schedule['next_run_at'].' UTC')) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $this->table(), array(
			'last_run_at'=>current_time('mysql',true), 'last_job_id'=>absint($job_id), 'last_status'=>'queued',
			'last_error'=>'', 'next_run_at'=>gmdate('Y-m-d H:i:s',$next), 'lock_expires_at'=>null,
			'updated_at'=>current_time('mysql',true),
		), array('id'=>absint($schedule['id'])) );
	}

	/** Record a user-triggered run without moving the automatic next-run time. */
	public function mark_manual_dispatched( $schedule_id, $job_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $this->table(), array(
			'last_run_at'=>current_time('mysql',true), 'last_job_id'=>absint($job_id), 'last_status'=>'queued_manual',
			'last_error'=>'', 'lock_expires_at'=>null, 'updated_at'=>current_time('mysql',true),
		), array('id'=>absint($schedule_id)) );
	}

	/** Record final/dispatch state. */
	public function mark_status( $schedule_id, $status, $error = '' ) {
		global $wpdb;
		if ( $schedule_id < 1 ) { return; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $this->table(), array(
			'last_status'=>sanitize_key((string)$status), 'last_error'=>sanitize_text_field((string)$error),
			'lock_expires_at'=>null, 'updated_at'=>current_time('mysql',true),
		), array('id'=>absint($schedule_id)) );
	}

	/** @param array<string,mixed> $row Row. @return array<string,mixed> */
	private function hydrate( array $row ) {
		foreach ( array('id','profile_id','weekday','monthday','last_job_id') as $key ) { $row[$key] = isset($row[$key]) ? (int)$row[$key] : 0; }
		$row['enabled'] = ! empty($row['enabled']);
		$row['next_run_ts'] = ! empty($row['next_run_at']) ? ( strtotime((string)$row['next_run_at'].' UTC') ?: 0 ) : 0;
		$row['run_time'] = ! empty($row['run_time']) ? (string)$row['run_time'] : '02:00';
		$row['timezone'] = ! empty($row['timezone']) ? (string)$row['timezone'] : 'UTC';
		$row['backup_mode'] = \Nobash\Backup\Incremental\BackupMode::normalize($row['backup_mode'] ?? 'full');
		return $row;
	}

	/** @return string */
	private function normalize_recurrence( $value ) {
		$value = sanitize_key( (string) $value );
		$map = array( 'nobash_weekly'=>'weekly', 'nobash_monthly'=>'monthly', 'twicedaily'=>'twicedaily' );
		$value = $map[$value] ?? $value;
		return in_array($value,array('hourly','twicedaily','daily','weekly','monthly'),true) ? $value : 'daily';
	}
	/** @return string */
	private function normalize_profile( $value ) {
		$value=sanitize_key((string)$value);
		return in_array($value,array('full','database','files','uploads','plugins','themes','custom'),true)?$value:'full';
	}
	/** @return string */
	private function normalize_clock( $value ) {
		$value=(string)$value;
		return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$value)?$value:'02:00';
	}

	/** Calculate the next run in the selected site timezone, then store UTC. */
	private function next_time( $recurrence, $clock, $weekday, $monthday, $timezone, $after ) {
		if ( 'hourly' === $recurrence ) { return (int)$after + HOUR_IN_SECONDS; }
		if ( 'twicedaily' === $recurrence ) { return (int)$after + 12 * HOUR_IN_SECONDS; }
		$tz = new \DateTimeZone($timezone ?: 'UTC');
		$after_local = (new \DateTimeImmutable('@'.(int)$after))->setTimezone($tz);
		list($hour,$minute)=array_map('intval',explode(':',$clock));
		$candidate=$after_local->setTime($hour,$minute,0);
		if('daily'===$recurrence){if($candidate->getTimestamp()<=$after)$candidate=$candidate->modify('+1 day');return$candidate->getTimestamp();}
		if('weekly'===$recurrence){while((int)$candidate->format('w')!==$weekday||$candidate->getTimestamp()<=$after){$candidate=$candidate->modify('+1 day');}return$candidate->getTimestamp();}
		$candidate=$candidate->setDate((int)$candidate->format('Y'),(int)$candidate->format('m'),$monthday);
		if($candidate->getTimestamp()<=$after){$next=$candidate->modify('first day of next month');$candidate=$next->setDate((int)$next->format('Y'),(int)$next->format('m'),$monthday)->setTime($hour,$minute,0);}
		return$candidate->getTimestamp();
	}
}

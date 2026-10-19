<?php
/**
 * Reusable local backup profiles.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\BackupProfile;

defined( 'ABSPATH' ) || exit;

/** Store and validate Core backup profiles. */
final class ProfileRepository {

	/** @return string */
	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'nobash_backup_profiles';
	}

	/** @return array<int,array<string,mixed>> */
	public function all() {
		global $wpdb;
		$table = $this->table();
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY is_system DESC, name ASC', $table ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned profile table.
		return is_array( $rows ) ? array_map( array( $this, 'hydrate' ), $rows ) : array();
	}

	/** @return array<string,mixed>|null */
	public function find( $id ) {
		global $wpdb;
		$table = $this->table();
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $table, absint( $id ) ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned profile table.
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/** @return array<string,mixed>|null */
	public function find_by_key( $key ) {
		global $wpdb;
		$table = $this->table();
		$key   = sanitize_key( (string) $key );
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE profile_key=%s', $table, $key ),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned profile table.
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Save a profile.
	 *
	 * @param array<string,mixed> $input Profile input.
	 * @return array<string,mixed>
	 */
	public function save( array $input ) {
		global $wpdb;
		$id       = absint( $input['id'] ?? 0 );
		$existing = $id ? $this->find( $id ) : null;
		if ( $existing && ! empty( $existing['is_system'] ) ) { throw new \RuntimeException( esc_html__( 'Built-in backup profiles are read-only.', 'nobash-backup' ) ); }
		$name     = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( esc_html__( 'Profile name is required.', 'nobash-backup' ) );
		}

		$content = sanitize_key( (string) ( $input['content_type'] ?? $input['type'] ?? 'full' ) );
		$allowed = array( 'full', 'database', 'files', 'uploads', 'plugins', 'themes', 'custom' );
		if ( ! in_array( $content, $allowed, true ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Invalid backup profile content type.', 'nobash-backup' ) );
		}

		$mode = \Nobash\Backup\Incremental\BackupMode::normalize( $input['backup_mode'] ?? 'full' );
		$paths = $this->paths( $input['include_paths'] ?? $input['folders'] ?? array() );
		$tables = $this->tables( $input['include_tables'] ?? $input['tables'] ?? array() );
		$exclusions = $this->paths( $input['exclusions'] ?? array() );
		if ( 'custom' === $content && empty( $paths ) && empty( $tables ) ) {
			throw new \InvalidArgumentException( esc_html__( 'A custom profile needs at least one included path or database table.', 'nobash-backup' ) );
		}

		$key = $existing && ! empty( $existing['profile_key'] )
			? $existing['profile_key']
			: sanitize_key( (string) ( $input['profile_key'] ?? sanitize_title( $name ) ) );
		if ( '' === $key ) {
			$key = 'profile-' . wp_generate_password( 8, false, false );
		}
		$key = substr( $key, 0, 64 );

		$row = array(
			'profile_key'       => $key,
			'name'              => $name,
			'content_type'      => $content,
			'backup_mode'       => $mode,
			'include_paths_json'=> wp_json_encode( $paths ),
			'include_tables_json'=> wp_json_encode( $tables ),
			'exclusions_json'   => wp_json_encode( $exclusions ),
			'enabled'           => isset( $input['enabled'] ) && ! $input['enabled'] ? 0 : 1,
			'is_system'         => $existing ? (int) $existing['is_system'] : 0,
			'updated_at'        => current_time( 'mysql', true ),
		);

		$table = $this->table();
		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $row, array( 'id' => $id ) );
		} else {
			$row['created_at'] = $row['updated_at'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $row );
			$id = (int) $wpdb->insert_id;
		}
		if ( $id < 1 || ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( esc_html__( 'The backup profile could not be saved.', 'nobash-backup' ) );
		}
		return $this->find( $id );
	}

	/** Delete a custom profile. System presets are immutable. */
	public function delete( $id ) {
		global $wpdb;
		$profile = $this->find( $id );
		if ( ! $profile ) {
			return false;
		}
		if ( ! empty( $profile['is_system'] ) ) {
			throw new \RuntimeException( esc_html__( 'Built-in backup profiles cannot be deleted.', 'nobash-backup' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->delete( $this->table(), array( 'id' => absint( $id ) ), array( '%d' ) );
	}

	/** Seed stable built-in profiles. */
	public function ensure_defaults() {
		$defaults = array(
			array( 'profile_key'=>'full', 'name'=>__( 'Full site', 'nobash-backup' ), 'content_type'=>'full', 'backup_mode'=>'full' ),
			array( 'profile_key'=>'database', 'name'=>__( 'Database only', 'nobash-backup' ), 'content_type'=>'database', 'backup_mode'=>'full' ),
			array( 'profile_key'=>'files', 'name'=>__( 'Files only', 'nobash-backup' ), 'content_type'=>'files', 'backup_mode'=>'full' ),
			array( 'profile_key'=>'uploads', 'name'=>__( 'Media uploads', 'nobash-backup' ), 'content_type'=>'uploads', 'backup_mode'=>'full' ),
			array( 'profile_key'=>'plugins', 'name'=>__( 'Plugins only', 'nobash-backup' ), 'content_type'=>'plugins', 'backup_mode'=>'full' ),
			array( 'profile_key'=>'themes', 'name'=>__( 'Themes only', 'nobash-backup' ), 'content_type'=>'themes', 'backup_mode'=>'full' ),
		);
		global $wpdb;
		$table = $this->table();
		foreach ( $defaults as $profile ) {
			if ( $this->find_by_key( $profile['profile_key'] ) ) {
				continue;
			}
			$now = current_time( 'mysql', true );
			$row = array_merge( $profile, array(
				'include_paths_json'=>'[]', 'include_tables_json'=>'[]', 'exclusions_json'=>'[]',
				'enabled'=>1, 'is_system'=>1, 'created_at'=>$now, 'updated_at'=>$now,
			) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $row );
		}
	}

	/** @param array<string,mixed> $row Row. @return array<string,mixed> */
	private function hydrate( array $row ) {
		$row['id'] = (int) $row['id'];
		$row['enabled'] = ! empty( $row['enabled'] );
		$row['is_system'] = ! empty( $row['is_system'] );
		$row['include_paths'] = $this->decode_list( $row['include_paths_json'] ?? '[]' );
		$row['include_tables'] = $this->decode_list( $row['include_tables_json'] ?? '[]' );
		$row['exclusions'] = $this->decode_list( $row['exclusions_json'] ?? '[]' );
		// UI compatibility with an earlier profile payload shape.
		$row['type'] = $row['content_type'];
		$row['folders'] = $row['include_paths'];
		$row['tables'] = $row['include_tables'];
		$row['destinations'] = array();
		unset( $row['include_paths_json'], $row['include_tables_json'], $row['exclusions_json'] );
		return $row;
	}

	/** @return array<int,string> */
	private function decode_list( $json ) {
		$value = json_decode( (string) $json, true );
		return is_array( $value ) ? array_values( array_filter( array_map( 'strval', $value ) ) ) : array();
	}

	/** @return array<int,string> */
	private function paths( $values ) {
		$out = array();
		foreach ( (array) $values as $path ) {
			$path = trim( str_replace( '\\', '/', sanitize_text_field( (string) $path ) ) );
			$path = ltrim( $path, '/' );
			if ( '' === $path || '..' === $path || false !== strpos( $path, '../' ) || false !== strpos( $path, "\0" ) ) {
				continue;
			}
			$out[] = rtrim( $path, '/' );
		}
		return array_values( array_unique( $out ) );
	}

	/** @return array<int,string> */
	private function tables( $values ) {
		$out = array();
		foreach ( (array) $values as $table ) {
			$table = preg_replace( '/[^A-Za-z0-9_$-]/', '', (string) $table );
			if ( '' !== $table ) {
				$out[] = $table;
			}
		}
		return array_values( array_unique( $out ) );
	}
}

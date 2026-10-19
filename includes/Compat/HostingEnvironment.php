<?php
/**
 * Hosting-platform compatibility and resource profiling.
 *
 * The engine deliberately relies on capabilities first and provider markers only
 * for diagnostics/policy guidance. This keeps Nobash Backup portable across cPanel,
 * Plesk, managed WordPress and custom shared-hosting stacks.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Compat;

defined( 'ABSPATH' ) || exit;

/** Runtime hosting compatibility profile. */
final class HostingEnvironment {

	/** @var array<string,mixed>|null */
	private static $snapshot = null;

	/** Return a sanitized, non-secret hosting/runtime snapshot. @return array<string,mixed> */
	public static function snapshot() {
		if ( null !== self::$snapshot ) {
			return self::$snapshot;
		}

		$memory = self::memory_limit_bytes();
		$execution = self::execution_limit_seconds();
		$provider = self::detect_provider();
		$tier = self::resource_tier( $memory, $execution );
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$action_scheduler = function_exists( 'as_schedule_single_action' ) || class_exists( '\ActionScheduler', false );

		self::$snapshot = array(
			'provider'            => $provider['id'],
			'provider_name'       => $provider['name'],
			'provider_confidence' => $provider['confidence'],
			'resource_tier'       => $tier,
			'memory_limit_bytes'  => $memory,
			'execution_limit'     => $execution,
			'php_sapi'            => PHP_SAPI,
			'wp_cron_disabled'    => $cron_disabled,
			'action_scheduler'    => $action_scheduler,
			'filesystem'          => array(
				'flock'    => function_exists( 'flock' ),
				'fsync'    => function_exists( 'fsync' ),
				'hardlink' => function_exists( 'link' ),
				'rename'   => function_exists( 'rename' ),
			),
			'policy'              => self::provider_policy( $provider['id'] ),
		);

		return self::$snapshot;
	}

	/** Reset only for deterministic tests. @return void */
	public static function reset_cache() {
		self::$snapshot = null;
	}

	/**
	 * Choose a request slice that leaves headroom for WordPress shutdown hooks,
	 * reverse proxies and JSON responses on short-lived shared-host requests.
	 *
	 * @param float $requested Requested seconds.
	 * @param int   $maximum   PHP max_execution_time, 0 when unlimited/unknown.
	 * @return float
	 */
	public static function recommended_time_budget( $requested, $maximum = 0 ) {
		$requested = max( 2.0, min( 120.0, (float) $requested ) );
		$maximum = max( 0, (int) $maximum );
		$tier = (string) ( self::snapshot()['resource_tier'] ?? 'balanced' );

		if ( $maximum > 0 ) {
			if ( $maximum <= 15 ) {
				$requested = min( $requested, max( 2.0, $maximum - 3.0 ) );
			} elseif ( $maximum <= 30 ) {
				$requested = min( $requested, max( 5.0, $maximum - 7.0 ) );
			} elseif ( $maximum <= 60 ) {
				$requested = min( $requested, max( 8.0, $maximum - 10.0 ) );
			} else {
				$requested = min( $requested, max( 10.0, $maximum - max( 10.0, $maximum * 0.15 ) ) );
			}
		}

		// Unlimited PHP time does not imply an unlimited HTTP/FastCGI request.
		// Keep low-resource hosts in short durable slices while allowing stronger
		// hosts to honor an explicitly configured larger slice.
		if ( 'constrained' === $tier ) {
			$requested = min( $requested, 10.0 );
		} elseif ( 'shared' === $tier ) {
			$requested = min( $requested, 20.0 );
		}

		return max( 2.0, $requested );
	}

	/** Bound outbound HTTP calls so provider stalls cannot consume an entire host request. @return int */
	public static function recommended_http_timeout( $requested = 120 ) {
		$requested = max( 5, min( 300, (int) $requested ) );
		$snapshot = self::snapshot();
		$maximum = max( 0, (int) ( $snapshot['execution_limit'] ?? 0 ) );
		$tier = (string) ( $snapshot['resource_tier'] ?? 'balanced' );
		if ( $maximum > 0 ) {
			$requested = min( $requested, max( 5, $maximum - max( 4, (int) ceil( $maximum * 0.20 ) ) ) );
		}
		if ( 'constrained' === $tier ) { $requested = min( $requested, 10 ); }
		elseif ( 'shared' === $tier ) { $requested = min( $requested, 20 ); }
		elseif ( 'balanced' === $tier ) { $requested = min( $requested, 45 ); }
		else { $requested = min( $requested, 120 ); }
		return max( 5, (int) $requested );
	}

	/** Suggested number of remote multipart chunks in one worker invocation. @return int */
	public static function remote_parts_per_slice() {
		switch ( (string) ( self::snapshot()['resource_tier'] ?? 'balanced' ) ) {
			case 'constrained': return 1;
			case 'shared':      return 1;
			case 'balanced':    return 2;
			case 'performance': return 4;
			default:            return 1;
		}
	}

	/** Maximum cumulative filesystem work per request for the detected tier. @return int */
	public static function max_file_work_bytes() {
		switch ( (string) ( self::snapshot()['resource_tier'] ?? 'balanced' ) ) {
			case 'constrained': return 134217728;  // 128 MiB.
			case 'shared':      return 536870912;  // 512 MiB.
			case 'balanced':    return 1073741824; // 1 GiB.
			case 'performance': return 1073741824; // Existing engine cap; deadline dominates.
			default:            return 536870912;
		}
	}

	/** Maximum single in-memory archive/file chunk. @return int */
	public static function max_io_chunk_bytes() {
		switch ( (string) ( self::snapshot()['resource_tier'] ?? 'balanced' ) ) {
			case 'constrained': return 4194304;
			case 'shared':      return 8388608;
			default:            return 16777216;
		}
	}

	/**
	 * Maximum request body used by resumable browser and site-to-site transfers.
	 *
	 * Transfer requests hold one complete chunk while WordPress validates its
	 * digest, so this limit is deliberately more conservative than raw PHP
	 * memory. Stronger hosts can still reduce thousands of request bootstraps on
	 * multi-gigabyte archives without making low-memory sites unstable.
	 *
	 * @return int
	 */
	public static function max_transfer_chunk_bytes() {
		switch ( (string) ( self::snapshot()['resource_tier'] ?? 'balanced' ) ) {
			case 'constrained': return 4194304;  // 4 MiB.
			case 'shared':      return 8388608;  // 8 MiB.
			case 'balanced':    return 33554432; // 32 MiB.
			case 'performance': return 67108864; // 64 MiB.
			default:            return 16777216;
		}
	}

	/** Maximum database rows per adaptive page before normal learning. @return int */
	public static function max_db_rows() {
		switch ( (string) ( self::snapshot()['resource_tier'] ?? 'balanced' ) ) {
			case 'constrained': return 250;
			case 'shared':      return 1500;
			case 'balanced':    return 3500;
			case 'performance': return 5000;
			default:            return 1500;
		}
	}

	/** Maximum SQL statement target before server packet-size clamping. @return int */
	public static function max_db_statement_bytes() {
		switch ( (string) ( self::snapshot()['resource_tier'] ?? 'balanced' ) ) {
			case 'constrained': return 1048576;
			case 'shared':      return 4194304;
			default:            return 8388608;
		}
	}

	/** Advisory policy for hosts with known platform restrictions. @return array<string,mixed> */
	public static function provider_policy( $provider = '' ) {
		$provider = sanitize_key( (string) $provider );
		if ( 'kinsta' === $provider ) {
			return array(
				'level' => 'warn',
				'local_backup_sensitive' => true,
				'message' => __( 'This managed host may restrict ongoing backup plugins. Use Nobash Backup primarily for migration/export only when permitted by your hosting plan, and prefer remote/cloud destinations over long local retention.', 'nobash-backup' ),
			);
		}
		if ( 'wpengine' === $provider ) {
			return array(
				'level' => 'warn',
				'local_backup_sensitive' => true,
				'message' => __( 'This managed host discourages backup plugins that create heavy local storage or database load. Nobash Backup will use bounded resumable slices; prefer a remote/cloud destination and confirm the current host policy before scheduled backups.', 'nobash-backup' ),
			);
		}
		return array(
			'level' => 'pass',
			'local_backup_sensitive' => false,
			'message' => '',
		);
	}

	/** @return array{id:string,name:string,confidence:string} */
	private static function detect_provider() {
		// Use provider-owned constants/environment markers when available. Never
		// inspect credentials or expose marker values in diagnostics.
		if ( self::defined_or_env( 'KINSTA_CACHE_ZONE' ) || self::defined_or_env( 'KINSTA_CDN_DOMAIN' ) || self::defined_or_env( 'KINSTA_ENVIRONMENT' ) ) {
			return array( 'id' => 'kinsta', 'name' => 'Kinsta', 'confidence' => 'high' );
		}
		if ( self::defined_or_env( 'WPE_APIKEY' ) || self::defined_or_env( 'WPE_CLUSTER_TYPE' ) || self::defined_or_env( 'PWP_NAME' ) || self::defined_or_env( 'IS_WPE' ) ) {
			return array( 'id' => 'wpengine', 'name' => 'WP Engine', 'confidence' => 'high' );
		}
		if ( self::defined_or_env( 'PANTHEON_ENVIRONMENT' ) || self::defined_or_env( 'PANTHEON_SITE_NAME' ) ) {
			return array( 'id' => 'pantheon', 'name' => 'Pantheon', 'confidence' => 'high' );
		}
		if ( self::defined_or_env( 'GD_SYSTEM_PLUGIN_DIR' ) || self::defined_or_env( 'GD_TEMP_DOMAIN' ) || self::defined_or_env( 'GD_SITE_TOKEN' ) ) {
			return array( 'id' => 'godaddy', 'name' => 'GoDaddy', 'confidence' => 'high' );
		}
		if ( self::defined_or_env( 'HOSTINGER_ABSPATH' ) || self::defined_or_env( 'HOSTINGER_WP_PLUGIN_VERSION' ) ) {
			return array( 'id' => 'hostinger', 'name' => 'Hostinger', 'confidence' => 'high' );
		}
		if ( defined( 'SG_CACHEPRESS_VERSION' ) || defined( 'SG_OPTIMIZER_VERSION' ) || class_exists( '\SiteGround_Optimizer\Loader\Loader', false ) ) {
			return array( 'id' => 'siteground', 'name' => 'SiteGround', 'confidence' => 'medium' );
		}
		if ( self::defined_or_env( 'PRESSABLE_SITE_ID' ) || self::defined_or_env( 'PRESSABLE_ENVIRONMENT' ) ) {
			return array( 'id' => 'pressable', 'name' => 'Pressable', 'confidence' => 'high' );
		}

		return array( 'id' => 'generic', 'name' => __( 'Generic / capability-based', 'nobash-backup' ), 'confidence' => 'capability' );
	}

	/** @return bool */
	private static function defined_or_env( $name ) {
		if ( defined( $name ) ) {
			$value = constant( $name );
			return is_scalar( $value ) && false !== $value && null !== $value && '' !== (string) $value;
		}
		$value = getenv( (string) $name );
		return false !== $value && '' !== trim( (string) $value );
	}

	/** @return string */
	private static function resource_tier( $memory, $execution ) {
		$memory = (int) $memory;
		$execution = (int) $execution;
		if ( ( $memory > 0 && $memory <= 100663296 ) || ( $execution > 0 && $execution <= 15 ) ) {
			return 'constrained';
		}
		if ( ( $memory > 0 && $memory <= 201326592 ) || ( $execution > 0 && $execution <= 30 ) ) {
			return 'shared';
		}
		if ( ( $memory > 0 && $memory <= 536870912 ) || ( $execution > 0 && $execution <= 60 ) ) {
			return 'balanced';
		}
		return 'performance';
	}

	/** @return int 0 means unlimited/unknown. */
	private static function memory_limit_bytes() {
		$value = trim( (string) ini_get( 'memory_limit' ) );
		if ( '' === $value || '-1' === $value ) { return 0; }
		$unit = strtolower( substr( $value, -1 ) );
		$number = (float) $value;
		if ( 'g' === $unit ) { return (int) floor( $number * 1073741824 ); }
		if ( 'm' === $unit ) { return (int) floor( $number * 1048576 ); }
		if ( 'k' === $unit ) { return (int) floor( $number * 1024 ); }
		return max( 0, (int) $number );
	}

	/** @return int */
	private static function execution_limit_seconds() {
		$value = ini_get( 'max_execution_time' );
		return false === $value ? 0 : max( 0, (int) $value );
	}
}

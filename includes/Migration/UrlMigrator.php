<?php
/**
 * Build URL search/replace pairs for migrations.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Class UrlMigrator
 */
class UrlMigrator {

	/**
	 * Build ordered search → replace pairs so paths like /service are preserved.
	 *
	 * Example: https://example.com/service → https://exampletwo.com/service
	 * when searching for https://example.com and replacing with https://exampletwo.com.
	 *
	 * @param string $from_home    Source home URL.
	 * @param string $to_home      Target home URL.
	 * @param string $from_siteurl Optional source siteurl.
	 * @param string $to_siteurl   Optional target siteurl.
	 * @return array<int, array{0:string,1:string}>
	 */
	public static function pairs( $from_home, $to_home, $from_siteurl = '', $to_siteurl = '' ) {
		$pairs = array();

		$from_home = untrailingslashit( (string) $from_home );
		$to_home   = untrailingslashit( (string) $to_home );
		if ( '' !== $from_home && '' !== $to_home && $from_home !== $to_home ) {
			foreach ( self::variants( $from_home ) as $from ) {
				$to      = self::map_variant( $from, $to_home );
				$pairs[] = array( $from, $to );
				$pairs[] = array( trailingslashit( $from ), trailingslashit( $to ) );
			}
		}

		$from_siteurl = untrailingslashit( (string) $from_siteurl );
		$to_siteurl   = untrailingslashit( (string) $to_siteurl );
		if (
			'' !== $from_siteurl
			&& '' !== $to_siteurl
			&& $from_siteurl !== $to_siteurl
			&& $from_siteurl !== $from_home
		) {
			foreach ( self::variants( $from_siteurl ) as $from ) {
				$to      = self::map_variant( $from, $to_siteurl );
				$pairs[] = array( $from, $to );
				$pairs[] = array( trailingslashit( $from ), trailingslashit( $to ) );
			}
		}

		// Longest search strings first so nested paths replace cleanly.
		usort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( $b[0] ) <=> strlen( $a[0] );
			}
		);

		$out  = array();
		$seen = array();
		foreach ( $pairs as $pair ) {
			$key = $pair[0];
			if ( isset( $seen[ $key ] ) || $pair[0] === $pair[1] || '' === $pair[0] ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $pair;
		}

		/**
		 * Filter URL migration pairs.
		 *
		 * @param array<int, array{0:string,1:string}> $out       Pairs.
		 * @param string                               $from_home Source home.
		 * @param string                               $to_home   Target home.
		 */
		return (array) apply_filters( 'nobash_backup_url_migration_pairs', $out, $from_home, $to_home );
	}

	/**
	 * Scheme / www / encoded variants of a URL.
	 *
	 * @param string $url URL.
	 * @return array<int, string>
	 */
	private static function variants( $url ) {
		$url  = untrailingslashit( $url );
		$list = array( $url );

		if ( 0 === strpos( $url, 'https://' ) ) {
			$list[] = 'http://' . substr( $url, 8 );
		} elseif ( 0 === strpos( $url, 'http://' ) ) {
			$list[] = 'https://' . substr( $url, 7 );
		}

		$extra = array();
		foreach ( $list as $u ) {
			if ( preg_match( '#^(https?://)www\.(.+)$#i', $u, $m ) ) {
				$extra[] = $m[1] . $m[2];
			} elseif ( preg_match( '#^(https?://)([^/].+)$#i', $u, $m ) ) {
				$extra[] = $m[1] . 'www.' . $m[2];
			}
		}
		$list = array_merge( $list, $extra );

		$encoded = array();
		foreach ( $list as $u ) {
			$encoded[] = str_replace( '/', '\/', $u );
		}

		return array_values( array_unique( array_merge( $list, $encoded ) ) );
	}

	/**
	 * Map a from-variant onto the target base while preserving scheme/www/escape style.
	 *
	 * @param string $from_variant Variant of source.
	 * @param string $to_base      Canonical target.
	 * @return string
	 */
	private static function map_variant( $from_variant, $to_base ) {
		$to = untrailingslashit( $to_base );

		$from_https = ( 0 === strpos( $from_variant, 'https://' ) || 0 === strpos( $from_variant, 'https:\/\/' ) );
		$to_https   = ( 0 === strpos( $to, 'https://' ) );
		if ( $from_https && ! $to_https && 0 === strpos( $to, 'http://' ) ) {
			$to = 'https://' . substr( $to, 7 );
		} elseif ( ! $from_https && $to_https && ( 0 === strpos( $from_variant, 'http://' ) || 0 === strpos( $from_variant, 'http:\/\/' ) ) ) {
			$to = 'http://' . substr( $to, 8 );
		}

		$from_www = (bool) preg_match( '#^https?(:\/\/|://)www\.#i', $from_variant );
		$to_www   = (bool) preg_match( '#^https?://www\.#i', $to );
		if ( $from_www && ! $to_www ) {
			$to = preg_replace( '#^(https?://)#i', '$1www.', $to, 1 );
		} elseif ( ! $from_www && $to_www ) {
			$to = preg_replace( '#^(https?://)www\.#i', '$1', $to, 1 );
		}

		if ( false !== strpos( $from_variant, '\/' ) ) {
			$to = str_replace( '/', '\/', $to );
		}

		return untrailingslashit( (string) $to );
	}
}

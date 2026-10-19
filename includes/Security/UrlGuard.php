<?php
/** Outbound URL safety and SSRF prevention. @package Nobash\Backup */
namespace Nobash\Backup\Security;
defined( 'ABSPATH' ) || exit;
class UrlGuard {
	/** @return true|\WP_Error */
	public static function assert_public_url( $url, $allow_http = true ) {
		$url = trim( (string) $url );
		if ( '' === $url || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $url ) ) return self::error( __( 'Invalid URL.', 'nobash-backup' ) );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) return self::error( __( 'Invalid URL.', 'nobash-backup' ) );
		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'https' !== $scheme && ! ( $allow_http && 'http' === $scheme ) ) return self::error( __( 'Only http(s) URLs are allowed.', 'nobash-backup' ) );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		if ( ! in_array( $port, array( 80, 443 ), true ) ) return self::error( __( 'Only standard web ports are allowed.', 'nobash-backup' ) );
		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( '' === $host || strlen( $host ) > 253 || in_array( $host, array( 'localhost', 'metadata.google.internal', 'metadata', 'instance-data' ), true ) || false !== strpos( $host, '%' ) ) return self::error( __( 'That host is not allowed.', 'nobash-backup' ) );
		$ips = self::resolve_ips( $host );
		if ( empty( $ips ) ) return self::error( __( 'The remote host could not be resolved safely.', 'nobash-backup' ) );
		foreach ( $ips as $ip ) if ( self::is_private_ip( $ip ) ) return self::error( __( 'Private or reserved IP addresses are not allowed.', 'nobash-backup' ) );
		return true;
	}
	/** @return array<int,string> */
	public static function resolve_public_ips( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) return array();
		$ips = self::resolve_ips( strtolower( rtrim( (string) $parts['host'], '.' ) ) );
		return array_values( array_filter( $ips, static function( $ip ){ return ! self::is_private_ip( $ip ); } ) );
	}
	private static function error( $message ) { return new \WP_Error( 'nobash_ssrf', $message ); }
	/** @return array<int,string> */
	private static function resolve_ips( $host ) {
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) return array( $host );
		$records = array();
		if ( function_exists( 'dns_get_record' ) ) {
			foreach ( array( DNS_A, DNS_AAAA ) as $type ) {
				$rows = @dns_get_record( $host, $type ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_array( $rows ) ) foreach ( $rows as $row ) { if ( ! empty( $row['ip'] ) ) $records[]=$row['ip']; if ( ! empty( $row['ipv6'] ) ) $records[]=$row['ipv6']; }
			}
		}
		if ( empty( $records ) ) { $ip=gethostbyname($host); if ( $ip !== $host && filter_var($ip,FILTER_VALIDATE_IP) ) $records[]=$ip; }
		return array_values( array_unique( $records ) );
	}
	private static function is_private_ip( $ip ) { return ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ); }
}

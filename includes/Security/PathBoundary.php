<?php
/**
 * Safe filesystem path resolution beneath a trusted root.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Security;

defined( 'ABSPATH' ) || exit;

final class PathBoundary {
	/**
	 * Resolve a relative path under a trusted directory.
	 *
	 * Existing symbolic-link components are rejected. Missing descendants are
	 * allowed only after their closest existing parent has been verified.
	 *
	 * @param string $root Trusted existing directory.
	 * @param string $relative            Relative path.
	 * @param bool   $reject_root_symlink Reject a symlinked trusted root.
	 * @return string
	 */
	public static function resolve( $root, $relative, $reject_root_symlink = false ) {
		if ( $reject_root_symlink && is_link( $root ) ) {
			throw new \RuntimeException( esc_html__( 'The restore destination root cannot be a symbolic link.', 'nobash-backup' ) );
		}
		$root = realpath( $root );
		if ( false === $root || ! is_dir( $root ) ) {
			throw new \RuntimeException( esc_html__( 'The restore destination root is unavailable.', 'nobash-backup' ) );
		}

		$relative = self::relative( $relative );
		$current  = rtrim( $root, DIRECTORY_SEPARATOR );
		$parts    = explode( '/', $relative );
		$last     = count( $parts ) - 1;

		foreach ( $parts as $index => $part ) {
			$next = $current . DIRECTORY_SEPARATOR . $part;
			if ( is_link( $next ) ) {
				throw new \RuntimeException( esc_html__( 'Restore blocked because the destination contains a symbolic link.', 'nobash-backup' ) );
			}

			if ( file_exists( $next ) ) {
				$resolved = realpath( $next );
				if ( false === $resolved || ! self::contains( $root, $resolved ) ) {
					throw new \RuntimeException( esc_html__( 'Restore destination escaped the WordPress installation.', 'nobash-backup' ) );
				}
				if ( $index < $last && ! is_dir( $resolved ) ) {
					throw new \RuntimeException( esc_html__( 'A restore destination parent is not a directory.', 'nobash-backup' ) );
				}
				$current = $resolved;
				continue;
			}

			$current = $next;
		}

		if ( ! self::contains( $root, $current ) ) {
			throw new \RuntimeException( esc_html__( 'Restore destination escaped the WordPress installation.', 'nobash-backup' ) );
		}
		return $current;
	}

	/** @return string */
	private static function relative( $relative ) {
		$relative = str_replace( '\\', '/', trim( (string) $relative ) );
		$relative = ltrim( $relative, '/' );
		if ( '' === $relative || false !== strpos( $relative, "\0" ) || preg_match( '#^[A-Za-z]:/#', $relative ) || preg_match( '#(^|/)\.\.(/|$)#', $relative ) ) {
			throw new \RuntimeException( esc_html__( 'Invalid restore destination path.', 'nobash-backup' ) );
		}

		$parts = array();
		foreach ( explode( '/', $relative ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				throw new \RuntimeException( esc_html__( 'Invalid restore destination path.', 'nobash-backup' ) );
			}
			$parts[] = $part;
		}
		if ( ! $parts ) {
			throw new \RuntimeException( esc_html__( 'Invalid restore destination path.', 'nobash-backup' ) );
		}
		return implode( '/', $parts );
	}

	/** @return bool */
	private static function contains( $root, $candidate ) {
		$root      = rtrim( str_replace( '\\', '/', (string) $root ), '/' );
		$candidate = rtrim( str_replace( '\\', '/', (string) $candidate ), '/' );
		if ( DIRECTORY_SEPARATOR === '\\' ) {
			$root      = strtolower( $root );
			$candidate = strtolower( $candidate );
		}
		return $candidate === $root || 0 === strpos( $candidate . '/', $root . '/' );
	}
}

<?php
/**
 * Symlink-safe recursive removal beneath an explicitly trusted parent.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Remove plugin-owned trees without following symbolic links.
 */
final class SafeTreeRemoval {

	/**
	 * Remove a complete directory tree.
	 *
	 * The root must be a real directory below the trusted parent. A root symlink
	 * is rejected. Descendant symlinks are unlinked without following targets.
	 *
	 * @param string $root           Directory to remove.
	 * @param string $trusted_parent Existing trusted parent directory.
	 * @return bool True when the directory no longer exists.
	 */
	public static function remove( $root, $trusted_parent ) {
		$context = self::context( $root, $trusted_parent );
		if ( null === $context ) {
			return true;
		}

		$count = 0;
		self::remove_node( $context['root'], $context['root'], PHP_FLOAT_MAX, PHP_INT_MAX, $count, true );
		return ! file_exists( $context['root'] ) && ! is_link( $context['root'] );
	}

	/**
	 * Remove a bounded slice from a directory tree.
	 *
	 * @param string $root           Directory to remove.
	 * @param string $trusted_parent Existing trusted parent directory.
	 * @param float  $deadline       microtime(true) deadline.
	 * @param int    $limit          Maximum filesystem entries to remove.
	 * @return array{complete:bool,removed:int}
	 */
	public static function remove_slice( $root, $trusted_parent, $deadline, $limit ) {
		$context = self::context( $root, $trusted_parent );
		if ( null === $context ) {
			return array( 'complete' => true, 'removed' => 0 );
		}

		$count = 0;
		self::remove_node(
			$context['root'],
			$context['root'],
			(float) $deadline,
			max( 1, (int) $limit ),
			$count,
			true
		);

		return array(
			'complete' => ! file_exists( $context['root'] ) && ! is_link( $context['root'] ),
			'removed'  => $count,
		);
	}

	/**
	 * Validate root ownership and return normalized paths.
	 *
	 * @param string $root           Root directory.
	 * @param string $trusted_parent Trusted parent.
	 * @return array{root:string,parent:string}|null
	 */
	private static function context( $root, $trusted_parent ) {
		$root           = self::normalize( $root );
		$trusted_parent = realpath( (string) $trusted_parent );
		if ( false === $trusted_parent || ! is_dir( $trusted_parent ) || is_link( $trusted_parent ) ) {
			throw new \RuntimeException( esc_html__( 'The trusted cleanup parent is unavailable or unsafe.', 'nobash-backup' ) );
		}
		$trusted_parent = self::normalize( $trusted_parent );

		if ( '' === $root || $root === $trusted_parent || ! self::contains( $trusted_parent, $root ) ) {
			throw new \RuntimeException( esc_html__( 'The cleanup path is outside its trusted directory.', 'nobash-backup' ) );
		}
		if ( is_link( $root ) ) {
			throw new \RuntimeException( esc_html__( 'The cleanup root is a symbolic link and was not followed.', 'nobash-backup' ) );
		}
		if ( ! file_exists( $root ) ) {
			return null;
		}
		if ( ! is_dir( $root ) ) {
			throw new \RuntimeException( esc_html__( 'The cleanup root is not a directory.', 'nobash-backup' ) );
		}

		$resolved = realpath( $root );
		if ( false === $resolved || ! self::contains( $trusted_parent, self::normalize( $resolved ) ) ) {
			throw new \RuntimeException( esc_html__( 'The cleanup root escaped its trusted directory.', 'nobash-backup' ) );
		}

		return array(
			'root'   => self::normalize( $resolved ),
			'parent' => $trusted_parent,
		);
	}

	/**
	 * Recursively remove a node without following symlinks.
	 *
	 * @param string $path     Current path.
	 * @param string $root     Verified cleanup root.
	 * @param float  $deadline Deadline.
	 * @param int    $limit    Removal limit.
	 * @param int    $count    Removed entries.
	 * @param bool   $is_root  Whether current path is the root.
	 * @return void
	 */
	private static function remove_node( $path, $root, $deadline, $limit, &$count, $is_root = false ) {
		if ( $count >= $limit || microtime( true ) >= $deadline ) {
			return;
		}
		if ( ! self::contains( $root, $path ) ) {
			throw new \RuntimeException( esc_html__( 'A cleanup path escaped its verified root.', 'nobash-backup' ) );
		}

		$stat = lstat( $path );
		if ( false === $stat ) {
			return;
		}

		$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();

		if ( is_link( $path ) ) {
			if ( $is_root ) {
				throw new \RuntimeException( esc_html__( 'The cleanup root changed into a symbolic link.', 'nobash-backup' ) );
			}
			if ( ! $wp_filesystem->delete( $path, false, 'f' ) ) {
				throw new \RuntimeException( esc_html__( 'A symbolic link could not be removed safely.', 'nobash-backup' ) );
			}
			++$count;
			return;
		}

		if ( ! is_dir( $path ) ) {
			if ( ! $wp_filesystem->delete( $path, false, 'f' ) ) {
				throw new \RuntimeException( esc_html__( 'A file could not be removed safely.', 'nobash-backup' ) );
			}
			++$count;
			return;
		}

		$resolved = realpath( $path );
		if ( false === $resolved || ! self::contains( $root, self::normalize( $resolved ) ) ) {
			throw new \RuntimeException( esc_html__( 'A cleanup directory escaped its verified root.', 'nobash-backup' ) );
		}

		$items = scandir( $path );
		if ( false === $items ) {
			throw new \RuntimeException( esc_html__( 'A cleanup directory could not be read.', 'nobash-backup' ) );
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			self::remove_node( $path . DIRECTORY_SEPARATOR . $item, $root, $deadline, $limit, $count, false );
			if ( $count >= $limit || microtime( true ) >= $deadline ) {
				return;
			}
		}

		if ( is_link( $path ) ) {
			throw new \RuntimeException( esc_html__( 'A cleanup directory changed into a symbolic link.', 'nobash-backup' ) );
		}
		if ( ! $wp_filesystem->rmdir( $path ) ) {
			// A bounded pass may leave children for the next slice.
			if ( $count >= $limit || microtime( true ) >= $deadline ) {
				return;
			}
			throw new \RuntimeException( esc_html__( 'A cleanup directory could not be removed.', 'nobash-backup' ) );
		}
		++$count;
	}

	/** @return string */
	private static function normalize( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		return rtrim( $path, '/' );
	}

	/** @return bool */
	private static function contains( $root, $candidate ) {
		$root      = self::normalize( $root );
		$candidate = self::normalize( $candidate );
		if ( DIRECTORY_SEPARATOR === '\\' ) {
			$root      = strtolower( $root );
			$candidate = strtolower( $candidate );
		}
		return $candidate === $root || 0 === strpos( $candidate . '/', $root . '/' );
	}
}

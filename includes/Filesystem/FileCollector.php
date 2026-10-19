<?php
/**
 * Collect files for backup based on profile.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Filesystem;

defined( 'ABSPATH' ) || exit;

/**
 * Class FileCollector
 */
class FileCollector {

	/**
	 * Collect file list for a profile.
	 *
	 * @param string $profile Profile key.
	 * @return array<int, string> Absolute paths.
	 */
	public function collect( $profile, $scope = 'site' ) {
		$roots = $this->roots( $profile, $scope );
		$files = array();

		foreach ( $roots as $root ) {
			if ( ! is_dir( $root ) && is_file( $root ) ) {
				if ( ! Exclusions::is_excluded( $root ) ) {
					$files[] = $root;
				}
				continue;
			}
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				/** @var \SplFileInfo $file */
				if ( ! $file->isFile() ) {
					continue;
				}
				$path = $file->getPathname();
				if ( Exclusions::is_excluded( $path ) ) {
					continue;
				}
				$files[] = $path;
			}
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Roots for profile.
	 *
	 * @param string $profile Profile.
	 * @param string $scope   site|network.
	 * @return array<int, string>
	 */
	public function roots( $profile, $scope = 'site' ) {
		switch ( $profile ) {
			case 'database':
				return array();
			case 'uploads':
				return \Nobash\Backup\Compat\Multisite::upload_roots( $scope );
			case 'plugins':
				return array( \Nobash\Backup\Compat\Paths::plugins_root() );
			case 'themes':
				return array( get_theme_root() );
			case 'files':
			case 'full':
			default:
				return $this->portable_site_roots();
		}
	}

	/**
	 * Relative path from the current WordPress installation root.
	 *
	 * @param string $absolute Absolute path.
	 * @return string
	 */
	public function relative( $absolute ) {
		$relative = \Nobash\Backup\Compat\Paths::archive_relative( $absolute );
		if ( '' === $relative && untrailingslashit( wp_normalize_path( (string) $absolute ) ) !== untrailingslashit( wp_normalize_path( \Nobash\Backup\Compat\Paths::wordpress_root() ) ) ) {
			throw new \RuntimeException( esc_html__( 'A backup source is outside the supported WordPress-managed directories.', 'nobash-backup' ) );
		}
		return $relative;
	}

	/** Include custom external uploads/plugins/themes roots without rescanning nested paths. */
	private function portable_site_roots() {
		$candidates = array(
			\Nobash\Backup\Compat\Paths::wordpress_root(),
			\Nobash\Backup\Compat\Paths::uploads_root(),
			\Nobash\Backup\Compat\Paths::plugins_root(),
			get_theme_root(),
		);
		$roots = array();
		foreach ( $candidates as $candidate ) {
			$candidate = untrailingslashit( wp_normalize_path( (string) $candidate ) );
			if ( '' === $candidate || ! is_dir( $candidate ) ) { continue; }
			$covered = false;
			foreach ( $roots as $root ) {
				if ( $candidate === $root || 0 === strpos( $candidate . '/', trailingslashit( $root ) ) ) { $covered = true; break; }
			}
			if ( ! $covered ) { $roots[] = $candidate; }
		}
		return $roots;
	}
}

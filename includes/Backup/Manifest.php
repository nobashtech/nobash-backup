<?php
/**
 * Archive manifest builder.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Backup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Manifest
 */
class Manifest {

	/**
	 * Build base manifest array.
	 *
	 * @param string               $profile Backup profile.
	 * @param array<string, mixed> $extra   Extra fields.
	 * @return array<string, mixed>
	 */
	public static function build( $profile, array $extra = array() ) {
		global $wp_version, $wpdb;

		$manifest = array_merge(
			array(
				'format'       => 'nbh',
				'format_version' => '1.0',
				'plugin'       => 'nobash-backup',
				'plugin_version' => NOBASH_BACKUP_VERSION,
				'created_at'   => gmdate( 'c' ),
				'profile'      => $profile,
				'siteurl'      => get_option( 'siteurl' ),
				'home'         => get_option( 'home' ),
				'table_prefix' => $wpdb->prefix,
				'wp_version'   => $wp_version,
				'php_version'  => PHP_VERSION,
				'site_root'    => \Nobash\Backup\Compat\Paths::wordpress_root(),
				'uploads_root' => \Nobash\Backup\Compat\Paths::uploads_root(),
				'plugins_root' => \Nobash\Backup\Compat\Paths::plugins_root(),
				'themes_root'  => untrailingslashit( wp_normalize_path( get_theme_root() ) ),
				'checksums'    => array(),
				'files_count'  => 0,
				'tables'       => array(),
			),
			$extra
		);

		/**
		 * Filter the backup manifest.
		 *
		 * @param array<string, mixed> $manifest Manifest.
		 */
		return (array) apply_filters( 'nobash_backup_manifest', $manifest );
	}

	/**
	 * Write manifest.json to a directory.
	 *
	 * @param string               $dir      Directory.
	 * @param array<string, mixed> $manifest Manifest.
	 * @return string Path written.
	 */
	public static function write( $dir, array $manifest ) {
		$path = trailingslashit( $dir ) . 'manifest.json';
		$contents = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === \Nobash\Backup\Filesystem\DirectFilesystem::get()->put_contents( $path, (string) $contents, 0640 ) ) {
			throw new \RuntimeException( esc_html__( 'Could not write the backup manifest.', 'nobash-backup' ) );
		}
		return $path;
	}

	/**
	 * Read and validate manifest from a directory.
	 *
	 * @param string $dir Directory containing manifest.json.
	 * @return array<string, mixed>
	 */
	public static function read( $dir ) {
		$path = trailingslashit( $dir ) . 'manifest.json';
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( esc_html__( 'Invalid archive: missing manifest.', 'nobash-backup' ) );
		}
		$raw = \Nobash\Backup\Filesystem\DirectFilesystem::get()->get_contents( $path );
		if ( false === $raw ) {
			throw new \RuntimeException( esc_html__( 'Invalid archive: manifest could not be read.', 'nobash-backup' ) );
		}
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) || empty( $data['format'] ) || ! in_array( $data['format'], array( 'nbh', 'NBHSTRM2' ), true ) ) {
			throw new \RuntimeException( esc_html__( 'Invalid archive: bad manifest format.', 'nobash-backup' ) );
		}
		return $data;
	}
}

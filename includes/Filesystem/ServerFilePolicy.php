<?php
/** Host-owned configuration and logs are not portable site data. @package Nobash\Backup */
namespace Nobash\Backup\Filesystem;

defined( 'ABSPATH' ) || exit;

/** Shared export/import policy; custom site files and .htaccess remain eligible. */
class ServerFilePolicy {

	/** Match PHP host configuration at any directory depth. @return bool */
	public static function is_php_configuration( $path ) {
		$name = strtolower( basename( str_replace( '\\', '/', (string) $path ) ) );
		$configured = (string) ini_get( 'user_ini.filename' );
		return in_array( $name, array( '.user.ini', 'user.ini', 'php.ini', '.php.ini', 'php5.ini', 'php7.ini', 'php8.ini' ), true )
			|| ( '' !== $configured && basename( $configured ) === $configured && strtolower( $configured ) === $name );
	}

	/** Exact host filenames only: never exclude arbitrary .ini, .conf or app directories. @return bool */
	public static function is_excluded( $path ) {
		$name = strtolower( basename( str_replace( '\\', '/', (string) $path ) ) );
		if ( self::is_php_configuration( $path ) ) { return true; }
		if ( in_array( $name, array( '.htpasswd', '.htgroup', 'nginx.conf', 'httpd.conf', 'apache2.conf', 'web.config', 'php-fpm.conf', '.ftpquota' ), true ) ) { return true; }
		// Include numbered/compressed rotations of known server logs, not arbitrary application data.
		if ( preg_match( '/^(?:error_log|access_log|php_errorlog|error\.log|access\.log|debug\.log|php_errors?\.log|php-fpm\.log|stderr\.log)(?:\.\d+)?(?:\.gz)?$/D', $name ) ) { return true; }
		// A failed recovery may leave a private, adjacent working copy of a host configuration.
		if ( preg_match( '/^(.+)\.nbh-(?:rollback|restore)-[^\/]+\.part$/D', $name, $matches ) ) { return self::is_php_configuration( $matches[1] ); }
		return false;
	}

	/** Archive metadata/database records must always be retained. @return bool */
	public static function retain_archive_path( $path ) {
		return 0 !== strpos( (string) $path, 'files/' ) || ! self::is_excluded( substr( (string) $path, 6 ) );
	}
}

<?php
/**
 * Bounded-memory archive streaming shared by admin and REST downloads.
 *
 * @package Nobash\Backup
 */
namespace Nobash\Backup\Http;

use Nobash\Backup\Security\AuditLog;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ArchiveDownload {
	/**
	 * Send an already-authorized and resolved archive, then terminate the request.
	 *
	 * @param string $path  Resolved local archive path.
	 * @param string $range Single HTTP Range header.
	 * @return WP_Error|void
	 */
	public static function stream( $path, $range = '' ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'nobash_download_unreadable', __( 'The backup archive could not be opened for download.', 'nobash-backup' ), array( 'status' => 500 ) );
		}
		$size = filesize( $path );
		if ( false === $size ) {
			return new WP_Error( 'nobash_download_unreadable', __( 'The backup archive could not be opened for download.', 'nobash-backup' ), array( 'status' => 500 ) );
		}
		try {
			$parsed = ByteRange::parse( trim( $range ), (int) $size );
		} catch ( \OutOfRangeException $e ) {
			header( 'Content-Range: bytes */' . $size );
			status_header( 416 );
			exit;
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem has no seekable, bounded-memory streaming API.
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'nobash_download_open_failed', __( 'The backup archive could not be opened for download.', 'nobash-backup' ), array( 'status' => 500 ) );
		}
		if ( $parsed['start'] > 0 && 0 !== fseek( $handle, $parsed['start'], SEEK_SET ) ) {
			fclose( $handle );
			return new WP_Error( 'nobash_download_seek_failed', __( 'The requested archive range could not be read.', 'nobash-backup' ), array( 'status' => 500 ) );
		}
		AuditLog::record( 'backup_downloaded', 'archive', basename( $path ), array( 'range_start' => $parsed['start'], 'range_end' => $parsed['end'] ) );

		// Remove plugin/theme buffers so they cannot retain or alter the entire archive.
		while ( ob_get_level() > 0 ) {
			$buffer = ob_get_status();
			if ( ! ( $buffer['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE ) || ! ob_end_clean() ) {
				fclose( $handle );
				return new WP_Error( 'nobash_download_buffered', __( 'A server output buffer is preventing the archive download.', 'nobash-backup' ), array( 'status' => 500 ) );
			}
		}
		if ( headers_sent() ) {
			fclose( $handle );
			return new WP_Error( 'nobash_download_headers_sent', __( 'Server output has already started. Reload the page and try again.', 'nobash-backup' ), array( 'status' => 500 ) );
		}
		if ( function_exists( 'ini_set' ) ) {
			ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Binary range responses must not be compressed or change Content-Length.
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Large downloads must not inherit the short PHP execution timeout.
		}
		$filename = sanitize_file_name( basename( $path ) );
		nocache_headers();
		status_header( $parsed['status'] );
		header_remove( 'Content-Encoding' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', "\r", "\n" ), '', $filename ) . '"; filename*=UTF-8\'\'' . rawurlencode( $filename ) );
		header( 'Content-Length: ' . $parsed['length'] );
		header( 'Accept-Ranges: bytes' );
		if ( 206 === $parsed['status'] ) {
			header( 'Content-Range: bytes ' . $parsed['start'] . '-' . $parsed['end'] . '/' . $size );
		}
		header( 'Cache-Control: private, no-store, no-transform' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Accel-Buffering: no' );
		$remaining = $parsed['length'];
		while ( $remaining > 0 && ! feof( $handle ) && ! connection_aborted() ) {
			$chunk = fread( $handle, min( 1024 * 1024, $remaining ) );
			if ( false === $chunk || '' === $chunk ) { break; }
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Exact binary archive bytes, never HTML.
			$remaining -= strlen( $chunk );
			flush();
		}
		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}

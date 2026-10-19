<?php
/** Durable range-based remote archive import. @package Nobash\Backup */
namespace Nobash\Backup\Migration;
use Nobash\Backup\Jobs\PermanentJobException;
use Nobash\Backup\Jobs\RetryableJobException;
use Nobash\Backup\Security\UrlGuard;
use Nobash\Backup\Compat\HostingEnvironment;
defined( 'ABSPATH' ) || exit;
class RemoteImportJob {
	const CHUNK_SIZE = 67108864;

	/** @return array<string,mixed> */
	public function process( array $job, $time_budget ) {
		$c     = is_array( $job['cursor'] ) ? $job['cursor'] : array();
		$phase = (string) ( $c['phase'] ?? 'init' );
		$repo  = new TransferRepository();

		if ( 'init' === $phase ) {
			$url  = esc_url_raw( (string) ( $c['url'] ?? '' ) );
			$safe = UrlGuard::assert_public_url( $url, false );
			if ( is_wp_error( $safe ) ) {
				throw new PermanentJobException( esc_html( $safe->get_error_message() ) );
			}
			$meta = $this->probe( $url );
			$chunk_size = min( self::CHUNK_SIZE, HostingEnvironment::max_transfer_chunk_bytes() );
			if ( empty( $meta['accept_ranges'] ) && (int) $meta['size'] > $chunk_size ) {
				throw new PermanentJobException( esc_html__( 'The remote server does not support resumable byte-range downloads. Use local upload or a host that supports HTTP Range requests.', 'nobash-backup' ) );
			}
			$remote_name = sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
			if ( '' === $remote_name || ! preg_match( '/\.nbh$/i', $remote_name ) ) { $remote_name = 'remote-import.nbh'; }
			$session = $repo->create( $remote_name, (int) $meta['size'], (string) ( $c['sha256'] ?? '' ), 'remote', TransferRepository::recommended_ttl( (int) $meta['size'], 6 * HOUR_IN_SECONDS ) );
			$c['transfer_id']  = $session['transfer_id'];
			$c['resolved_url'] = $meta['url'];
			$c['total_size']   = (int) $meta['size'];
			$c['accept_ranges']= ! empty( $meta['accept_ranges'] );
			$c['chunk_size']   = $chunk_size;
			$c['offset']       = 0;
			$c['phase']        = 'download';
			return $this->response( $c, 2 );
		}

		if ( 'download' === $phase ) {
			$total  = (int) $c['total_size'];
			$offset = (int) $c['offset'];
			if ( $offset >= $total ) {
				$c['phase'] = 'finalize';
				return $this->response( $c, 96 );
			}
			$deadline = microtime( true ) + max( 1.0, (float) $time_budget - 1.0 );
			$slice_bytes = 0;
			$max_slice_bytes = HostingEnvironment::max_file_work_bytes();
			$chunk_size = max( 1048576, min( self::CHUNK_SIZE, (int) ( $c['chunk_size'] ?? HostingEnvironment::max_transfer_chunk_bytes() ) ) );
			/* Keep downloading until the worker deadline instead of paying for one
			 * complete WordPress bootstrap and job checkpoint per range. */
			while ( $offset < $total && $slice_bytes < $max_slice_bytes && microtime( true ) < $deadline ) {
				$length = min( $chunk_size, $total - $offset, $max_slice_bytes - $slice_bytes );
				$timeout = min( HostingEnvironment::recommended_http_timeout( 45 ), max( 5, (int) ceil( $deadline - microtime( true ) ) + 2 ) );
				$chunk = $this->fetch_range( (string) $c['resolved_url'], $offset, $length, ! empty( $c['accept_ranges'] ), $timeout );
				$received = strlen( $chunk );
				$repo->append( (string) $c['transfer_id'], $offset, $chunk, hash( 'sha256', $chunk ) );
				$offset += $received;
				$slice_bytes += $received;
				$c['offset'] = $offset;
				if ( $received < $length && $offset < $total ) {
					throw new RetryableJobException( esc_html__( 'The remote server returned an incomplete archive range.', 'nobash-backup' ) );
				}
			}
			if ( $c['offset'] >= $total ) {
				$c['phase'] = 'finalize';
			}
			$progress = 2 + (int) floor( ( $c['offset'] / max( 1, $total ) ) * 93 );
			return $this->response( $c, $progress, 0, 0, $c['offset'], $total );
		}

		if ( 'finalize' === $phase ) {
			try {
				$result = $repo->finalize( (string) $c['transfer_id'] );
			} catch ( \Throwable $e ) {
				$message = $e->getMessage();
				if ( false !== stripos( $message, 'checksum mismatch' ) ) {
					$message = __( 'SHA-256 verification failed. Confirm that the hash matches this exact archive and try again.', 'nobash-backup' );
				} elseif ( false !== stripos( $message, 'archive' ) && ( false !== stripos( $message, 'invalid' ) || false !== stripos( $message, 'header' ) || false !== stripos( $message, 'format' ) ) ) {
					$message = __( 'The downloaded file is not a valid .nbh archive.', 'nobash-backup' );
				}
				throw new PermanentJobException( esc_html( $message ) );
			}
			$c['phase'] = 'done';
			return array(
				'cursor'          => $c,
				'progress'        => 100,
				'status'          => 'completed',
				'result'          => $result,
				'processed_bytes' => (int) $result['size'],
				'total_bytes'     => (int) $result['size'],
			);
		}

		throw new PermanentJobException( esc_html__( 'Unknown remote import phase.', 'nobash-backup' ) );
	}

	/** @return array{url:string,size:int,accept_ranges:bool} */
	private function probe( $url ) {
		$current = $url;
		for ( $i = 0; $i < 4; $i++ ) {
			$safe = UrlGuard::assert_public_url( $current, false );
			if ( is_wp_error( $safe ) ) {
				throw new PermanentJobException( esc_html( $safe->get_error_message() ) );
			}
			$response = wp_remote_request(
				$current,
				array(
					'method'             => 'HEAD',
					'timeout'            => 20,
					'redirection'        => 0,
					'reject_unsafe_urls' => true,
					'sslverify'          => true,
					'headers'            => array( 'Accept' => 'application/octet-stream' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				throw new RetryableJobException( esc_html( $this->network_error_message( $response ) ) );
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$next = wp_remote_retrieve_header( $response, 'location' );
				if ( ! $next ) {
					throw new PermanentJobException( esc_html__( 'The remote server returned an invalid redirect.', 'nobash-backup' ) );
				}
				$current = $this->absolute_url( $current, $next );
				continue;
			}
			if ( in_array( $code, array( 403, 405, 501 ), true ) ) {
				return $this->probe_with_range_get( $current );
			}
			$this->assert_http_status( $code );
			$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
			if ( false !== strpos( $content_type, 'text/html' ) ) {
				throw new PermanentJobException( esc_html__( 'The URL returned an HTML page instead of an .nbh archive.', 'nobash-backup' ) );
			}
			$size = (int) wp_remote_retrieve_header( $response, 'content-length' );
			if ( $size < 1 ) {
				throw new PermanentJobException( esc_html__( 'The remote server did not provide Content-Length, so the archive size is unavailable.', 'nobash-backup' ) );
			}
			if ( $size > 512 * 1024 * 1024 * 1024 ) {
				throw new PermanentJobException( esc_html__( 'The remote archive exceeds the configured safety limit.', 'nobash-backup' ) );
			}
			return array(
				'url'           => $current,
				'size'          => $size,
				'accept_ranges' => false !== stripos( (string) wp_remote_retrieve_header( $response, 'accept-ranges' ), 'bytes' ),
			);
		}
		throw new PermanentJobException( esc_html__( 'The remote archive redirected too many times.', 'nobash-backup' ) );
	}

	/** @return array{url:string,size:int,accept_ranges:bool} */
	private function probe_with_range_get( $url ) {
		$tmp = wp_tempnam( 'nbh-probe-' );
		if ( ! $tmp ) {
			throw new RetryableJobException( esc_html__( 'The server could not allocate temporary storage for the remote archive probe.', 'nobash-backup' ) );
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'sslverify'           => true,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => 1,
				'headers'             => array( 'Accept' => 'application/octet-stream', 'Range' => 'bytes=0-0' ),
			)
		);
		wp_delete_file( $tmp );
		if ( is_wp_error( $response ) ) {
			throw new RetryableJobException( esc_html( $this->network_error_message( $response ) ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$this->assert_http_status( $code, array( 200, 206 ) );
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( false !== strpos( $content_type, 'text/html' ) ) {
			throw new PermanentJobException( esc_html__( 'The URL returned an HTML page instead of an .nbh archive.', 'nobash-backup' ) );
		}
		$size = 0;
		$content_range = (string) wp_remote_retrieve_header( $response, 'content-range' );
		if ( preg_match( '#/([0-9]+)$#', $content_range, $matches ) ) {
			$size = (int) $matches[1];
		}
		if ( $size < 1 ) {
			$size = (int) wp_remote_retrieve_header( $response, 'content-length' );
		}
		if ( $size < 1 ) {
			throw new PermanentJobException( esc_html__( 'The remote server did not provide the archive size.', 'nobash-backup' ) );
		}
		return array( 'url' => $url, 'size' => $size, 'accept_ranges' => 206 === $code );
	}

	private function fetch_range( $url, $offset, $length, $ranges, $timeout = 45 ) {
		$wp_filesystem = \Nobash\Backup\Filesystem\DirectFilesystem::get();
		$end = $offset + $length - 1;
		$tmp = wp_tempnam( 'nbh-range-' );
		if ( ! $tmp ) {
			throw new RetryableJobException( esc_html__( 'The server could not allocate temporary storage for the next archive chunk.', 'nobash-backup' ) );
		}
		$headers = array( 'Accept' => 'application/octet-stream' );
		if ( $ranges ) {
			$headers['Range'] = 'bytes=' . $offset . '-' . $end;
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => max( 5, min( 120, (int) $timeout ) ),
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'sslverify'           => true,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => $length,
				'headers'             => $headers,
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );
			throw new RetryableJobException( esc_html( $this->network_error_message( $response ) ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $ranges && 206 !== $code ) {
			wp_delete_file( $tmp );
			if ( $code >= 400 ) {
				$this->assert_http_status( $code );
			}
			throw new PermanentJobException( esc_html__( 'The remote server stopped honoring resumable byte-range requests.', 'nobash-backup' ) );
		}
		if ( ! $ranges && 0 !== $offset ) {
			wp_delete_file( $tmp );
			throw new PermanentJobException( esc_html__( 'The remote server does not support resumable byte-range downloads.', 'nobash-backup' ) );
		}
		if ( ! $ranges ) {
			$this->assert_http_status( $code, array( 200 ) );
		}
		$data = $wp_filesystem->get_contents( $tmp );
		wp_delete_file( $tmp );
		if ( false === $data || strlen( $data ) < 1 ) {
			throw new RetryableJobException( esc_html__( 'The remote server returned an empty archive chunk.', 'nobash-backup' ) );
		}
		if ( strlen( $data ) > $length ) {
			throw new PermanentJobException( esc_html__( 'The remote server returned more data than requested.', 'nobash-backup' ) );
		}
		return $data;
	}

	private function assert_http_status( $code, $allowed = array() ) {
		if ( ! empty( $allowed ) && in_array( $code, $allowed, true ) ) {
			return;
		}
		if ( empty( $allowed ) && $code >= 200 && $code < 300 ) {
			return;
		}
		if ( 401 === $code ) throw new PermanentJobException( esc_html__( 'The remote archive requires authentication (HTTP 401).', 'nobash-backup' ) );
		if ( 403 === $code ) throw new PermanentJobException( esc_html__( 'The remote server denied access to the archive (HTTP 403).', 'nobash-backup' ) );
		if ( 404 === $code ) throw new PermanentJobException( esc_html__( 'The remote archive was not found (HTTP 404).', 'nobash-backup' ) );
		if ( 410 === $code ) throw new PermanentJobException( esc_html__( 'The remote archive link has expired (HTTP 410).', 'nobash-backup' ) );
		/* translators: %d: HTTP status code. */
		if ( 408 === $code || 429 === $code || $code >= 500 ) throw new RetryableJobException( sprintf( esc_html__( 'The remote server is temporarily unavailable (HTTP %d).', 'nobash-backup' ), esc_html( $code ) ) );
		/* translators: %d: HTTP status code. */
		throw new PermanentJobException( sprintf( esc_html__( 'The remote server rejected the archive request (HTTP %d).', 'nobash-backup' ), esc_html( $code ) ) );
	}

	private function network_error_message( $error ) {
		$message = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;
		$lower   = strtolower( $message );
		if ( false !== strpos( $lower, 'certificate' ) || false !== strpos( $lower, 'ssl' ) || false !== strpos( $lower, 'tls' ) ) {
			return __( 'The remote server SSL certificate could not be verified.', 'nobash-backup' );
		}
		if ( false !== strpos( $lower, 'timed out' ) || false !== strpos( $lower, 'timeout' ) ) {
			return __( 'The remote archive request timed out.', 'nobash-backup' );
		}
		if ( false !== strpos( $lower, 'resolve' ) || false !== strpos( $lower, 'dns' ) ) {
			return __( 'The remote hostname could not be resolved.', 'nobash-backup' );
		}
		return sprintf( /* translators: %s: Network error message. */ __( 'The remote server could not be reached: %s', 'nobash-backup' ), $message );
	}

	private function absolute_url( $base, $location ) {
		if ( preg_match( '#^https?://#i', $location ) ) return esc_url_raw( $location );
		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
		return $parts['scheme'] . '://' . $parts['host'] . ( '/' === substr( $location, 0, 1 ) ? $location : '/' . ltrim( $location, '/' ) );
	}

	private function response( array $c, $progress, $items = 0, $total = 0, $bytes = 0, $total_bytes = 0 ) {
		return array(
			'cursor'          => $c,
			'progress'        => max( 0, min( 100, (int) $progress ) ),
			'status'          => 'running',
			'processed_items' => $items,
			'total_items'     => $total,
			'processed_bytes' => $bytes,
			'total_bytes'     => $total_bytes,
		);
	}
}

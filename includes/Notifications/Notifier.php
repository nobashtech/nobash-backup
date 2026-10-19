<?php
/**
 * Email / webhook notifications.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Notifications;

use Nobash\Backup\Security\UrlGuard;
use Nobash\Backup\Storage\CredentialVault;

defined( 'ABSPATH' ) || exit;

/**
 * Class Notifier
 */
class Notifier {

	/**
	 * Notify based on job outcome and settings.
	 *
	 * @param array<string, mixed> $job Job.
	 * @return void
	 */
	public function notify_job( array $job ) {
		$settings = get_option( 'nobash_backup_settings', array() );
		$status   = $job['status'];

		$on_success = ! empty( $settings['notify_on_success'] );
		$on_failure = ! isset( $settings['notify_on_failure'] ) || ! empty( $settings['notify_on_failure'] );

		if ( in_array( $status, array( 'completed', 'completed_with_warnings' ), true ) && ! $on_success ) {
			return;
		}
		if ( 'failed' === $status && ! $on_failure ) {
			return;
		}
		if ( ! in_array( $status, array( 'completed', 'completed_with_warnings', 'failed' ), true ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: job type, 2: status */
			__( '[Nobash Backup] %1$s job %2$s', 'nobash-backup' ),
			$job['type'],
			$status
		);

		$body = sprintf(
			"%s\n\nJob ID: %d\nType: %s\nStatus: %s\nProgress: %d%%\nError: %s\nSite: %s\n",
			__( 'Nobash Backup job notification', 'nobash-backup' ),
			(int) $job['id'],
			$job['type'],
			$status,
			(int) $job['progress'],
			$job['error'] ? $job['error'] : '-',
			home_url()
		);

		$email = isset( $settings['notify_email'] ) ? sanitize_email( $settings['notify_email'] ) : '';
		if ( '' === $email ) {
			$email = get_option( 'admin_email' );
		}

		if ( is_email( $email ) ) {
			wp_mail( $email, $subject, $body );
		}

		$webhook = isset( $settings['webhook_url'] ) ? esc_url_raw( $settings['webhook_url'] ) : '';
		if ( ! $webhook ) {
			return;
		}

		$safe = UrlGuard::assert_public_url( $webhook, false );
		if ( is_wp_error( $safe ) ) {
			return;
		}

		$payload = wp_json_encode(
			array(
				'event'     => 'nobash_backup_job',
				'subject'   => $subject,
				'timestamp' => time(),
				'job'       => array(
					'id'       => $job['id'],
					'type'     => $job['type'],
					'status'   => $status,
					'progress' => $job['progress'],
					'error'    => $job['error'],
				),
				'site'      => home_url(),
			)
		);

		$headers = array( 'Content-Type' => 'application/json' );
		$webhook_credentials = CredentialVault::load( 'webhook' );
		$secret = isset( $webhook_credentials['secret'] ) ? (string) $webhook_credentials['secret'] : ( isset( $settings['webhook_secret'] ) ? (string) $settings['webhook_secret'] : '' );
		if ( '' !== $secret ) {
			$headers['X-NBH-Signature'] = 'sha256=' . hash_hmac( 'sha256', (string) $payload, $secret );
		}

		wp_remote_post(
			$webhook,
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'reject_unsafe_urls' => true,
				'sslverify' => true,
				'headers'     => $headers,
				'body'        => $payload,
			)
		);
	}
	/** Send one explicit test through configured notification channels. */
	public function send_test() {
		$settings = get_option( 'nobash_backup_settings', array() );
		$email = sanitize_email( (string) ( $settings['notify_email'] ?? get_option( 'admin_email' ) ) );
		$email_sent = false;
		if ( is_email( $email ) ) {
			$email_sent = (bool) wp_mail(
				$email,
				__( '[Nobash Backup] Notification test', 'nobash-backup' ),
				sprintf( /* translators: %s: Website URL. */ __( 'Nobash Backup notifications are connected for %s.', 'nobash-backup' ), home_url() )
			);
		}
		$webhook = esc_url_raw( (string) ( $settings['webhook_url'] ?? '' ) );
		$webhook_status = 'not_configured';
		if ( $webhook ) {
			$safe = UrlGuard::assert_public_url( $webhook, false );
			if ( is_wp_error( $safe ) ) { throw new \RuntimeException( esc_html( $safe->get_error_message() ) ); }
			$payload = wp_json_encode( array( 'event'=>'nobash_backup_test', 'timestamp'=>time(), 'site'=>home_url() ) );
			$headers = array( 'Content-Type'=>'application/json' );
			$webhook_credentials = CredentialVault::load( 'webhook' );
			$secret = isset( $webhook_credentials['secret'] ) ? (string) $webhook_credentials['secret'] : (string) ( $settings['webhook_secret'] ?? '' );
			if ( '' !== $secret ) { $headers['X-NBH-Signature'] = 'sha256=' . hash_hmac( 'sha256', (string) $payload, $secret ); }
			$response = wp_remote_post( $webhook, array( 'timeout'=>10, 'redirection'=>0, 'reject_unsafe_urls'=>true, 'sslverify'=>true, 'headers'=>$headers, 'body'=>$payload ) );
			if ( is_wp_error( $response ) ) { $webhook_status = 'failed'; }
			else { $code = (int) wp_remote_retrieve_response_code( $response ); $webhook_status = $code >= 200 && $code < 300 ? 'sent' : 'failed'; }
		}
		return array( 'email'=>$email, 'email_sent'=>$email_sent, 'webhook'=>$webhook_status );
	}

}

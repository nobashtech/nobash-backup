<?php
/**
 * WordPress privacy-policy guide integration.
 *
 * @package Nobash\Backup
 */

namespace Nobash\Backup\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Adds accurate suggested privacy text for site administrators.
 */
final class PrivacyPolicy {

	/**
	 * Register privacy hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'add_policy_content' ) );
	}

	/**
	 * Add suggested text to Settings > Privacy.
	 *
	 * @return void
	 */
	public static function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content  = '<p>' . esc_html__( 'Nobash Backup creates recovery archives that may contain the same personal data and private content stored by this website. Local archives remain on infrastructure controlled by the site owner unless an administrator downloads, migrates, or sends them to a configured destination.', 'nobash-backup' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Backup archives are immutable recovery snapshots and are not modified by WordPress personal-data erasure requests. Site owners should use an appropriate retention policy and remove obsolete archives when required.', 'nobash-backup' ) . '</p>';
		$content .= '<p>' . wp_kses_post( __( 'Nobash Backup does not send usage data or telemetry. For security auditing, administrative backup actions may store the acting WordPress user ID and request IP address locally in the site database. This audit data is not sent to Nobash. The support form sends the entered contact details, message, site URL, and software versions to support@nobash.com only when an administrator submits it.', 'nobash-backup' ) ) . '</p>';

		wp_add_privacy_policy_content(
			__( 'Nobash Backup', 'nobash-backup' ),
			wp_kses_post( wpautop( $content, false ) )
		);
	}
}

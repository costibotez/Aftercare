<?php
namespace Aftercare\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suggested privacy-policy content covering the optional RUM beacon and the
 * fatal error log.
 */
final class Privacy {

	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content  = '<p>' . __( 'This site uses Aftercare to monitor page performance. When real-user monitoring is enabled, a small script measures anonymous performance timings (such as page load and layout stability) for a sample of visits and stores aggregated daily values on this website. No cookies are set, and no personal data, IP addresses or identifiers are collected or transmitted to third parties.', 'aftercare' ) . '</p>';
		$content .= '<p>' . __( 'When a page request fails with a PHP fatal error, Aftercare records the file, line and first line of the error message on this website so the site owner can fix it. It records nothing about the visitor whose request failed: no IP address, no request address, no form data and no cookies.', 'aftercare' ) . '</p>';
		$content .= '<p>' . __( 'Aggregate Core Web Vitals statistics for this site are also retrieved from Google\'s Chrome UX Report, a public dataset of real-user experience data.', 'aftercare' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Aftercare', 'aftercare' ), wp_kses_post( wpautop( $content ) ) );
	}
}

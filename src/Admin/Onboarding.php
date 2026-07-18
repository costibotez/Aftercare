<?php
namespace Aftercare\Admin;

use Aftercare\Core\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * First-run pointer: until an API key is configured (or the notice is
 * dismissed), administrators see a short 3-step setup guide on the main
 * dashboard and on Aftercare screens.
 */
final class Onboarding {

	private const DISMISS_OPTION = 'aftercare_onboarding_dismissed';

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
		add_action( 'admin_post_aftercare_dismiss_onboarding', array( $this, 'handle_dismiss' ) );
	}

	public function maybe_show_notice(): void {
		if ( ! current_user_can( Menu::CAP )
			|| get_option( self::DISMISS_OPTION )
			|| '' !== (string) Options::get( 'api_key' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$on_dashboard = 'dashboard' === $screen->id;
		$on_aftercare = str_contains( (string) $screen->id, 'aftercare' ) && ! str_contains( (string) $screen->id, 'aftercare-settings' );
		if ( ! $on_dashboard && ! $on_aftercare ) {
			return;
		}

		$dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=aftercare_dismiss_onboarding' ), 'aftercare_dismiss_onboarding' );

		echo '<div class="notice notice-info aftercare-onboarding">';
		echo '<p><strong>' . esc_html__( 'Aftercare is almost ready — three steps to first data:', 'aftercare' ) . '</strong></p>';
		echo '<ol style="margin-left:1.5em;">';
		echo '<li>' . wp_kses_post( __( 'Create a free Google API key with the <a href="https://console.cloud.google.com/apis/library/chromeuxreport.googleapis.com" target="_blank" rel="noopener">Chrome UX Report API</a> enabled.', 'aftercare' ) ) . '</li>';
		printf(
			'<li>%s</li>',
			wp_kses_post(
				sprintf(
					/* translators: %s: settings page URL */
					__( 'Paste it in <a href="%s">Aftercare Settings</a> and optionally add tracked URLs.', 'aftercare' ),
					esc_url( admin_url( 'admin.php?page=aftercare-settings' ) )
				)
			)
		);
		printf(
			'<li>%s</li>',
			wp_kses_post(
				sprintf(
					/* translators: %s: dashboard page URL */
					__( 'Press <em>Run daily checks now</em> on the <a href="%s">Aftercare dashboard</a> — vitals appear immediately.', 'aftercare' ),
					esc_url( admin_url( 'admin.php?page=aftercare' ) )
				)
			)
		);
		echo '</ol>';
		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=aftercare-settings' ) ) . '">' . esc_html__( 'Open Settings', 'aftercare' ) . '</a> ';
		echo '<a href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss', 'aftercare' ) . '</a></p>';
		echo '</div>';
	}

	public function handle_dismiss(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'aftercare' ) );
		}
		check_admin_referer( 'aftercare_dismiss_onboarding' );
		update_option( self::DISMISS_OPTION, 1, false );
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}
}

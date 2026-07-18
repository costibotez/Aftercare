<?php
namespace Aftercare\Admin;

use Aftercare\Core\Options;
use Aftercare\Licensing\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings: tracked URLs, budgets, RUM, notifications, branding (Pro),
 * data retention and license.
 */
final class SettingsPage {

	public function render(): void {
		$options = Options::all();
		$is_pro  = License::is_pro();

		Menu::header( __( 'Aftercare Settings', 'aftercare' ) );

		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'aftercare' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="aftercare_save_settings" />';
		wp_nonce_field( 'aftercare_save_settings' );

		echo '<h2>' . esc_html__( 'Vitals collection', 'aftercare' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="ac-api-key">' . esc_html__( 'Google API key (CrUX)', 'aftercare' ) . '</label></th><td>';
		echo '<input type="password" id="ac-api-key" name="api_key" value="' . esc_attr( (string) $options['api_key'] ) . '" class="regular-text" autocomplete="off" />';
		echo '<p class="description">' . wp_kses_post( __( 'Your own key for the Chrome UX Report API. Create one in the <a href="https://console.cloud.google.com/apis/library/chromeuxreport.googleapis.com" target="_blank" rel="noopener">Google Cloud Console</a> (free tier is plenty). Aftercare only calls Google with your key — nothing is sent anywhere else.', 'aftercare' ) ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ac-urls">' . esc_html__( 'Tracked URLs', 'aftercare' ) . '</label></th><td>';
		echo '<textarea id="ac-urls" name="tracked_urls" rows="5" class="large-text code" placeholder="' . esc_attr( home_url( '/pricing/' ) ) . '">' . esc_textarea( implode( "\n", (array) $options['tracked_urls'] ) ) . '</textarea>';
		echo '<p class="description">';
		printf(
			/* translators: 1: homepage URL, 2: URL limit */
			esc_html__( 'One per line. The homepage (%1$s) is always tracked. Free monitors up to %2$d extra URLs; Pro is unlimited.', 'aftercare' ),
			esc_html( home_url( '/' ) ),
			(int) Options::FREE_URL_LIMIT
		);
		echo '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Real-user monitoring', 'aftercare' ) . '</th><td>';
		echo '<label><input type="checkbox" name="rum_enabled" value="1" ' . checked( (bool) $options['rum_enabled'], true, false ) . ' /> ' . esc_html__( 'Load the RUM beacon (~2 KB) for a sample of visitors and record field vitals from real visits', 'aftercare' ) . '</label>';
		echo '<p><label for="ac-rum-rate">' . esc_html__( 'Sample rate (% of visits):', 'aftercare' ) . ' </label>';
		echo '<input type="number" id="ac-rum-rate" name="rum_sample_rate" value="' . esc_attr( (string) $options['rum_sample_rate'] ) . '" min="1" max="100" step="1" class="small-text" /></p>';
		echo '</td></tr>';

		echo '</table>';

		echo '<h2>' . esc_html__( 'Performance budgets', 'aftercare' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A daily p75 above budget, or 20% worse than the 28-day baseline, opens an incident.', 'aftercare' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		$budget_fields = array(
			'LCP'  => array( __( 'LCP budget (ms)', 'aftercare' ), '2500' ),
			'INP'  => array( __( 'INP budget (ms)', 'aftercare' ), '200' ),
			'CLS'  => array( __( 'CLS budget (score)', 'aftercare' ), '0.1' ),
			'TTFB' => array( __( 'TTFB budget (ms)', 'aftercare' ), '800' ),
		);
		foreach ( $budget_fields as $metric => [ $label, $placeholder ] ) {
			$step = 'CLS' === $metric ? '0.01' : '50';
			echo '<tr><th scope="row"><label for="ac-budget-' . esc_attr( $metric ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input type="number" id="ac-budget-' . esc_attr( $metric ) . '" name="budgets[' . esc_attr( $metric ) . ']" value="' . esc_attr( (string) $options['budgets'][ $metric ] ) . '" step="' . esc_attr( $step ) . '" min="0" placeholder="' . esc_attr( $placeholder ) . '" class="regular-text" />';
			echo '</td></tr>';
		}
		echo '</table>';

		echo '<h2>' . esc_html__( 'Notifications', 'aftercare' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="ac-alert-email">' . esc_html__( 'Alert email', 'aftercare' ) . '</label></th><td>';
		echo '<input type="email" id="ac-alert-email" name="alert_email" value="' . esc_attr( (string) $options['alert_email'] ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'New incidents and the weekly digest are emailed here.', 'aftercare' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Weekly digest', 'aftercare' ) . '</th><td>';
		echo '<label><input type="checkbox" name="weekly_digest" value="1" ' . checked( (bool) $options['weekly_digest'], true, false ) . ' /> ' . esc_html__( 'Send a weekly summary email: vitals status, changes made and incidents from the past 7 days', 'aftercare' ) . '</label>';
		echo '</td></tr>';

		$pro_attr = $is_pro ? '' : ' disabled';
		$pro_note = $is_pro ? '' : ' <span class="aftercare-badge aftercare-badge-medium">' . esc_html__( 'Pro', 'aftercare' ) . '</span>';

		echo '<tr><th scope="row"><label for="ac-slack">' . esc_html__( 'Slack webhook URL', 'aftercare' ) . wp_kses_post( $pro_note ) . '</label></th><td>';
		echo '<input type="url" id="ac-slack" name="slack_webhook" value="' . esc_attr( (string) $options['slack_webhook'] ) . '" class="regular-text"' . esc_attr( $pro_attr ) . ' />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ac-webhook">' . esc_html__( 'Generic webhook URL', 'aftercare' ) . wp_kses_post( $pro_note ) . '</label></th><td>';
		echo '<input type="url" id="ac-webhook" name="webhook_url" value="' . esc_attr( (string) $options['webhook_url'] ) . '" class="regular-text"' . esc_attr( $pro_attr ) . ' />';
		echo '<p class="description">' . esc_html__( 'Incidents are POSTed as JSON.', 'aftercare' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';

		echo '<h2>' . esc_html__( 'Client reports & branding', 'aftercare' ) . wp_kses_post( $pro_note ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="ac-recipients">' . esc_html__( 'Client recipients', 'aftercare' ) . '</label></th><td>';
		echo '<input type="text" id="ac-recipients" name="client_recipients" value="' . esc_attr( (string) $options['client_recipients'] ) . '" class="regular-text"' . esc_attr( $pro_attr ) . ' />';
		echo '<p class="description">' . esc_html__( 'Comma-separated email addresses that receive the monthly report.', 'aftercare' ) . '</p>';
		echo '</td></tr>';

		$branding = (array) $options['branding'];
		echo '<tr><th scope="row"><label for="ac-logo">' . esc_html__( 'Logo URL', 'aftercare' ) . '</label></th><td>';
		echo '<input type="url" id="ac-logo" name="branding[logo_url]" value="' . esc_attr( (string) $branding['logo_url'] ) . '" class="regular-text"' . esc_attr( $pro_attr ) . ' />';
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="ac-accent">' . esc_html__( 'Accent colour', 'aftercare' ) . '</label></th><td>';
		echo '<input type="text" id="ac-accent" name="branding[accent]" value="' . esc_attr( (string) $branding['accent'] ) . '" class="small-text code" placeholder="#0f766e"' . esc_attr( $pro_attr ) . ' />';
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="ac-footer">' . esc_html__( 'Report footer text', 'aftercare' ) . '</label></th><td>';
		echo '<input type="text" id="ac-footer" name="branding[footer_text]" value="' . esc_attr( (string) $branding['footer_text'] ) . '" class="regular-text"' . esc_attr( $pro_attr ) . ' />';
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="ac-sender">' . esc_html__( 'Sender name', 'aftercare' ) . '</label></th><td>';
		echo '<input type="text" id="ac-sender" name="branding[sender_name]" value="' . esc_attr( (string) $branding['sender_name'] ) . '" class="regular-text"' . esc_attr( $pro_attr ) . ' />';
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="ac-replyto">' . esc_html__( 'Reply-to address', 'aftercare' ) . '</label></th><td>';
		echo '<input type="email" id="ac-replyto" name="branding[reply_to]" value="' . esc_attr( (string) $branding['reply_to'] ) . '" class="regular-text"' . esc_attr( $pro_attr ) . ' />';
		echo '</td></tr>';

		echo '</table>';

		echo '<h2>' . esc_html__( 'Data', 'aftercare' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Retention', 'aftercare' ) . '</th><td><p class="description">';
		if ( $is_pro ) {
			echo esc_html__( 'Pro: 13 months of vitals history, unlimited ledger.', 'aftercare' );
		} else {
			echo esc_html__( 'Free: 30 days of vitals history, 90 days of ledger. Pro extends this to 13 months and unlimited.', 'aftercare' );
		}
		echo '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Uninstall', 'aftercare' ) . '</th><td>';
		echo '<label><input type="checkbox" name="keep_data_on_uninstall" value="1" ' . checked( (bool) $options['keep_data_on_uninstall'], true, false ) . ' /> ' . esc_html__( 'Keep Aftercare data (tables and settings) when the plugin is deleted', 'aftercare' ) . '</label>';
		echo '</td></tr>';
		echo '</table>';

		if ( ! $is_pro ) {
			echo '<div class="aftercare-upsell">';
			echo '<p><strong>' . esc_html__( 'Aftercare Pro', 'aftercare' ) . '</strong> — ' . esc_html__( 'cause attribution, white-label client reports, Slack & webhooks, unlimited URLs and 13-month history.', 'aftercare' ) . '</p>';
			echo '<a class="button button-primary" href="' . esc_url( License::upgrade_url() ) . '">' . esc_html__( 'See plans', 'aftercare' ) . '</a>';
			echo '</div>';
		}

		submit_button( __( 'Save settings', 'aftercare' ) );
		echo '</form>';

		Menu::footer();
	}

	public static function handle_save(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'aftercare' ) );
		}
		check_admin_referer( 'aftercare_save_settings' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitized below.
		$urls = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) wp_unslash( $_POST['tracked_urls'] ?? '' ) ) as $line ) {
			$url = esc_url_raw( trim( $line ) );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		$budgets_in = (array) wp_unslash( $_POST['budgets'] ?? array() );
		$budgets    = array();
		foreach ( Options::METRICS as $metric ) {
			$budgets[ $metric ] = max( 0, (float) ( $budgets_in[ $metric ] ?? Options::defaults()['budgets'][ $metric ] ) );
		}

		$new = array(
			'api_key'                => sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ),
			'tracked_urls'           => $urls,
			'budgets'                => $budgets,
			'rum_enabled'            => ! empty( $_POST['rum_enabled'] ),
			'rum_sample_rate'        => max( 1, min( 100, (int) ( $_POST['rum_sample_rate'] ?? 10 ) ) ),
			'alert_email'            => sanitize_email( wp_unslash( $_POST['alert_email'] ?? '' ) ),
			'weekly_digest'          => ! empty( $_POST['weekly_digest'] ),
			'keep_data_on_uninstall' => ! empty( $_POST['keep_data_on_uninstall'] ),
		);

		if ( License::is_pro() ) {
			$branding_in = (array) wp_unslash( $_POST['branding'] ?? array() );
			$accent      = sanitize_hex_color( (string) ( $branding_in['accent'] ?? '' ) );

			$new['slack_webhook']     = esc_url_raw( wp_unslash( $_POST['slack_webhook'] ?? '' ) );
			$new['webhook_url']       = esc_url_raw( wp_unslash( $_POST['webhook_url'] ?? '' ) );
			$new['client_recipients'] = sanitize_text_field( wp_unslash( $_POST['client_recipients'] ?? '' ) );
			$new['branding']          = array(
				'logo_url'    => esc_url_raw( (string) ( $branding_in['logo_url'] ?? '' ) ),
				'accent'      => $accent ? $accent : '#0f766e',
				'footer_text' => sanitize_text_field( (string) ( $branding_in['footer_text'] ?? '' ) ),
				'sender_name' => sanitize_text_field( (string) ( $branding_in['sender_name'] ?? '' ) ),
				'reply_to'    => sanitize_email( (string) ( $branding_in['reply_to'] ?? '' ) ),
			);
		}
		// phpcs:enable

		Options::update( $new );

		wp_safe_redirect( admin_url( 'admin.php?page=aftercare-settings&updated=1' ) );
		exit;
	}
}

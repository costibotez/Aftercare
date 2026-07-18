<?php
namespace Aftercare\Admin;

use Aftercare\Licensing\License;
use Aftercare\Reports\Builder;
use Aftercare\Reports\Repository as ReportRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro: report archive, editor (personal note), preview and send.
 */
final class ReportsPage {

	public function render(): void {
		Menu::header( __( 'Client Reports', 'aftercare' ) );

		// The report builder ships only in the premium build; the free build
		// shows what Pro offers (advertising is fine, locked code is not).
		if ( ! License::is_pro() || ! class_exists( Builder::class ) ) {
			echo '<div class="aftercare-upsell aftercare-panel">';
			echo '<p><strong>' . esc_html__( 'Turn every month into proof of your work.', 'aftercare' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Aftercare Pro drafts a white-label report per month: Core Web Vitals versus last month, every change you made, every regression caught and resolved — with your logo, your colours and a personal note. Print it, download it or email it straight to the client.', 'aftercare' ) . '</p>';
			echo '<a class="button button-primary" href="' . esc_url( License::upgrade_url() ) . '">' . esc_html__( 'Upgrade to Pro', 'aftercare' ) . '</a>';
			echo '</div>';
			Menu::footer();
			return;
		}

		if ( isset( $_GET['sent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Report sent to the configured client recipients.', 'aftercare' ) . '</p></div>';
		}
		if ( isset( $_GET['send_failed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Sending failed. Check that client recipients are configured in Settings and that the site can send email.', 'aftercare' ) . '</p></div>';
		}

		$report_id = isset( $_GET['report'] ) ? absint( wp_unslash( $_GET['report'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
		if ( $report_id > 0 ) {
			$this->render_editor( $report_id );
			Menu::footer();
			return;
		}

		// Generate buttons: current and previous month.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aftercare-inline-form">';
		echo '<input type="hidden" name="action" value="aftercare_report_generate" />';
		wp_nonce_field( 'aftercare_report_generate' );
		echo '<button class="button button-primary" name="period" value="previous">' . esc_html__( 'Generate draft for last month', 'aftercare' ) . '</button> ';
		echo '<button class="button" name="period" value="current">' . esc_html__( 'Generate draft for this month (partial)', 'aftercare' ) . '</button>';
		echo '</form>';

		$reports = ( new ReportRepository() )->all();
		if ( empty( $reports ) ) {
			echo '<p class="aftercare-subtle">' . esc_html__( 'No reports yet. Drafts are generated automatically on the 1st of each month, or on demand above.', 'aftercare' ) . '</p>';
			Menu::footer();
			return;
		}

		echo '<table class="widefat striped aftercare-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Period', 'aftercare' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'aftercare' ) . '</th>';
		echo '<th>' . esc_html__( 'Generated', 'aftercare' ) . '</th>';
		echo '<th>' . esc_html__( 'Sent', 'aftercare' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';
		foreach ( $reports as $report ) {
			$period = date_i18n( 'F Y', mktime( 0, 0, 0, (int) $report['period_month'], 1, (int) $report['period_year'] ) );
			echo '<tr>';
			echo '<td><strong>' . esc_html( $period ) . '</strong></td>';
			echo '<td>' . ( 'sent' === $report['status']
				? '<span class="aftercare-pill aftercare-pill-pass">' . esc_html__( 'Sent', 'aftercare' ) . '</span>'
				: '<span class="aftercare-pill aftercare-pill-warn">' . esc_html__( 'Draft', 'aftercare' ) . '</span>' ) . '</td>';
			echo '<td>' . esc_html( (string) $report['generated_at'] ) . '</td>';
			echo '<td>' . esc_html( (string) ( $report['sent_at'] ?: '—' ) ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=aftercare-reports&report=' . (int) $report['id'] ) ) . '">' . esc_html__( 'Open', 'aftercare' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function render_editor( int $id ): void {
		$report = ( new ReportRepository() )->find( $id );
		if ( ! $report ) {
			echo '<p>' . esc_html__( 'Report not found.', 'aftercare' ) . '</p>';
			return;
		}

		$period = date_i18n( 'F Y', mktime( 0, 0, 0, (int) $report['period_month'], 1, (int) $report['period_year'] ) );

		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=aftercare-reports' ) ) . '">&larr; ' . esc_html__( 'All reports', 'aftercare' ) . '</a></p>';
		echo '<div class="aftercare-panel">';
		echo '<h2>' . esc_html( $period ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="aftercare_report_note" />';
		echo '<input type="hidden" name="report" value="' . (int) $id . '" />';
		wp_nonce_field( 'aftercare_report_note_' . $id );
		echo '<p><label for="aftercare-note"><strong>' . esc_html__( 'Personal note to the client', 'aftercare' ) . '</strong></label></p>';
		echo '<textarea id="aftercare-note" name="personal_note" rows="4" class="large-text">' . esc_textarea( (string) ( $report['personal_note'] ?? '' ) ) . '</textarea>';
		echo '<p><button class="button">' . esc_html__( 'Save note', 'aftercare' ) . '</button></p>';
		echo '</form>';

		$preview_url = wp_nonce_url( admin_url( 'admin-post.php?action=aftercare_report_preview&report=' . $id ), 'aftercare_report_preview_' . $id );
		echo '<p>';
		echo '<a class="button" href="' . esc_url( $preview_url ) . '" target="_blank">' . esc_html__( 'Preview / print (PDF via browser)', 'aftercare' ) . '</a> ';
		echo '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aftercare-inline-form" onsubmit="return confirm(\'' . esc_js( __( 'Send this report to the configured client recipients?', 'aftercare' ) ) . '\');">';
		echo '<input type="hidden" name="action" value="aftercare_report_send" />';
		echo '<input type="hidden" name="report" value="' . (int) $id . '" />';
		wp_nonce_field( 'aftercare_report_send_' . $id );
		echo '<button class="button button-primary">' . esc_html__( 'Send to client', 'aftercare' ) . '</button>';
		echo '</form>';

		echo '</div>';
	}

	public static function handle_generate(): void {
		self::guard( 'aftercare_report_generate' );

		$period = isset( $_POST['period'] ) ? sanitize_key( wp_unslash( $_POST['period'] ) ) : 'previous'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$ts     = 'current' === $period ? time() : strtotime( 'first day of last month' );
		$id     = ( new Builder() )->generate_draft( (int) gmdate( 'n', $ts ), (int) gmdate( 'Y', $ts ) );

		wp_safe_redirect( admin_url( 'admin.php?page=aftercare-reports&report=' . $id ) );
		exit;
	}

	public static function handle_note(): void {
		// The report ID is part of the nonce action, so it must be read first.
		$id = isset( $_POST['report'] ) ? absint( wp_unslash( $_POST['report'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		self::guard( 'aftercare_report_note_' . $id );

		$note = isset( $_POST['personal_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['personal_note'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		( new ReportRepository() )->set_personal_note( $id, $note );
		wp_safe_redirect( admin_url( 'admin.php?page=aftercare-reports&report=' . $id ) );
		exit;
	}

	public static function handle_send(): void {
		// The report ID is part of the nonce action, so it must be read first.
		$id = isset( $_POST['report'] ) ? absint( wp_unslash( $_POST['report'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		self::guard( 'aftercare_report_send_' . $id );

		$repo   = new ReportRepository();
		$report = $repo->find( $id );
		$sent   = $report ? ( new Builder() )->send( $report ) : false;

		wp_safe_redirect( admin_url( 'admin.php?page=aftercare-reports&' . ( $sent ? 'sent=1' : 'send_failed=1' ) ) );
		exit;
	}

	/**
	 * Streams the print-friendly report HTML. If a PDF engine is provided via
	 * the `aftercare_pdf_engine` filter (e.g. dompdf), it renders a download
	 * instead; otherwise the print stylesheet + browser print covers PDF.
	 */
	public static function handle_preview(): void {
		// The report ID is part of the nonce action, so it must be read first.
		$id = isset( $_GET['report'] ) ? absint( wp_unslash( $_GET['report'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in guard().
		self::guard( 'aftercare_report_preview_' . $id, false );

		$report = ( new ReportRepository() )->find( $id );
		if ( ! $report ) {
			wp_die( esc_html__( 'Report not found.', 'aftercare' ) );
		}

		$html = ( new Builder() )->render_report( $report );

		/**
		 * Filter to plug in a PDF engine. Return a callable that accepts the
		 * report HTML and outputs a PDF (setting its own headers), or null to
		 * use the print-friendly HTML preview.
		 *
		 * @param callable|null $engine
		 */
		$engine = apply_filters( 'aftercare_pdf_engine', null );
		if ( is_callable( $engine ) ) {
			$engine( $html );
			exit;
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fully escaped in the template.
		exit;
	}

	private static function guard( string $nonce_action, bool $is_post = true ): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'aftercare' ) );
		}
		check_admin_referer( $nonce_action );
		if ( ! License::is_pro() || ! class_exists( Builder::class ) ) {
			wp_die( esc_html__( 'Client reports are an Aftercare Pro feature.', 'aftercare' ) );
		}
	}
}

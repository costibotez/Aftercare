<?php
namespace Aftercare\Admin;

use Aftercare\Core\Util;
use Aftercare\Incidents\Repository as IncidentRepository;
use Aftercare\Ledger\Repository as LedgerRepository;
use Aftercare\Licensing\License;
use Aftercare\Vitals\SampleRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Incident list plus detail view with the attribution timeline.
 */
final class IncidentsPage {

	public function render(): void {
		$incident_id = (int) ( $_GET['incident'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $incident_id > 0 ) {
			$this->render_detail( $incident_id );
			return;
		}
		$this->render_list();
	}

	private function render_list(): void {
		$incidents = ( new IncidentRepository() )->all();

		Menu::header( __( 'Incidents', 'aftercare' ) );

		if ( empty( $incidents ) ) {
			echo '<p class="aftercare-subtle">' . esc_html__( 'No incidents recorded. When a metric breaches its budget or regresses more than 20% against its 28-day baseline, an incident opens here and an alert email goes out.', 'aftercare' ) . '</p>';
			Menu::footer();
			return;
		}

		echo '<table class="widefat striped aftercare-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Metric', 'aftercare' ) . '</th>';
		echo '<th>' . esc_html__( 'URL', 'aftercare' ) . '</th>';
		echo '<th>' . esc_html__( 'Breach', 'aftercare' ) . '</th>';
		echo '<th>' . esc_html__( 'Budget', 'aftercare' ) . '</th>';
		echo '<th>' . esc_html__( 'Baseline', 'aftercare' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'aftercare' ) . '</th>';
		echo '<th>' . esc_html__( 'Opened', 'aftercare' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $incidents as $incident ) {
			$metric = (string) $incident['metric'];
			echo '<tr>';
			echo '<td><strong>' . esc_html( $metric ) . '</strong></td>';
			echo '<td class="aftercare-cell-url">' . esc_html( (string) $incident['url'] ) . '</td>';
			echo '<td class="aftercare-fail">' . esc_html( Util::format_metric( $metric, (float) $incident['breach_p75'] ) ) . '</td>';
			echo '<td>' . esc_html( Util::format_metric( $metric, (float) $incident['budget_value'] ) ) . '</td>';
			echo '<td>' . ( null !== $incident['baseline_p75'] ? esc_html( Util::format_metric( $metric, (float) $incident['baseline_p75'] ) ) : '&mdash;' ) . '</td>';
			echo '<td>' . self::status_pill( (string) $incident['status'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
			echo '<td>' . esc_html( human_time_diff( strtotime( $incident['opened_at'] . ' UTC' ) ) . ' ' . __( 'ago', 'aftercare' ) ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=aftercare-incidents&incident=' . (int) $incident['id'] ) ) . '">' . esc_html__( 'Details', 'aftercare' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		Menu::footer();
	}

	private function render_detail( int $id ): void {
		$repo     = new IncidentRepository();
		$incident = $repo->find( $id );

		Menu::header( __( 'Incident detail', 'aftercare' ) );

		if ( ! $incident ) {
			echo '<p>' . esc_html__( 'Incident not found.', 'aftercare' ) . '</p>';
			Menu::footer();
			return;
		}

		$metric = (string) $incident['metric'];
		$url    = (string) $incident['url'];

		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=aftercare-incidents' ) ) . '">&larr; ' . esc_html__( 'All incidents', 'aftercare' ) . '</a></p>';

		echo '<div class="aftercare-panel">';
		echo '<h2>' . esc_html( $metric ) . ' — ' . esc_html( $url ) . ' ' . self::status_pill( (string) $incident['status'] ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '<div class="aftercare-incident-stats">';
		echo '<div><span class="aftercare-stat-label">' . esc_html__( 'Breach p75', 'aftercare' ) . '</span><span class="aftercare-stat aftercare-fail">' . esc_html( Util::format_metric( $metric, (float) $incident['breach_p75'] ) ) . '</span></div>';
		echo '<div><span class="aftercare-stat-label">' . esc_html__( 'Budget', 'aftercare' ) . '</span><span class="aftercare-stat">' . esc_html( Util::format_metric( $metric, (float) $incident['budget_value'] ) ) . '</span></div>';
		echo '<div><span class="aftercare-stat-label">' . esc_html__( '28-day baseline', 'aftercare' ) . '</span><span class="aftercare-stat">' . ( null !== $incident['baseline_p75'] ? esc_html( Util::format_metric( $metric, (float) $incident['baseline_p75'] ) ) : '&mdash;' ) . '</span></div>';
		echo '<div><span class="aftercare-stat-label">' . esc_html__( 'Opened', 'aftercare' ) . '</span><span class="aftercare-stat">' . esc_html( (string) $incident['opened_at'] ) . ' UTC</span></div>';
		echo '</div>';

		// 60-day trend with the breach visible.
		$series = ( new SampleRepository() )->series( $url, $metric, 60 );
		echo '<div class="aftercare-sparkline aftercare-sparkline-large" data-sparkline="' . esc_attr( (string) wp_json_encode( array_column( $series, 'value' ) ) ) . '" data-budget="' . esc_attr( (string) $incident['budget_value'] ) . '"></div>';

		// Status actions.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aftercare-inline-form">';
		echo '<input type="hidden" name="action" value="aftercare_incident_status" />';
		echo '<input type="hidden" name="incident" value="' . (int) $incident['id'] . '" />';
		wp_nonce_field( 'aftercare_incident_status_' . (int) $incident['id'] );
		foreach ( array(
			'acknowledged' => __( 'Acknowledge', 'aftercare' ),
			'resolved'     => __( 'Mark resolved', 'aftercare' ),
			'dismissed'    => __( 'Dismiss', 'aftercare' ),
		) as $status => $label ) {
			if ( $status === $incident['status'] ) {
				continue;
			}
			echo '<button class="button" name="status" value="' . esc_attr( $status ) . '">' . esc_html( $label ) . '</button> ';
		}
		echo '</form>';
		echo '</div>';

		// Attribution: ranked causes (Pro) or upsell + raw timeline (free).
		echo '<div class="aftercare-columns">';

		echo '<div class="aftercare-panel">';
		echo '<h2>' . esc_html__( 'Probable cause', 'aftercare' ) . '</h2>';
		if ( License::is_pro() ) {
			$causes = json_decode( (string) ( $incident['causes'] ?? '' ), true );
			if ( is_array( $causes ) && $causes ) {
				echo '<ol class="aftercare-causes">';
				foreach ( $causes as $cause ) {
					$confidence = (string) ( $cause['confidence'] ?? 'low' );
					echo '<li>';
					echo '<span class="aftercare-badge aftercare-badge-' . esc_attr( $confidence ) . '">' . esc_html( self::confidence_label( $confidence ) ) . '</span> ';
					echo esc_html( (string) ( $cause['label'] ?? '' ) );
					if ( ! empty( $cause['occurred_at'] ) ) {
						echo ' <span class="aftercare-event-meta">' . esc_html( (string) $cause['occurred_at'] ) . ' UTC</span>';
					}
					echo '</li>';
				}
				echo '</ol>';
			} else {
				echo '<p class="aftercare-subtle">' . esc_html__( 'No attribution data recorded for this incident.', 'aftercare' ) . '</p>';
			}
		} else {
			echo '<div class="aftercare-upsell">';
			echo '<p><strong>' . esc_html__( 'Aftercare Pro tells you which change probably did this.', 'aftercare' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Pro ranks every change from the 72 hours before the regression with a confidence score — plugin updates, activations, publishes, settings — so you fix the right thing first.', 'aftercare' ) . '</p>';
			echo '<a class="button button-primary" href="' . esc_url( License::upgrade_url() ) . '">' . esc_html__( 'Upgrade to Pro', 'aftercare' ) . '</a>';
			echo '</div>';
		}
		echo '</div>';

		// Raw ledger window — free and Pro both see this.
		echo '<div class="aftercare-panel">';
		echo '<h2>' . esc_html__( 'Changes in the 72 hours before the breach', 'aftercare' ) . '</h2>';
		$from   = gmdate( 'Y-m-d H:i:s', strtotime( $incident['opened_at'] . ' UTC' ) - 72 * HOUR_IN_SECONDS );
		$events = ( new LedgerRepository() )->in_window( $from, (string) $incident['opened_at'] );
		if ( empty( $events ) ) {
			echo '<p class="aftercare-subtle">' . esc_html__( 'No site changes were recorded in this window — an external factor (hosting, traffic, third-party scripts) is likely.', 'aftercare' ) . '</p>';
		} else {
			echo '<ul class="aftercare-timeline">';
			foreach ( $events as $event ) {
				LedgerPage::render_event_row( $event );
			}
			echo '</ul>';
		}
		echo '</div>';

		echo '</div>';

		Menu::footer();
	}

	public static function status_pill( string $status ): string {
		$labels = array(
			'open'         => __( 'Open', 'aftercare' ),
			'acknowledged' => __( 'Acknowledged', 'aftercare' ),
			'resolved'     => __( 'Resolved', 'aftercare' ),
			'dismissed'    => __( 'Dismissed', 'aftercare' ),
		);
		$class  = in_array( $status, array( 'resolved', 'dismissed' ), true ) ? 'pass' : ( 'acknowledged' === $status ? 'warn' : 'fail' );
		return '<span class="aftercare-pill aftercare-pill-' . esc_attr( $class ) . '">' . esc_html( $labels[ $status ] ?? $status ) . '</span>';
	}

	private static function confidence_label( string $confidence ): string {
		$labels = array(
			'high'   => __( 'High confidence', 'aftercare' ),
			'medium' => __( 'Medium confidence', 'aftercare' ),
			'low'    => __( 'Low confidence', 'aftercare' ),
		);
		return $labels[ $confidence ] ?? $confidence;
	}

	public static function handle_status_change(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'aftercare' ) );
		}
		$id = (int) ( $_POST['incident'] ?? 0 );
		check_admin_referer( 'aftercare_incident_status_' . $id );

		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		( new IncidentRepository() )->set_status( $id, $status );

		wp_safe_redirect( admin_url( 'admin.php?page=aftercare-incidents&incident=' . $id ) );
		exit;
	}
}

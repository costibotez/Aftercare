<?php
namespace Aftercare\Admin;

use Aftercare\Core\Util;
use Aftercare\Incidents\Repository as IncidentRepository;
use Aftercare\Errors\Repository as ErrorRepository;
use Aftercare\Incidents\Attribution;
use Aftercare\Ledger\Repository as LedgerRepository;
use Aftercare\Vitals\SampleRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Incident list plus detail view with the attribution timeline.
 */
final class IncidentsPage {

	public function render(): void {
		$incident_id = isset( $_GET['incident'] ) ? absint( wp_unslash( $_GET['incident'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
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
			echo '<td><strong>' . esc_html( IncidentRepository::label( $incident ) ) . '</strong></td>';
			echo '<td class="aftercare-cell-url">' . esc_html( (string) $incident['url'] ) . '</td>';
			echo '<td class="aftercare-fail">' . esc_html( Util::format_metric( $metric, (float) $incident['breach_p75'] ) ) . '</td>';
			echo '<td>' . ( IncidentRepository::is_vitals( $incident ) ? esc_html( Util::format_metric( $metric, (float) $incident['budget_value'] ) ) : '&mdash;' ) . '</td>';
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
		$is_lab = IncidentRepository::SOURCE_LAB === ( $incident['sample_source'] ?? '' );

		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=aftercare-incidents' ) ) . '">&larr; ' . esc_html__( 'All incidents', 'aftercare' ) . '</a></p>';

		echo '<div class="aftercare-panel">';
		echo '<h2>' . esc_html( IncidentRepository::label( $incident ) ) . ' — ' . esc_html( $url ) . ' ' . self::status_pill( (string) $incident['status'] ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! IncidentRepository::is_vitals( $incident ) ) {
			$this->render_problem_stats( $incident );
		} else {
			echo '<div class="aftercare-incident-stats">';
			echo '<div><span class="aftercare-stat-label">' . ( $is_lab ? esc_html__( 'Lab reading', 'aftercare' ) : esc_html__( 'Breach p75', 'aftercare' ) ) . '</span><span class="aftercare-stat aftercare-fail">' . esc_html( Util::format_metric( $metric, (float) $incident['breach_p75'] ) ) . '</span></div>';
			echo '<div><span class="aftercare-stat-label">' . esc_html__( 'Budget', 'aftercare' ) . '</span><span class="aftercare-stat">' . esc_html( Util::format_metric( $metric, (float) $incident['budget_value'] ) ) . '</span></div>';
			echo '<div><span class="aftercare-stat-label">' . esc_html__( '28-day baseline', 'aftercare' ) . '</span><span class="aftercare-stat">' . ( null !== $incident['baseline_p75'] ? esc_html( Util::format_metric( $metric, (float) $incident['baseline_p75'] ) ) : '&mdash;' ) . '</span></div>';
			echo '<div><span class="aftercare-stat-label">' . esc_html__( 'Opened', 'aftercare' ) . '</span><span class="aftercare-stat">' . esc_html( (string) $incident['opened_at'] ) . ' UTC</span></div>';
			echo '</div>';

			if ( $is_lab ) {
				echo '<p class="aftercare-subtle">' . esc_html__( 'Opened by a Lighthouse lab check: the reading was more than 20% worse than the 28-day lab baseline, by more than normal run-to-run variation. Lab incidents never open from the budget alone.', 'aftercare' ) . '</p>';
			}
		}

		$triggers = ( new LedgerRepository() )->find_many( IncidentRepository::trigger_ids( $incident ) );
		if ( $triggers ) {
			echo '<p><strong>' . esc_html__( 'Caught by the lab re-test that ran after these changes:', 'aftercare' ) . '</strong></p>';
			echo '<ul class="aftercare-timeline">';
			foreach ( $triggers as $event ) {
				LedgerPage::render_event_row( $event );
			}
			echo '</ul>';
		}

		if ( IncidentRepository::is_vitals( $incident ) ) {
			// 60-day trend with the breach visible, drawn from the same kind
			// of data that opened the incident.
			$samples = new SampleRepository();
			$series  = $is_lab ? $samples->lab_series( $url, $metric, 60 ) : $samples->series( $url, $metric, 60 );
			echo '<div class="aftercare-sparkline aftercare-sparkline-large" data-sparkline="' . esc_attr( (string) wp_json_encode( array_column( $series, 'value' ) ) ) . '" data-budget="' . esc_attr( (string) $incident['budget_value'] ) . '"></div>';
		}

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

		// Ranked cause attribution comes from the separate add-on. Without it
		// the page shows the raw ledger window on its own, which is the same
		// evidence in unranked form.
		echo '<div class="aftercare-columns">';

		if ( class_exists( Attribution::class ) ) {
			echo '<div class="aftercare-panel">';
			echo '<h2>' . esc_html__( 'Probable cause', 'aftercare' ) . '</h2>';
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
			echo '</div>';
		}

		// Raw ledger window.
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

	/**
	 * Stats for incidents that are not about vitals: a fatal error, or a page
	 * that failed the availability check.
	 *
	 * @param array<string, mixed> $incident
	 */
	private function render_problem_stats( array $incident ): void {
		$stat = static function ( string $label, string $value, string $class = '' ): void {
			echo '<div><span class="aftercare-stat-label">' . esc_html( $label ) . '</span><span class="aftercare-stat ' . esc_attr( $class ) . '">' . esc_html( $value ) . '</span></div>';
		};

		if ( IncidentRepository::SOURCE_ERROR === $incident['sample_source'] ) {
			$error = ( new ErrorRepository() )->find_by_location( (string) $incident['url'] );
			if ( $error ) {
				echo '<p><code>' . esc_html( (string) $error['message'] ) . '</code></p>';
			}
			echo '<div class="aftercare-incident-stats">';
			if ( $error ) {
				$stat( __( 'Occurrences', 'aftercare' ), Util::format_metric( 'ERROR', (float) $error['occurrences'] ), 'aftercare-fail' );
				$stat( __( 'Likely source', 'aftercare' ), ErrorRepository::culprit_label( (string) $error['culprit'] ) );
				$stat( __( 'First seen', 'aftercare' ), (string) $error['first_seen'] . ' UTC' );
				$stat( __( 'Last seen', 'aftercare' ), (string) $error['last_seen'] . ' UTC' );
			}
			$stat( __( 'Opened', 'aftercare' ), (string) $incident['opened_at'] . ' UTC' );
			echo '</div>';
			echo '<p class="aftercare-subtle">' . esc_html__( 'Resolves on its own once the error has not happened for 24 hours.', 'aftercare' ) . '</p>';
			return;
		}

		echo '<div class="aftercare-incident-stats">';
		$stat( __( 'Response', 'aftercare' ), Util::format_metric( 'HTTP', (float) $incident['breach_p75'] ), 'aftercare-fail' );
		$stat( __( 'Opened', 'aftercare' ), (string) $incident['opened_at'] . ' UTC' );
		echo '</div>';
		echo '<p class="aftercare-subtle">' . esc_html__( 'The page failed two availability checks in a row: a server error, a missing page or a page cut off before it finished rendering. Resolves on its own at the first healthy check.', 'aftercare' ) . '</p>';
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
		// The incident ID is part of the nonce action, so it must be read first.
		$id = isset( $_POST['incident'] ) ? absint( wp_unslash( $_POST['incident'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line.
		check_admin_referer( 'aftercare_incident_status_' . $id );

		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		( new IncidentRepository() )->set_status( $id, $status );

		wp_safe_redirect( admin_url( 'admin.php?page=aftercare-incidents&incident=' . $id ) );
		exit;
	}
}

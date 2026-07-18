<?php
namespace Aftercare\Admin;

use Aftercare\Core\Options;
use Aftercare\Core\Util;
use Aftercare\Incidents\Repository as IncidentRepository;
use Aftercare\Vitals\Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Site performance" widget on the main wp-admin dashboard: vitals status
 * pills and the open incidents count at a glance.
 */
final class DashboardWidget {

	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
		add_action(
			'admin_enqueue_scripts',
			static function ( $hook ) {
				if ( 'index.php' === $hook ) {
					wp_enqueue_style( 'aftercare-admin', AFTERCARE_URL . 'assets/css/admin.css', array(), AFTERCARE_VERSION );
				}
			}
		);
	}

	public function add_widget(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		wp_add_dashboard_widget( 'aftercare_status', __( 'Aftercare — Site performance', 'aftercare' ), array( $this, 'render' ) );
	}

	public function render(): void {
		$labels = array(
			'pass'  => __( 'Pass', 'aftercare' ),
			'warn'  => __( 'Warn', 'aftercare' ),
			'fail'  => __( 'Fail', 'aftercare' ),
			'empty' => __( 'No data', 'aftercare' ),
		);

		echo '<div class="aftercare-wrap">';

		if ( '' === (string) Options::get( 'api_key' ) ) {
			echo '<p>';
			printf(
				/* translators: %s: settings page URL */
				wp_kses_post( __( 'Add your Google API key in <a href="%s">Aftercare Settings</a> to start monitoring Core Web Vitals.', 'aftercare' ) ),
				esc_url( admin_url( 'admin.php?page=aftercare-settings' ) )
			);
			echo '</p></div>';
			return;
		}

		echo '<table class="widefat striped" style="border:none;"><tbody>';
		foreach ( Status::summary() as $row ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $row['metric'] ) . '</strong></td>';
			echo '<td>' . ( null !== $row['value'] ? esc_html( Util::format_metric( $row['metric'], (float) $row['value'] ) ) : '&mdash;' ) . '</td>';
			echo '<td style="text-align:right;"><span class="aftercare-pill aftercare-pill-' . esc_attr( $row['status'] ) . '">' . esc_html( $labels[ $row['status'] ] ) . '</span></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$open = ( new IncidentRepository() )->count_open();
		if ( $open > 0 ) {
			echo '<p class="aftercare-fail" style="font-weight:600;">';
			printf(
				/* translators: %d: number of open incidents */
				esc_html( _n( '%d open incident needs attention.', '%d open incidents need attention.', $open, 'aftercare' ) ),
				(int) $open
			);
			echo '</p>';
			echo '<p><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=aftercare-incidents' ) ) . '">' . esc_html__( 'View incidents', 'aftercare' ) . '</a> ';
		} else {
			echo '<p class="aftercare-pass">' . esc_html__( 'No open incidents.', 'aftercare' ) . '</p>';
			echo '<p>';
		}
		echo '<a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=aftercare' ) ) . '">' . esc_html__( 'Open Aftercare', 'aftercare' ) . '</a></p>';

		echo '</div>';
	}
}

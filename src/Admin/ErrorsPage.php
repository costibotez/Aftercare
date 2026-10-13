<?php
namespace Aftercare\Admin;

use Aftercare\Core\Options;
use Aftercare\Core\Util;
use Aftercare\Errors\Repository as ErrorRepository;
use Aftercare\Incidents\Repository as IncidentRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PHP fatal errors grouped by signature: where, what, how often and which
 * plugin or theme most likely caused it.
 */
final class ErrorsPage {

	public function render(): void {
		$errors    = ( new ErrorRepository() )->all();
		$incidents = new IncidentRepository();

		Menu::header( __( 'PHP Errors', 'aftercare' ) );

		if ( ! Options::get( 'error_watch' ) ) {
			echo '<div class="notice notice-info inline"><p>';
			printf(
				/* translators: %s: settings page URL */
				wp_kses_post( __( 'Error watch is turned off, so new fatal errors are not recorded. Turn it on in <a href="%s">Aftercare Settings</a>.', 'aftercare' ) ),
				esc_url( admin_url( 'admin.php?page=aftercare-settings' ) )
			);
			echo '</p></div>';
		}

		if ( empty( $errors ) ) {
			echo '<p class="aftercare-subtle">' . esc_html__( 'No PHP fatal errors recorded. When a request dies of a fatal error, it appears here grouped by file and line, with the plugin or theme it most likely came from.', 'aftercare' ) . '</p>';
			Menu::footer();
			return;
		}

		echo '<table class="widefat striped aftercare-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Last seen', 'aftercare' ) . '</th>';
		echo '<th>' . esc_html__( 'Error', 'aftercare' ) . '</th>';
		echo '<th>' . esc_html__( 'Likely source', 'aftercare' ) . '</th>';
		echo '<th>' . esc_html__( 'Occurrences', 'aftercare' ) . '</th>';
		echo '<th>' . esc_html__( 'First seen', 'aftercare' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $errors as $error ) {
			$location = (string) $error['location'];
			$open     = $incidents->find_open( $location, 'ERROR', IncidentRepository::SOURCE_ERROR );
			echo '<tr>';
			echo '<td>' . esc_html( human_time_diff( strtotime( $error['last_seen'] . ' UTC' ) ) . ' ' . __( 'ago', 'aftercare' ) ) . '</td>';
			echo '<td><code>' . esc_html( $location ) . '</code><br />' . esc_html( (string) $error['message'] ) . '</td>';
			echo '<td>' . esc_html( ErrorRepository::culprit_label( (string) $error['culprit'] ) ) . '</td>';
			echo '<td>' . esc_html( Util::format_metric( 'ERROR', (float) $error['occurrences'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $error['first_seen'] ) . ' UTC</td>';
			echo '<td>';
			if ( $open ) {
				echo '<a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=aftercare-incidents&incident=' . (int) $open['id'] ) ) . '">' . esc_html__( 'Open incident', 'aftercare' ) . '</a>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<p class="aftercare-subtle">' . esc_html__( 'Only fatal errors are recorded: warnings and notices are left to the PHP error log. Messages are cut to their first line, without server paths.', 'aftercare' ) . '</p>';

		Menu::footer();
	}
}

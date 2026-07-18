<?php
namespace Aftercare\Admin;

use Aftercare\Ledger\Repository as LedgerRepository;
use Aftercare\Licensing\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filterable change timeline with pagination and Pro CSV export.
 */
final class LedgerPage {

	private const PER_PAGE = 50;

	private const TYPE_ICONS = array(
		'plugin_update'     => 'dashicons-update',
		'plugin_activate'   => 'dashicons-plus-alt',
		'plugin_deactivate' => 'dashicons-dismiss',
		'theme_update'      => 'dashicons-admin-appearance',
		'theme_switch'      => 'dashicons-randomize',
		'core_update'       => 'dashicons-wordpress',
		'settings_change'   => 'dashicons-admin-settings',
		'content_publish'   => 'dashicons-edit',
		'user_created'      => 'dashicons-admin-users',
	);

	public static function type_label( string $type ): string {
		$labels = array(
			'plugin_update'     => __( 'Plugin update', 'aftercare' ),
			'plugin_activate'   => __( 'Plugin activated', 'aftercare' ),
			'plugin_deactivate' => __( 'Plugin deactivated', 'aftercare' ),
			'theme_update'      => __( 'Theme update', 'aftercare' ),
			'theme_switch'      => __( 'Theme switch', 'aftercare' ),
			'core_update'       => __( 'Core update', 'aftercare' ),
			'settings_change'   => __( 'Settings change', 'aftercare' ),
			'content_publish'   => __( 'Content published', 'aftercare' ),
			'user_created'      => __( 'User created', 'aftercare' ),
		);
		return $labels[ $type ] ?? $type;
	}

	public function render(): void {
		$ledger  = new LedgerRepository();
		$filters = $this->current_filters();
		$page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$events = $ledger->query( $filters, self::PER_PAGE, $page );
		$total  = $ledger->count( $filters );
		$pages  = (int) ceil( $total / self::PER_PAGE );

		Menu::header( __( 'Change Ledger', 'aftercare' ) );

		echo '<form method="get" class="aftercare-filters">';
		echo '<input type="hidden" name="page" value="aftercare-ledger" />';
		echo '<select name="type"><option value="">' . esc_html__( 'All event types', 'aftercare' ) . '</option>';
		foreach ( LedgerRepository::EVENT_TYPES as $type ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $type ),
				selected( $filters['type'] ?? '', $type, false ),
				esc_html( self::type_label( $type ) )
			);
		}
		echo '</select>';
		echo '<input type="date" name="from" value="' . esc_attr( $filters['from'] ?? '' ) . '" aria-label="' . esc_attr__( 'From date', 'aftercare' ) . '" />';
		echo '<input type="date" name="to" value="' . esc_attr( $filters['to'] ?? '' ) . '" aria-label="' . esc_attr__( 'To date', 'aftercare' ) . '" />';
		echo '<button class="button">' . esc_html__( 'Filter', 'aftercare' ) . '</button>';

		if ( License::is_pro() ) {
			$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=aftercare_ledger_export' ), 'aftercare_ledger_export' );
			echo ' <a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'aftercare' ) . '</a>';
		}
		echo '</form>';

		if ( empty( $events ) ) {
			echo '<p class="aftercare-subtle">' . esc_html__( 'No events match. Site changes are recorded automatically as they happen.', 'aftercare' ) . '</p>';
		} else {
			echo '<ul class="aftercare-timeline aftercare-timeline-page">';
			foreach ( $events as $event ) {
				self::render_event_row( $event );
			}
			echo '</ul>';
		}

		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			$base = add_query_arg( array_filter( array_merge( array( 'page' => 'aftercare-ledger' ), $filters ) ), admin_url( 'admin.php' ) );
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => $base . '%_%',
						'format'  => '&paged=%#%',
						'current' => $page,
						'total'   => $pages,
					)
				) ?? ''
			);
			echo '</div></div>';
		}

		if ( ! License::is_pro() ) {
			echo '<p class="aftercare-subtle">' . esc_html__( 'Free keeps 90 days of history. Aftercare Pro keeps the ledger forever and adds CSV export.', 'aftercare' ) . '</p>';
		}

		Menu::footer();
	}

	/**
	 * @param array<string, mixed> $event
	 */
	public static function render_event_row( array $event ): void {
		$icon = self::TYPE_ICONS[ $event['event_type'] ] ?? 'dashicons-marker';
		echo '<li class="aftercare-event aftercare-event-' . esc_attr( (string) $event['event_type'] ) . '">';
		echo '<span class="dashicons ' . esc_attr( $icon ) . '"></span>';
		echo '<span class="aftercare-event-summary">' . esc_html( (string) $event['summary'] ) . '</span>';
		echo '<span class="aftercare-event-meta">';
		printf(
			/* translators: 1: actor name, 2: human time diff */
			esc_html__( 'by %1$s · %2$s ago', 'aftercare' ),
			esc_html( (string) ( $event['actor_label'] ?: __( 'system', 'aftercare' ) ) ),
			esc_html( human_time_diff( strtotime( $event['occurred_at'] . ' UTC' ) ) )
		);
		echo '</span></li>';
	}

	/**
	 * @return array{type?: string, from?: string, to?: string}
	 */
	private function current_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$filters = array();
		if ( ! empty( $_GET['type'] ) ) {
			$filters['type'] = sanitize_key( wp_unslash( $_GET['type'] ) );
		}
		foreach ( array( 'from', 'to' ) as $key ) {
			if ( ! empty( $_GET[ $key ] ) ) {
				$date = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
					$filters[ $key ] = $date;
				}
			}
		}
		return $filters;
		// phpcs:enable
	}

	/**
	 * Pro: stream the full ledger as CSV.
	 */
	public static function handle_export(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'aftercare' ) );
		}
		check_admin_referer( 'aftercare_ledger_export' );
		if ( ! License::is_pro() ) {
			wp_die( esc_html__( 'CSV export is an Aftercare Pro feature.', 'aftercare' ) );
		}

		$ledger = new LedgerRepository();
		$events = $ledger->query( array(), 100000 );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=aftercare-ledger-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'occurred_at', 'event_type', 'summary', 'actor', 'payload' ) );
		foreach ( $events as $event ) {
			fputcsv(
				$out,
				array(
					$event['occurred_at'],
					$event['event_type'],
					$event['summary'],
					$event['actor_label'],
					$event['payload'],
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}

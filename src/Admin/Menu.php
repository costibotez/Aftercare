<?php
namespace Aftercare\Admin;

use Aftercare\Incidents\Repository as IncidentRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the admin menu, screens, assets and admin-post actions.
 */
final class Menu {

	public const CAP = 'manage_options';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'admin_post_aftercare_save_settings', array( SettingsPage::class, 'handle_save' ) );
		add_action( 'admin_post_aftercare_incident_status', array( IncidentsPage::class, 'handle_status_change' ) );
		add_action( 'admin_post_aftercare_ledger_export', array( LedgerPage::class, 'handle_export' ) );
		add_action( 'admin_post_aftercare_report_generate', array( ReportsPage::class, 'handle_generate' ) );
		add_action( 'admin_post_aftercare_report_note', array( ReportsPage::class, 'handle_note' ) );
		add_action( 'admin_post_aftercare_report_send', array( ReportsPage::class, 'handle_send' ) );
		add_action( 'admin_post_aftercare_report_preview', array( ReportsPage::class, 'handle_preview' ) );
		add_action( 'admin_post_aftercare_run_now', array( $this, 'handle_run_now' ) );
	}

	public function add_pages(): void {
		$open = ( new IncidentRepository() )->count_open();
		/* translators: %d: number of open incidents */
		$badge = $open > 0 ? ' <span class="awaiting-mod">' . (int) $open . '</span>' : '';

		add_menu_page(
			__( 'Aftercare', 'aftercare' ),
			__( 'Aftercare', 'aftercare' ) . $badge,
			self::CAP,
			'aftercare',
			array( new DashboardPage(), 'render' ),
			'dashicons-chart-line',
			58
		);
		add_submenu_page( 'aftercare', __( 'Dashboard', 'aftercare' ), __( 'Dashboard', 'aftercare' ), self::CAP, 'aftercare', array( new DashboardPage(), 'render' ) );
		add_submenu_page( 'aftercare', __( 'Change Ledger', 'aftercare' ), __( 'Ledger', 'aftercare' ), self::CAP, 'aftercare-ledger', array( new LedgerPage(), 'render' ) );
		add_submenu_page( 'aftercare', __( 'Incidents', 'aftercare' ), __( 'Incidents', 'aftercare' ) . $badge, self::CAP, 'aftercare-incidents', array( new IncidentsPage(), 'render' ) );
		add_submenu_page( 'aftercare', __( 'Client Reports', 'aftercare' ), __( 'Reports', 'aftercare' ), self::CAP, 'aftercare-reports', array( new ReportsPage(), 'render' ) );
		add_submenu_page( 'aftercare', __( 'Aftercare Settings', 'aftercare' ), __( 'Settings', 'aftercare' ), self::CAP, 'aftercare-settings', array( new SettingsPage(), 'render' ) );
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, 'aftercare' ) ) {
			return;
		}
		wp_enqueue_style( 'aftercare-admin', AFTERCARE_URL . 'assets/css/admin.css', array(), AFTERCARE_VERSION );
		wp_enqueue_script( 'aftercare-admin', AFTERCARE_URL . 'assets/js/admin.js', array(), AFTERCARE_VERSION, true );
	}

	/**
	 * Debug/QA helper: run the daily pipeline immediately.
	 */
	public function handle_run_now(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'aftercare' ) );
		}
		check_admin_referer( 'aftercare_run_now' );
		( new \Aftercare\Core\Cron() )->run_daily();
		wp_safe_redirect( add_query_arg( 'aftercare_ran', '1', admin_url( 'admin.php?page=aftercare' ) ) );
		exit;
	}

	/**
	 * Shared page chrome.
	 */
	public static function header( string $title ): void {
		echo '<div class="wrap aftercare-wrap"><h1>' . esc_html( $title ) . '</h1>';
	}

	public static function footer(): void {
		echo '</div>';
	}
}

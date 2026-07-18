<?php
namespace Aftercare\Admin;

use Aftercare\Vitals\Status;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pass/warn/fail dot in the admin bar (front and back office) for
 * administrators, linking to the Aftercare dashboard.
 */
final class AdminBar {

	private const COLORS = array(
		'pass'  => '#4ade80',
		'warn'  => '#fbbf24',
		'fail'  => '#f87171',
		'empty' => '#9ca3af',
	);

	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 90 );
	}

	/**
	 * @param \WP_Admin_Bar $admin_bar
	 */
	public function add_node( $admin_bar ): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}

		$status = Status::overall();
		$titles = array(
			'pass'  => __( 'All vitals within budget', 'aftercare' ),
			'warn'  => __( 'Vitals approaching budget', 'aftercare' ),
			'fail'  => __( 'Vitals over budget', 'aftercare' ),
			'empty' => __( 'No vitals data yet', 'aftercare' ),
		);

		$admin_bar->add_node(
			array(
				'id'    => 'aftercare',
				'title' => '<span style="color:' . esc_attr( self::COLORS[ $status ] ) . ';font-size:14px;line-height:inherit;vertical-align:baseline;">&#9679;</span> Aftercare',
				'href'  => admin_url( 'admin.php?page=aftercare' ),
				'meta'  => array( 'title' => $titles[ $status ] ),
			)
		);
	}
}

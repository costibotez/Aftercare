<?php
/**
 * Aftercare uninstall: removes tables, options and cron events unless the
 * user checked "keep data on uninstall" in settings.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$aftercare_settings = get_option( 'aftercare_settings', array() );
if ( is_array( $aftercare_settings ) && ! empty( $aftercare_settings['keep_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$aftercare_prefix = $wpdb->prefix . 'aftercare_';
foreach ( array( 'vitals_samples', 'ledger_events', 'incidents', 'reports' ) as $aftercare_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$aftercare_prefix}{$aftercare_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

delete_option( 'aftercare_settings' );
delete_option( 'aftercare_schema_version' );
delete_option( 'aftercare_cron_last_run' );
delete_option( 'aftercare_plugin_versions' );
delete_option( 'aftercare_rum_buffer' );
delete_option( 'aftercare_onboarding_dismissed' );

// Transients (CrUX caches).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_aftercare\\_%' OR option_name LIKE '\\_transient\\_timeout\\_aftercare\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery

wp_clear_scheduled_hook( 'aftercare_daily_tasks' );
wp_clear_scheduled_hook( 'aftercare_weekly_digest' );

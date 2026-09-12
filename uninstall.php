<?php
/**
 * Uninstall — only deletes settings if the user opted in.
 *
 * Scope: this plugin's OWN `devdalyt_*` rows plus a coordinated shared-core cleanup.
 * The suite-shared connection state (`devdcorev1_site_id` / `_site_token` / `_account_id` /
 * `_account_email` / `_connected_at`) is deliberately NOT deleted here — other DevDome
 * plugins on the same site read those rows, and the shared-core helper below removes the
 * shared artifacts only when this is the LAST DevDome plugin installed.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Housekeeping that runs on EVERY uninstall, before the opt-in guard: unscheduling our cron
// event and removing the generated static-script cache file are not "user data" — leaving
// them behind would orphan a daily cron with no handler and a stray file in uploads/ forever
// (audit 2026-08-05; wp.org reviewers check for orphaned cron events).
wp_clear_scheduled_hook( 'devdalyt_fp_refresh' );
$devdalyt_fp = get_option( 'devdalyt_fp_paths', null );
if ( is_array( $devdalyt_fp ) && ! empty( $devdalyt_fp['dir'] ) && preg_match( '/^[a-f0-9]{10}$/', (string) $devdalyt_fp['dir'] ) ) {
	$devdalyt_up  = wp_get_upload_dir();
	$devdalyt_dir = trailingslashit( $devdalyt_up['basedir'] ) . $devdalyt_fp['dir'];
	$devdalyt_file = ! empty( $devdalyt_fp['file'] ) && preg_match( '/^[a-f0-9]{8}\.js$/', (string) $devdalyt_fp['file'] ) ? trailingslashit( $devdalyt_dir ) . $devdalyt_fp['file'] : '';
	// never through a link (review 2026-09-11): a linked folder or file would delete outside uploads;
	// and the RESOLVED folder must still sit inside the resolved uploads folder (linked ancestors).
	$devdalyt_real = realpath( $devdalyt_dir );
	$devdalyt_base = realpath( $devdalyt_up['basedir'] );
	$devdalyt_inside = $devdalyt_real && $devdalyt_base && 0 === strpos( trailingslashit( $devdalyt_real ), trailingslashit( $devdalyt_base ) );
	if ( $devdalyt_inside && '' !== $devdalyt_file && ! is_link( $devdalyt_dir ) && ! is_link( $devdalyt_file ) && is_file( $devdalyt_file ) ) {
		wp_delete_file( $devdalyt_file );
	}
	if ( $devdalyt_inside && is_dir( $devdalyt_dir ) && ! is_link( $devdalyt_dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own empty random dir at uninstall
		@rmdir( $devdalyt_dir );
	}
}

if ( ! get_option( 'devdalyt_delete_data_on_uninstall', false ) ) {
	return;
}

delete_option( 'devdalyt_tracking_enabled' );
delete_option( 'devdalyt_click_tracking_enabled' );
delete_option( 'devdalyt_outbound_tracking_enabled' );
delete_option( 'devdalyt_ai_tracking_enabled' );
delete_option( 'devdalyt_bot_tracking_enabled' );
delete_option( 'devdalyt_dnt_admins' );
delete_option( 'devdalyt_excluded_roles' );
delete_option( 'devdalyt_debug_mode' );
delete_option( 'devdalyt_respect_dnt' );
delete_option( 'devdalyt_returning_visitors' );
delete_option( 'devdalyt_api_endpoint' );
delete_option( 'devdalyt_status_endpoint' );
delete_option( 'devdalyt_stats_endpoint' );
delete_option( 'devdalyt_script_url' );
delete_option( 'devdalyt_dashboard_url' );
delete_option( 'devdalyt_plugin_version' );
delete_option( 'devdalyt_last_connection_test' );
delete_option( 'devdalyt_last_event_sent_at' );
delete_option( 'devdalyt_migrated_ids' );
// First-party delivery (1.0.3+): the switch and the random resource names. The cron event
// and the uploads/ file were already removed above, before the opt-in guard.
delete_option( 'devdalyt_first_party' );
delete_option( 'devdalyt_fp_auth_failed' );
delete_option( 'devdalyt_user_disconnected' );
delete_option( 'devdalyt_remote_disconnected' );
delete_option( 'devdalyt_fp_paths' );
delete_option( 'devdalyt_delete_data_on_uninstall' );
delete_option( 'devdalyt_relay_secret' ); // the fleet relay credential this plugin copied (review 2026-09-11)

// This plugin's transients use dynamic keys (per-request-token connect handles, per-IP
// beacon rate buckets, the update-metadata cache), so they can't be listed one by one —
// sweep them by the plugin's own prefix. `_` is a LIKE wildcard and must be escaped.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_devdalyt\_%' OR option_name LIKE '\_transient\_timeout\_devdalyt\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall-time cleanup of dynamic transient keys

// Coordinate shared-core cleanup: the daily feed cron, the cached feed option and the shared
// hub/account rows are removed only if this is the LAST DevDome plugin still installed, so
// uninstalling this one never orphans the core for its siblings.
require_once __DIR__ . '/lib/devdome-core/uninstall.php';
devdcorev1_uninstall_cleanup( 'devdome-analytics/devdome-analytics.php' );

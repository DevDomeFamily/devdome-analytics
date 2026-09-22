<?php
/**
 * Plugin Name: DevDome Analytics
 * Plugin URI: https://devdome.com/wp-plugins/analytics/
 * Description: Traffic analytics, visitor statistics and click tracking for WordPress, with AI referral detection and bot-filtered numbers.
 * Version: 1.1.2
 * Author: DevDome
 * Author URI: https://devdome.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: devdome-analytics
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * This plugin is a lightweight connector. The dashboard lives on DevDome — the
 * plugin only connects the site, verifies it, injects the tracking script, and
 * reports basic status. It does NOT build an analytics dashboard inside WordPress.
 *
 * Identifiers: everything this plugin OWNS carries the single uninterrupted prefix
 * `devdalyt` (DEVD + "alyt" from an·ALYT·ics) / `DEVDALYT_`. The suite-shared
 * connection state and the vendored shared library keep the `devdcorev1_` family —
 * several plugins read the same rows, so those must never be plugin-prefixed.
 */

defined( 'ABSPATH' ) || exit;

// The WordPress.org zip ships this marker file (defines DEVDCOREV1_WPORG_BUILD) so the
// same codebase can switch off self-hosted updates and other non-wp.org behavior.
if ( file_exists( __DIR__ . '/wporg-build.php' ) ) {
	require __DIR__ . '/wporg-build.php';
}

define( 'DEVDALYT_VERSION', '1.1.2' );
define( 'DEVDALYT_FILE', __FILE__ );
define( 'DEVDALYT_DIR', plugin_dir_path( __FILE__ ) );
define( 'DEVDALYT_URL', plugin_dir_url( __FILE__ ) );

// Service URLs — ALL on the analytics.devdome.com Cloudflare Worker (consolidated 2026-06-08 into one
// pipeline + one bot-detection brain; moved off analytics.processdome.com 2026-06-10 for the DevDome launch).
define( 'DEVDALYT_DEFAULT_API_ENDPOINT', 'https://analytics.devdome.com/api/event' );
define( 'DEVDALYT_DEFAULT_STATUS_ENDPOINT', 'https://analytics.devdome.com/api/plugin/status' );
define( 'DEVDALYT_DEFAULT_STATS_ENDPOINT', 'https://analytics.devdome.com/api/plugin/stats' );
define( 'DEVDALYT_DEFAULT_SCRIPT_URL', 'https://analytics.devdome.com/track.js' );
define( 'DEVDALYT_DEFAULT_DASHBOARD_URL', 'https://analytics.devdome.com/admin' );
define( 'DEVDALYT_DEFAULT_ACCOUNT_URL', 'https://devdome.com' );

// Shared bot-detection core + the DevDome Tools hub (vendored, version-guarded — only the
// highest copy across the installed suite loads). Required so the Dashboard appears even on
// an analytics-only site. Must stay above any early return.
require_once __DIR__ . '/lib/devdome-core/loader.php';

// Legacy-identifier migration ships only in fleet/self-hosted builds (.wporg-strip):
// wp.org installs are fresh and have no old-prefix data to move.
if ( file_exists( DEVDALYT_DIR . 'includes/migrate.php' ) ) {
	require_once DEVDALYT_DIR . 'includes/migrate.php';
}

require_once DEVDALYT_DIR . 'includes/db-guard.php'; // DESIGN.md 24 / 24.5: failed-query guard + proved option writes
require_once DEVDALYT_DIR . 'includes/class-devdome-analytics.php';

// Request-wide database guard (DESIGN.md 24): every failed query is recorded before wpdb::query() clears it.
add_filter( 'query', 'devdalyt_db_guard_record', 1 );

// "Report this error" (core 1.7.0): the plugin keeps no log, so a report about it carries the connection
// state instead (flags and timestamps only), and every secret this site holds is masked before it leaves.
add_filter( 'devdcorev1_error_report_log', function ( $lines, $plugin ) {
	if ( 'devdome-analytics' !== $plugin ) {
		return $lines;
	}
	$ent = get_transient( 'devdalyt_fp_entitlement' );
	return array(
		'connected=' . ( class_exists( 'DEVDALYT_Analytics' ) && DEVDALYT_Analytics::is_connected_cached() ? '1' : '0' ),
		'connected_at=' . (string) get_option( 'devdcorev1_connected_at', '' ),
		'site_id=' . (string) get_option( 'devdcorev1_site_id', '' ),
		'has_token=' . ( '' !== (string) get_option( 'devdcorev1_site_token', '' ) ? '1' : '0' ),
		'user_disconnected=' . ( get_option( 'devdalyt_user_disconnected', false ) ? '1' : '0' ),
		'remote_disconnected=' . ( get_option( 'devdalyt_remote_disconnected', false ) ? '1' : '0' ),
		'first_party=' . ( get_option( 'devdalyt_first_party', false ) ? '1' : '0' ),
		'fp_entitlement=' . ( is_array( $ent ) ? wp_json_encode( $ent ) : 'none' ),
		'permalinks=' . ( '' !== (string) get_option( 'permalink_structure', '' ) ? 'pretty' : 'plain' ),
	);
}, 10, 2 );
add_filter( 'devdcorev1_error_report_redact', function ( $text, $plugin ) {
	return 'devdome-analytics' === $plugin ? devdalyt_redact( $text ) : $text;
}, 10, 2 );
require_once DEVDALYT_DIR . 'includes/abilities.php'; // WordPress Abilities API (6.9+): registers nothing on older versions

// Self-hosted update feed (api.devdome.com). The plugin checks this JSON and WP shows
// "Update available" when it advertises a newer version — no manual re-upload. The wp.org
// build drops the updater file from the zip and defines DEVDCOREV1_WPORG_BUILD (wp.org itself
// delivers updates there), so the whole block is skipped.
if ( ! defined( 'DEVDCOREV1_WPORG_BUILD' ) ) {
	define( 'DEVDALYT_UPDATE_URL', 'https://api.devdome.com/plugin-updates/devdome-analytics.json' );
	// Wire up self-hosted updates (admin + cron only — the update check never runs on the front end).
	if ( ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) && file_exists( DEVDALYT_DIR . 'includes/class-devdome-updater.php' ) ) {
		require_once DEVDALYT_DIR . 'includes/class-devdome-updater.php';
		new DEVDALYT_Updater( __FILE__, 'devdome-analytics', DEVDALYT_UPDATE_URL );
	}
}

/**
 * Full-path tracking: tag EVERY same-site server redirect (ANY plugin/setup) with
 * ?_dd=<status>:<from-path> so the landing page's tracker records the skipped hop — giving the
 * complete path referrer → redirect hop → landing in the dashboard.
 *
 * SEO-SAFE by construction: crawlers are skipped (they'd otherwise receive a ?_dd= URL and could
 * index it); also skips admin/cron/REST, non-GET, cross-domain exits (our tracker isn't there), and
 * already-tagged URLs. track.js strips the param via replaceState so real users never keep it. This
 * is pure URL annotation on the redirect target — it never blocks or changes the destination.
 */
add_filter( 'wp_redirect', 'devdalyt_stitch_redirect', 5, 2 );
function devdalyt_stitch_redirect( $location, $status ) {
	if ( ! is_string( $location ) || '' === $location ) { return $location; }
	if ( false !== strpos( $location, '_dd=' ) ) { return $location; }          // already tagged
	if ( is_admin() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return $location; }
	if ( empty( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) { return $location; }
	if ( ! DEVDALYT_Tracker::is_connected_cheap() ) { return $location; }               // unconnected sites never tag redirects
	if ( ! (bool) get_option( 'devdalyt_tracking_enabled', true ) ) { return $location; }
	// The same opt-outs as the page tracker (DeepSeek round 5): a Do Not Track / Global Privacy Control visitor, a
	// logged-in administrator under "Do not track admins" and a user in an excluded role never get a tagged URL
	// (the tracker would not run on the landing page, so the tag would sit in the address bar for nothing).
	if ( get_option( 'devdalyt_respect_dnt', true )
		&& ( ( isset( $_SERVER['HTTP_DNT'] ) && '1' === (string) $_SERVER['HTTP_DNT'] ) || ( isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === (string) $_SERVER['HTTP_SEC_GPC'] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared to a literal
		return $location;
	}
	if ( is_user_logged_in() ) {
		if ( get_option( 'devdalyt_dnt_admins', true ) && current_user_can( 'manage_options' ) ) { return $location; }
		$excluded = (array) get_option( 'devdalyt_excluded_roles', array() );
		if ( $excluded && array_intersect( (array) wp_get_current_user()->roles, $excluded ) ) { return $location; }
	}
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	// SEO-safe: never tag a crawler's redirect (would pollute the index with ?_dd= URLs).
	if ( '' === $ua || ( function_exists( 'devdcorev1_ua_is_bot' ) && devdcorev1_ua_is_bot( $ua ) ) ) { return $location; }
	// The shared feeds are permanently off in the WordPress.org build (Codex round 7): the plugin's own crawler map
	// plus the generic crawler words decide too, so Googlebot never indexes a ?_dd= URL.
	if ( class_exists( 'DEVDALYT_Bot_Detector' ) ) {
		foreach ( DEVDALYT_Bot_Detector::BOTS as $bot_name => $bot_type ) {
			if ( false !== stripos( $ua, $bot_name ) ) { return $location; }
		}
	}
	if ( preg_match( '/bot|crawl|spider|slurp|preview|fetch|headless|lighthouse|monitor|python-requests|curl\//i', $ua ) ) { return $location; }
	// Same-site landings only (the tracker runs there). A relative location (no host) is same-site.
	$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$dest = strtolower( (string) wp_parse_url( $location, PHP_URL_HOST ) );
	if ( '' !== $dest && $dest !== $home ) { return $location; }
	// Sanitized with a printable-ASCII allowlist rather than sanitize_text_field()/esc_url_raw():
	// those strip %XX escapes or bare colons in the query, corrupting the recorded from-path. The
	// allowlist removes control bytes and anything a URI cannot legally contain, and the value is
	// rawurlencode()d before it reaches the redirect URL below.
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by the printable-ASCII allowlist on this line (see comment above); recognized sanitizers corrupt %XX escapes in the recorded path.
	$from = isset( $_SERVER['REQUEST_URI'] ) ? preg_replace( '/[\x00-\x20\x7F]/', '', (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // control bytes and spaces only: UTF-8 slugs stay (DeepSeek round 4), rawurlencoded below
	if ( '' === $from ) { return $location; }
	// Path only (DeepSeek round 7): the query string of the redirecting URL can hold a password-reset token, a
	// magic link or a nonce, and track.js would post it on as transit_from. The hop is the path.
	$from = (string) strtok( $from, '?' );
	if ( '' === $from ) { return $location; }
	$from = preg_replace( '/[?&]_dd=[^&]*/', '', $from );
	$dd   = rawurlencode( ( $status ? (string) $status : '302' ) . ':' . $from );
	// The fragment stays a fragment (DeepSeek round 5): the parameter goes before "#", never inside it.
	$frag = '';
	$hash = strpos( $location, '#' );
	if ( false !== $hash ) {
		$frag     = substr( $location, $hash );
		$location = substr( $location, 0, $hash );
	}
	$sep  = ( false === strpos( $location, '?' ) ) ? '?' : '&';
	return $location . $sep . '_dd=' . $dd . $frag;
}

/**
 * Create any missing default-option rows (no-op for existing ones). Shared by activation + upgrade.
 *
 * Every key is written as a LITERAL at its add_option()/get_option() call site: the wp.org review
 * scanner cannot resolve a variable key and assumes the worst, so no loop over a key => value map.
 *
 * The `devdcorev1_*` rows are the suite-shared connection state (site identity + account link).
 * They are shared by design — several DevDome plugins read the same rows — so they keep the
 * shared-core prefix instead of this plugin's.
 */
function devdalyt_seed_options() {
	// Suite-shared connection state.
	if ( null === get_option( 'devdcorev1_site_id', null ) )       { add_option( 'devdcorev1_site_id', '' ); }
	if ( null === get_option( 'devdcorev1_site_token', null ) )    { add_option( 'devdcorev1_site_token', '' ); }
	if ( null === get_option( 'devdcorev1_account_id', null ) )    { add_option( 'devdcorev1_account_id', '' ); }
	if ( null === get_option( 'devdcorev1_account_email', null ) ) { add_option( 'devdcorev1_account_email', '' ); }
	if ( null === get_option( 'devdcorev1_connected_at', null ) )  { add_option( 'devdcorev1_connected_at', '' ); }

	// Tracking switches.
	if ( null === get_option( 'devdalyt_tracking_enabled', null ) )          { add_option( 'devdalyt_tracking_enabled', true ); }
	if ( null === get_option( 'devdalyt_click_tracking_enabled', null ) )    { add_option( 'devdalyt_click_tracking_enabled', true ); }
	if ( null === get_option( 'devdalyt_outbound_tracking_enabled', null ) ) { add_option( 'devdalyt_outbound_tracking_enabled', true ); }
	if ( null === get_option( 'devdalyt_ai_tracking_enabled', null ) )       { add_option( 'devdalyt_ai_tracking_enabled', true ); }
	if ( null === get_option( 'devdalyt_bot_tracking_enabled', null ) )      { add_option( 'devdalyt_bot_tracking_enabled', true ); }
	if ( null === get_option( 'devdalyt_dnt_admins', null ) )                { add_option( 'devdalyt_dnt_admins', true ); }
	if ( null === get_option( 'devdalyt_respect_dnt', null ) )               { add_option( 'devdalyt_respect_dnt', true ); }
	// Empty by default so upgrades never silently change what an existing fleet site tracks;
	// fresh installs get administrator+editor from the activation hook below.
	if ( null === get_option( 'devdalyt_excluded_roles', null ) )            { add_option( 'devdalyt_excluded_roles', array() ); }
	if ( null === get_option( 'devdalyt_debug_mode', null ) )                { add_option( 'devdalyt_debug_mode', false ); }
	// Returning-visitor tracking. ON = a first-party cookie + localStorage id, so the same
	// person is recognised across days and a click can be tied back to their visit (this is
	// what affiliate attribution needs). OFF = COOKIELESS: nothing at all is written to the
	// visitor's device, and no consent banner is needed for DevDome.
	//
	// TRUE here on purpose, same reasoning as excluded_roles above: an UPGRADE must never
	// silently change how an existing site tracks. A live affiliate site that quietly stopped
	// setting the cookie would lose returning visitors and click-to-visit attribution
	// overnight, and nobody asked it to. Existing installs therefore keep the cookie.
	//
	// FRESH installs get FALSE (cookieless) from the activation hook below: the honest default
	// for a new customer is "we store nothing on your visitors' devices", and turning this on
	// is a deliberate, warned choice.
	if ( null === get_option( 'devdalyt_returning_visitors', null ) )        { add_option( 'devdalyt_returning_visitors', true ); }

	// DevDome-managed service URLs + bookkeeping.
	if ( null === get_option( 'devdalyt_api_endpoint', null ) )              { add_option( 'devdalyt_api_endpoint', DEVDALYT_DEFAULT_API_ENDPOINT ); }
	if ( null === get_option( 'devdalyt_status_endpoint', null ) )           { add_option( 'devdalyt_status_endpoint', DEVDALYT_DEFAULT_STATUS_ENDPOINT ); }
	if ( null === get_option( 'devdalyt_stats_endpoint', null ) )            { add_option( 'devdalyt_stats_endpoint', DEVDALYT_DEFAULT_STATS_ENDPOINT ); }
	if ( null === get_option( 'devdalyt_script_url', null ) )                { add_option( 'devdalyt_script_url', DEVDALYT_DEFAULT_SCRIPT_URL ); }
	if ( null === get_option( 'devdalyt_dashboard_url', null ) )             { add_option( 'devdalyt_dashboard_url', DEVDALYT_DEFAULT_DASHBOARD_URL ); }
	// devdalyt_plugin_version is NOT seeded here: it is written LAST by activation / the upgrade path, only when every default landed (Codex round 2).
	if ( null === get_option( 'devdalyt_last_connection_test', null ) )      { add_option( 'devdalyt_last_connection_test', '' ); }
	if ( null === get_option( 'devdalyt_last_event_sent_at', null ) )        { add_option( 'devdalyt_last_event_sent_at', '' ); }
	if ( null === get_option( 'devdalyt_delete_data_on_uninstall', null ) )  { add_option( 'devdalyt_delete_data_on_uninstall', false ); }
	if ( null === get_option( 'devdalyt_first_party', null ) )               { add_option( 'devdalyt_first_party', false ); }
}

// Upgrade path: when the stored version lags, seed any options added in newer versions (activation
// doesn't re-run on plugin updates) so new switches have real rows and can be toggled off.
/** The DevDome-managed service URLs, proved (DESIGN.md 24.5). @return bool every one landed. */
function devdalyt_sync_service_urls() {
	$ok = devdalyt_option_write( 'devdalyt_api_endpoint', DEVDALYT_DEFAULT_API_ENDPOINT );
	$ok = devdalyt_option_write( 'devdalyt_status_endpoint', DEVDALYT_DEFAULT_STATUS_ENDPOINT ) && $ok;
	$ok = devdalyt_option_write( 'devdalyt_stats_endpoint', DEVDALYT_DEFAULT_STATS_ENDPOINT ) && $ok;
	$ok = devdalyt_option_write( 'devdalyt_script_url', DEVDALYT_DEFAULT_SCRIPT_URL ) && $ok;
	return devdalyt_option_write( 'devdalyt_dashboard_url', DEVDALYT_DEFAULT_DASHBOARD_URL ) && $ok;
}

/**
 * True when every seeded default has a row AND the identity holds real values (Codex round 3): the seed writes placeholder
 * rows ('' token) first, so "a row exists" is not "the intended value landed". The identity is read through get_option():
 * on a subdirectory multisite core 1.7.0 routes those two rows to the main site, where a raw row read of this blog finds nothing.
 */
function devdalyt_seed_landed() {
	foreach ( array( 'devdalyt_tracking_enabled', 'devdalyt_click_tracking_enabled', 'devdalyt_outbound_tracking_enabled', 'devdalyt_ai_tracking_enabled', 'devdalyt_bot_tracking_enabled', 'devdalyt_dnt_admins', 'devdalyt_respect_dnt', 'devdalyt_excluded_roles', 'devdalyt_debug_mode', 'devdalyt_returning_visitors', 'devdalyt_first_party', 'devdalyt_delete_data_on_uninstall', 'devdalyt_last_connection_test', 'devdalyt_last_event_sent_at' ) as $k ) {
		$row = devdalyt_option_row( $k );
		if ( ! is_array( $row ) || ! $row[0] ) {
			return false;
		}
	}
	if ( '' === (string) get_option( 'devdcorev1_site_id', '' ) || strlen( (string) get_option( 'devdcorev1_site_token', '' ) ) < 20 ) {
		return false; // identity not provisioned = the site cannot connect; keep the activation path open
	}
	return 1 !== (int) get_option( 'devdalyt_init_pending', 0 ); // 1 = fresh-install defaults still owed; 2 = landed (the marker outlives the version write, Codex round 10)
}

/** The fresh-install defaults, proved; shared by activation and the retry on admin_init (Codex round 3). @return bool both landed. */
function devdalyt_fresh_defaults() {
	$ok = devdalyt_option_write( 'devdalyt_excluded_roles', array( 'administrator', 'editor' ) );
	// A brand-new site starts COOKIELESS: nothing is written to any visitor's device, so the
	// owner needs no cookie banner for DevDome. Turning returning-visitor tracking on is their
	// explicit, warned decision. Existing sites are untouched (see the option default).
	return devdalyt_option_write( 'devdalyt_returning_visitors', false ) && $ok;
}

/** Site id + token when missing, proved (a token that did not land leaves the connect proof empty). @return bool */
function devdalyt_provision_identity() {
	$ok = true;
	if ( '' === (string) get_option( 'devdcorev1_site_id', '' ) ) {
		$ok = devdalyt_option_write( 'devdcorev1_site_id', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}
	if ( strlen( (string) get_option( 'devdcorev1_site_token', '' ) ) < 20 ) {
		$ok = devdalyt_option_write( 'devdcorev1_site_token', wp_generate_password( 40, false ) ) && $ok;
	}
	return $ok;
}

add_action( 'admin_init', function () {
	// First-party delivery without its daily refresh (a wp_schedule_event that failed at activation) would serve a stale
	// tracker copy after every plugin update: re-provision until the schedule exists (DeepSeek round 9).
	if ( get_option( 'devdalyt_first_party', false ) && ! wp_next_scheduled( 'devdalyt_fp_refresh' ) && class_exists( 'DEVDALYT_Tracker' ) && DEVDALYT_Tracker::is_connected_cheap() ) {
		DEVDALYT_Tracker::fp_provision();
	}
	if ( get_option( 'devdalyt_plugin_version' ) !== DEVDALYT_VERSION ) {
		// No version row = this site meets the plugin for the FIRST time here (a network subsite never runs the
		// activation hook; Codex round 4): it gets the fresh privacy defaults exactly like an activation.
		$fresh = ( null === get_option( 'devdalyt_plugin_version', null ) );
		devdalyt_seed_options();
		if ( $fresh && ! get_option( 'devdalyt_init_pending', false ) ) {
			// The marker is written BEFORE the identity is provisioned, 2 when the defaults landed and 1 when they did not:
			// without it a failed identity write left the site "fresh" and the defaults were rewritten over the owner's
			// later choices on every admin load (Codex round 9).
			if ( ! devdalyt_option_write( 'devdalyt_init_pending', devdalyt_fresh_defaults() ? 2 : 1 ) ) {
				return; // the marker did not land: no version row this load, the next one retries (DeepSeek round 6)
			}
		}
		// Activation could not prove its writes (Codex round 3): redo the fresh-install defaults and the identity
		// with the INTENDED values, and clear the marker only when they landed.
		$pending = (int) get_option( 'devdalyt_init_pending', 0 );
		if ( 1 === $pending ) {
			// 1 = defaults owed, 2 = defaults landed and only the identity / the marker's delete are owed (DeepSeek rounds 7-8:
			// a delete or an identity write that kept failing rewrote the owner's later role / cookie choices on every load).
			// The step to 2 is written the moment the defaults land, BEFORE the identity is provisioned.
			if ( devdalyt_fresh_defaults() && devdalyt_option_write( 'devdalyt_init_pending', 2 ) ) {
				$pending = 2;
			}
		}
		if ( 1 === $pending ) {
			return; // the fresh privacy defaults are still owed: no identity (the site must not become connectable first), no version row (DeepSeek round 11)
		}
		if ( 2 === $pending && ! devdalyt_provision_identity() ) {
			return; // identity still owed: no version row this load either; the next one retries without touching the defaults
		}
		devdalyt_provision_identity();
		// DevDome-managed service URLs are not user-editable — re-sync to current defaults on upgrade
		// (everything lives on analytics.devdome.com since 2026-06-10). The version row is written LAST and only
		// when every default and URL landed, so a lost write is retried on the next admin load instead of masked.
		// The marker goes only AFTER the version row landed (Codex round 10): while it is 2, a failed URL or version
		// write never makes the site "fresh" again, so the defaults are never rewritten over the owner's choices.
		if ( devdalyt_sync_service_urls() && devdalyt_seed_landed() && devdalyt_option_write( 'devdalyt_plugin_version', DEVDALYT_VERSION ) && $pending ) {
			devdalyt_option_delete( 'devdalyt_init_pending' );
		}
	} elseif ( get_option( 'devdalyt_init_pending', false ) ) {
		devdalyt_option_delete( 'devdalyt_init_pending' ); // the version row landed on an earlier load, only the marker's delete is owed
	}
} );

register_activation_hook( __FILE__, function () {
	// Fresh install = no version row yet (checked BEFORE seeding). Only then does the
	// excluded-roles default apply — re-activating an existing site changes nothing.
	$fresh = ( null === get_option( 'devdalyt_plugin_version', null ) );
	devdalyt_seed_options();
	$landed = true;
	if ( $fresh ) {
		// Defaults already proved by an earlier, unfinished attempt (marker 2) are KEPT: a re-activation must not rewrite
		// the roles or cookie choices made since (Codex round 11).
		$landed = 2 === (int) get_option( 'devdalyt_init_pending', 0 ) || devdalyt_fresh_defaults();
		// 2 = defaults landed, identity owed; 1 = defaults owed. Written BEFORE the identity so a failed identity write
		// never re-runs the defaults over the owner's later choices (Codex round 9; the admin_init path finishes it).
		$landed = devdalyt_option_write( 'devdalyt_init_pending', $landed ? 2 : 1 ) && $landed;
	}
	// Auto-provision this site's identity so connecting is one click (no pasting):
	// site_id is the domain, site_token is a random secret kept server-side only.
	// Both are suite-shared (devdcorev1_*) — one connect covers every DevDome plugin.
	// Proved (DeepSeek round 2): a token that did not land leaves the connect proof empty and the site unable to connect;
	// the version gate below then keeps the activation path open for the next admin load.
	$landed = $landed && devdalyt_provision_identity(); // no identity while the fresh defaults are owed (DeepSeek round 11): the connect proof stays empty until they landed
	// Deactivation clears the daily first-party refresh; a re-activation with the feature still on must bring it
	// back, or the local tracker copy never refreshes again (Codex round 8). fp_provision() schedules only while
	// the site is connected and the feature is on, and proves its writes.
	if ( get_option( 'devdalyt_first_party', false ) && class_exists( 'DEVDALYT_Tracker' ) ) {
		DEVDALYT_Tracker::fp_provision();
	}
	// Service URLs are DevDome-managed (not user-editable) — keep them current so an
	// upgrade from older endpoints (e.g. the testing host) always points at production.
	// The version row lands LAST and only when every default landed (DeepSeek round 1): otherwise the
	// admin_init upgrade path re-seeds on the next load instead of leaving a fresh site half-configured.
	if ( devdalyt_sync_service_urls() && $landed && devdalyt_seed_landed() && devdalyt_option_write( 'devdalyt_plugin_version', DEVDALYT_VERSION ) && $fresh ) {
		devdalyt_option_delete( 'devdalyt_init_pending' ); // after the version row (Codex round 10); a lost delete is redone by admin_init
	}
	// Bot detection must work from the first request: fetch the shared devdome-core
	// feed synchronously now instead of waiting for cron (which may be disabled).
	if ( function_exists( 'devdcorev1_refresh_feeds' ) ) {
		devdcorev1_refresh_feeds();
	}
} );

// Deactivation must take the daily First-Party refresh event with it — nothing else
// unschedules it, and wp.org reviewers check for cron events that outlive their handler.
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'devdalyt_fp_refresh' );
} );

// Serve the domain-verification file so DevDome can confirm site ownership — the plugin
// being installed is itself the proof. Matches the dashboard's /.well-known check, so a
// plugin-connected site shows as "Connected" and stays green on the hourly re-ping.
add_action( 'init', function () {
	$path = isset( $_SERVER['REQUEST_URI'] )
		? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH )
		: '';
	// Connect proof (1.0.4): the sha256 of THIS site's token, served BEFORE the site is
	// connected — it is what makes connecting possible. The DevDome worker adopts the token a
	// fresh install presents only when this file proves the site itself knows it (only someone
	// who controls the site can serve it), which keeps no-first-caller-wins while unbreaking
	// self-serve installs. The hash is one-way: reading it reveals nothing usable, and
	// ownership still binds only through the account-authorize / snippet proofs.
	if ( '/.well-known/devdome-connect-proof.txt' === $path ) {
		$devdalyt_tok = (string) get_option( 'devdcorev1_site_token', '' );
		if ( '' !== $devdalyt_tok ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( hash( 'sha256', $devdalyt_tok ) );
			exit;
		}
		return;
	}
	if ( '/.well-known/devdome-analytics.txt' !== $path ) {
		return; // every other request: no predicate, no identity rewrite, no remote call (Codex round 3)
	}
	if ( ! DEVDALYT_Tracker::is_connected_cheap() ) {
		return; // nothing to verify until the user links the site
	}
	{
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'devdome-analytics-verify-v1';
		exit;
	}
} );

// Click-Fraud bridge: contribute this site's bot-visit count (fake pageviews) to DevDome
// Bot Protection's Click-Fraud tab. `devdome_click_fraud_sources` is Bot Protection's hook,
// not ours — we only add_filter() to it, so the name stays as that plugin defines it.
// The analytics data lives on the DevDome service, so this fetches the site's bot count from
// the stats endpoint (cached 1h, admin-only) and reports it as a "visit" figure — kept separate
// from blocked clicks. Graceful no-op until the site is connected or if the service is unreachable.
add_filter( 'devdome_click_fraud_sources', function ( $sources ) {
	if ( ! is_admin() ) {
		return $sources;
	}
	$site     = (string) get_option( 'devdcorev1_site_id', '' );
	$endpoint = (string) get_option( 'devdalyt_stats_endpoint', '' );
	// site_id is auto-provisioned on activation, so it alone doesn't mean "connected" —
	// require the real connect handshake before any stats call leaves the site.
	if ( '' === $site || '' === $endpoint || ! DEVDALYT_Tracker::is_connected_cheap() ) {
		return $sources; // not connected yet (cheap predicate: this filter runs on admin renders of another plugin)
	}
	$bots = get_transient( 'devdalyt_cf_bot_visits' );
	if ( false === $bots ) {
		$bots = -1; // sentinel: attempted but no usable answer
		// The same token-authenticated stats call the Overview tiles use (Codex round 4): the unauthenticated GET
		// this used to send was refused by the service, so the bot figure never reached Bot Protection.
		$data = ( new DEVDALYT_API() )->get_stats( 30 );
		if ( is_array( $data ) && isset( $data['bots'] ) && null !== $data['bots'] ) {
			$bots = max( 0, (int) $data['bots'] );
		}
		set_transient( 'devdalyt_cf_bot_visits', $bots, HOUR_IN_SECONDS );
	}
	if ( (int) $bots >= 0 ) {
		$sources[] = array(
			'key'    => 'analytics',
			'kind'   => 'visit',
			'label'  => 'Bot visits (DevDome Analytics)',
			'count'  => (int) $bots,
			'period' => 30,
			'url'    => (string) get_option( 'devdalyt_dashboard_url', '' ),
		);
	}
	return $sources;
} );

// DevDome Tools hub: register this plugin in the suite dashboard.
add_filter( 'devdcorev1_suite_register', function ( $r ) {
	$r['devdome-analytics'] = array(
		'slug'     => 'devdome-analytics',
		'name'     => 'Analytics',
		'desc'     => 'Privacy-first traffic analytics with real, bot-filtered stats.',
		'icon'     => 'dashicons-chart-area',
		'version'  => defined( 'DEVDALYT_VERSION' ) ? DEVDALYT_VERSION : '',
		'page'     => 'devdome-analytics',
		'position' => 10,
		'schema'   => 1,
		'tiles'    => function () {
			// Read-only: options + the click-fraud bridge's cached transient (no query on render).
			$href     = 'admin.php?page=devdome-analytics';
			$tracking = (int) get_option( 'devdalyt_tracking_enabled', 0 );
			$bv       = get_transient( 'devdalyt_cf_bot_visits' );
			$bots     = ( false !== $bv && (int) $bv >= 0 ) ? (int) $bv : null;
			return array(
				array( 'label' => 'Tracking',         'value' => $tracking ? 'On' : 'Off', 'fmt' => 'text', 'state' => $tracking ? 'good' : 'idle', 'href' => $href ),
				array( 'label' => 'Bot visits (30d)', 'value' => $bots,                   'fmt' => 'int',  'state' => 'idle',                     'href' => $href ),
			);
		},
		'health'   => function () {
			$href      = admin_url( 'admin.php?page=devdome-analytics' );
			$connected = DEVDALYT_Analytics::is_connected_cached(); // cached only: a hub render never verifies remotely (Codex round 4)
			$tracking  = (int) get_option( 'devdalyt_tracking_enabled', 0 );
			$score     = $connected ? ( $tracking ? 100 : 60 ) : 0;
			$issues    = array();
			if ( ! $connected ) {
				$issues[] = array( 'problem' => 'Analytics is not connected.', 'why_it_matters' => 'You are not collecting traffic stats.', 'fix' => 'Connect the site in DevDome Analytics.', 'actions' => array( array( 'label' => 'Open Analytics', 'href' => $href ) ) );
			} elseif ( ! $tracking ) {
				$issues[] = array( 'problem' => 'Analytics tracking is paused.', 'why_it_matters' => 'No new traffic is being recorded.', 'fix' => 'Enable tracking in DevDome Analytics.', 'actions' => array( array( 'label' => 'Open Analytics', 'href' => $href ) ) );
			}
			return array( 'score' => $score, 'status' => ( $score >= 75 ? 'good' : ( $score >= 50 ? 'warn' : 'urgent' ) ), 'scope_label' => 'Analytics', 'summary' => '', 'issues' => $issues );
		},
	);
	return $r;
} );

// S3: Recent-activity digest section.
add_filter( 'devdcorev1_suite_report_sections', function ( $s ) {
	$bv   = get_transient( 'devdalyt_cf_bot_visits' );
	$line = ( false !== $bv && (int) $bv >= 0 ) ? ( (int) $bv . ' bot visits (30d)' ) : 'Connect for traffic stats';
	$s[]  = array( 'title' => 'Analytics', 'lines' => array( ( (int) get_option( 'devdalyt_tracking_enabled', 0 ) ? 'Tracking on' : 'Tracking off' ), $line ) );
	return $s;
} );

// Boot.
DEVDALYT_Analytics::instance();

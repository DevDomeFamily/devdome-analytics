<?php
/**
 * Plugin Name: DevDome Analytics
 * Plugin URI: https://devdome.com/features/analytics
 * Description: Traffic analytics, visitor statistics and click tracking for WordPress, with AI referral detection and bot-filtered numbers.
 * Version: 1.0.8
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

define( 'DEVDALYT_VERSION', '1.0.8' );
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

require_once DEVDALYT_DIR . 'includes/class-devdome-analytics.php';
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
	if ( ! DEVDALYT_Analytics::is_connected() ) { return $location; }               // unconnected sites never tag redirects
	if ( ! (bool) get_option( 'devdalyt_tracking_enabled', true ) ) { return $location; }
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	// SEO-safe: never tag a crawler's redirect (would pollute the index with ?_dd= URLs).
	if ( '' === $ua || ( function_exists( 'devdcorev1_ua_is_bot' ) && devdcorev1_ua_is_bot( $ua ) ) ) { return $location; }
	// Same-site landings only (the tracker runs there). A relative location (no host) is same-site.
	$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$dest = strtolower( (string) wp_parse_url( $location, PHP_URL_HOST ) );
	if ( '' !== $dest && $dest !== $home ) { return $location; }
	// Sanitized with a printable-ASCII allowlist rather than sanitize_text_field()/esc_url_raw():
	// those strip %XX escapes or bare colons in the query, corrupting the recorded from-path. The
	// allowlist removes control bytes and anything a URI cannot legally contain, and the value is
	// rawurlencode()d before it reaches the redirect URL below.
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by the printable-ASCII allowlist on this line (see comment above); recognized sanitizers corrupt %XX escapes in the recorded path.
	$from = isset( $_SERVER['REQUEST_URI'] ) ? preg_replace( '/[^\x21-\x7E]/', '', (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	if ( '' === $from ) { return $location; }
	$from = preg_replace( '/[?&]_dd=[^&]*/', '', $from );
	$dd   = rawurlencode( ( $status ? (string) $status : '302' ) . ':' . $from );
	$sep  = ( false === strpos( $location, '?' ) ) ? '?' : '&';
	return $location . $sep . '_dd=' . $dd;
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
	if ( null === get_option( 'devdalyt_plugin_version', null ) )            { add_option( 'devdalyt_plugin_version', DEVDALYT_VERSION ); }
	if ( null === get_option( 'devdalyt_last_connection_test', null ) )      { add_option( 'devdalyt_last_connection_test', '' ); }
	if ( null === get_option( 'devdalyt_last_event_sent_at', null ) )        { add_option( 'devdalyt_last_event_sent_at', '' ); }
	if ( null === get_option( 'devdalyt_delete_data_on_uninstall', null ) )  { add_option( 'devdalyt_delete_data_on_uninstall', false ); }
	if ( null === get_option( 'devdalyt_first_party', null ) )               { add_option( 'devdalyt_first_party', false ); }
}

// Upgrade path: when the stored version lags, seed any options added in newer versions (activation
// doesn't re-run on plugin updates) so new switches have real rows and can be toggled off.
add_action( 'admin_init', function () {
	if ( get_option( 'devdalyt_plugin_version' ) !== DEVDALYT_VERSION ) {
		devdalyt_seed_options();
		// DevDome-managed service URLs are not user-editable — re-sync to current defaults on upgrade
		// (everything lives on analytics.devdome.com since 2026-06-10).
		update_option( 'devdalyt_api_endpoint', DEVDALYT_DEFAULT_API_ENDPOINT );
		update_option( 'devdalyt_status_endpoint', DEVDALYT_DEFAULT_STATUS_ENDPOINT );
		update_option( 'devdalyt_stats_endpoint', DEVDALYT_DEFAULT_STATS_ENDPOINT );
		update_option( 'devdalyt_script_url', DEVDALYT_DEFAULT_SCRIPT_URL );
		update_option( 'devdalyt_dashboard_url', DEVDALYT_DEFAULT_DASHBOARD_URL );
		update_option( 'devdalyt_plugin_version', DEVDALYT_VERSION );
	}
} );

register_activation_hook( __FILE__, function () {
	// Fresh install = no version row yet (checked BEFORE seeding). Only then does the
	// excluded-roles default apply — re-activating an existing site changes nothing.
	$fresh = ( null === get_option( 'devdalyt_plugin_version', null ) );
	devdalyt_seed_options();
	if ( $fresh ) {
		update_option( 'devdalyt_excluded_roles', array( 'administrator', 'editor' ) );
		// A brand-new site starts COOKIELESS: nothing is written to any visitor's device, so the
		// owner needs no cookie banner for DevDome. Turning returning-visitor tracking on is their
		// explicit, warned decision. Existing sites are untouched (see the option default).
		update_option( 'devdalyt_returning_visitors', false );
	}
	// Auto-provision this site's identity so connecting is one click (no pasting):
	// site_id is the domain, site_token is a random secret kept server-side only.
	// Both are suite-shared (devdcorev1_*) — one connect covers every DevDome plugin.
	if ( '' === (string) get_option( 'devdcorev1_site_id', '' ) ) {
		update_option( 'devdcorev1_site_id', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}
	if ( '' === (string) get_option( 'devdcorev1_site_token', '' ) ) {
		update_option( 'devdcorev1_site_token', wp_generate_password( 40, false ) );
	}
	// Service URLs are DevDome-managed (not user-editable) — keep them current so an
	// upgrade from older endpoints (e.g. the testing host) always points at production.
	update_option( 'devdalyt_api_endpoint', DEVDALYT_DEFAULT_API_ENDPOINT );
	update_option( 'devdalyt_status_endpoint', DEVDALYT_DEFAULT_STATUS_ENDPOINT );
	update_option( 'devdalyt_stats_endpoint', DEVDALYT_DEFAULT_STATS_ENDPOINT );
	update_option( 'devdalyt_script_url', DEVDALYT_DEFAULT_SCRIPT_URL );
	update_option( 'devdalyt_dashboard_url', DEVDALYT_DEFAULT_DASHBOARD_URL );
	update_option( 'devdalyt_plugin_version', DEVDALYT_VERSION );
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
	if ( ! DEVDALYT_Analytics::is_connected() ) {
		return; // nothing to verify until the user links the site
	}
	if ( '/.well-known/devdome-analytics.txt' === $path ) {
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
	if ( '' === $site || '' === $endpoint || ! DEVDALYT_Analytics::is_connected() ) {
		return $sources; // not connected yet
	}
	$bots = get_transient( 'devdalyt_cf_bot_visits' );
	if ( false === $bots ) {
		$bots = -1; // sentinel: attempted but no usable answer
		$resp = wp_remote_get(
			add_query_arg( array( 'site' => $site, 'days' => 30 ), $endpoint ),
			array( 'timeout' => 8 )
		);
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$data = json_decode( wp_remote_retrieve_body( $resp ), true );
			if ( is_array( $data ) && isset( $data['bots'] ) ) {
				$bots = max( 0, (int) $data['bots'] );
			}
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
			$connected = ( '' !== (string) get_option( 'devdcorev1_site_id', '' ) );
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

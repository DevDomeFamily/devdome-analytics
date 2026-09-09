<?php
/**
 * REST API for the admin UI. Admin-only (manage_options + wp_rest nonce).
 * Routes under /wp-json/devdome-analytics/v1/ : connect, disconnect, settings, stats.
 */

defined( 'ABSPATH' ) || exit;

class DEVDALYT_Rest {

	/** @var DEVDALYT_API */
	private $api;

	public function __construct( DEVDALYT_API $api ) {
		$this->api = $api;
		add_action( 'rest_api_init', array( $this, 'register' ) );
	}

	public function register() {
		$ns = 'devdome-analytics/v1';
		$auth = array( $this, 'can_manage' );

		register_rest_route( $ns, '/disconnect', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'disconnect' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( $ns, '/settings', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_settings' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( $ns, '/stats', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'stats' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( $ns, '/reset', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'reset' ),
			'permission_callback' => $auth,
		) );
	}

	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/** Link this site: store the Account ID (+ optional email), then verify with DevDome. */
	// Manual Account-ID connect removed (2026-08-11): connecting goes through the two-sided
	// devdome.com flow only (handle_connect_go / maybe_complete_oauth_connect), so the linked
	// account is always the one actually signed in — an arbitrary typed ID can no longer bind.

	/** Flush full-page caches so already-cached HTML re-renders with (connect) / without (disconnect)
	 *  the /dd-go link rewriting. Each call is a no-op unless that caching plugin is active. */
	public static function purge_page_caches() {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		do_action( 'litespeed_purge_all' );                                      // LiteSpeed Cache
		if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); } // WP Rocket
		if ( function_exists( 'w3tc_flush_all' ) ) { w3tc_flush_all(); }           // W3 Total Cache
		if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); } // WP Super Cache
		do_action( 'cloudflare_purge_everything' );                              // Cloudflare plugin
	}

	/** Unlink: stop tracking. The auto-provisioned token is kept for reconnects. If purge=true,
	 *  also delete this site's collected data on DevDome (else it auto-prunes after retention). */
	public function disconnect( WP_REST_Request $req ) {
		if ( ! empty( $req->get_param( 'purge' ) ) ) {
			$this->api->purge();
		}
		// Unlink server-side too (token-authenticated), same as the hub Disconnect — otherwise
		// the next verify re-paints "Connected" from the still-bound service record.
		$site  = (string) get_option( 'devdcorev1_site_id', '' );
		$token = (string) get_option( 'devdcorev1_site_token', '' );
		if ( '' !== $site && '' !== $token ) {
			wp_remote_post( 'https://api.devdome.com/plugin/disconnect', array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'site' => $site, 'token' => $token ) ),
			) );
		}
		update_option( 'devdcorev1_connected_at', '' );
		// Disconnect wipes the WHOLE local identity + verdict cache — any survivor
		// (account_id, cached ok-state) re-paints a Connected UI somewhere in the suite.
		delete_option( 'devdcorev1_account_id' );
		delete_option( 'devdcorev1_account_email' );
		delete_option( 'devdcorev1_account_connected' ); // legacy flag, unread since 1.5.0
		delete_option( 'devdcorev1_conn_state' );
		delete_transient( 'devdcorev1_conn_checked' );
		// The service may still have this site bound to the account; without this marker the
		// admin screen's remote-state sync would quietly reconnect what the user just ended.
		update_option( 'devdalyt_user_disconnected', 1 );
		self::purge_page_caches(); // drop cached HTML so /dd-go links stop being served
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** Reset analytics: delete this site's collected data on DevDome and start fresh (stays connected). */
	public function reset() {
		$ok = $this->api->purge();
		return rest_ensure_response( array( 'ok' => $ok ) );
	}

	/**
	 * Save the tracking switches. Each option name is spelled out as a LITERAL at its
	 * update_option() call site — a map-driven loop hides the key behind a variable and the
	 * wp.org review scanner, unable to resolve it, assumes the worst.
	 */
	public function save_settings( WP_REST_Request $req ) {
		$body = (array) $req->get_json_params();
		$on   = static function ( $key ) use ( $body ) {
			return ! empty( $body[ $key ] );
		};
		if ( array_key_exists( 'tracking', $body ) )            { update_option( 'devdalyt_tracking_enabled', $on( 'tracking' ) ); }
		if ( array_key_exists( 'clicks', $body ) )              { update_option( 'devdalyt_click_tracking_enabled', $on( 'clicks' ) ); }
		if ( array_key_exists( 'outbound', $body ) )            { update_option( 'devdalyt_outbound_tracking_enabled', $on( 'outbound' ) ); }
		if ( array_key_exists( 'ai', $body ) )                  { update_option( 'devdalyt_ai_tracking_enabled', $on( 'ai' ) ); }
		if ( array_key_exists( 'bots', $body ) )                { update_option( 'devdalyt_bot_tracking_enabled', $on( 'bots' ) ); }
		if ( array_key_exists( 'dnt_admins', $body ) )          { update_option( 'devdalyt_dnt_admins', $on( 'dnt_admins' ) ); }
		if ( array_key_exists( 'dnt', $body ) )                 { update_option( 'devdalyt_respect_dnt', $on( 'dnt' ) ); }
		if ( array_key_exists( 'returning', $body ) )           { update_option( 'devdalyt_returning_visitors', $on( 'returning' ) ); }
		if ( array_key_exists( 'first_party', $body ) ) {
			// First-Party Delivery is included in Pro and up. The entitlement lives on our
			// service and is asked here, live: a plan that does not include it keeps the
			// switch off and the settings screen shows the upgrade note. Nothing is blocked
			// or crippled — tracking continues exactly as before through the normal tag.
			$want = $on( 'first_party' );
			// An unconnected site has nothing to relay to — the switch needs a connection first.
			if ( $want && ! DEVDALYT_Analytics::is_connected() ) {
				$want = false;
			}
			// Turning ON requires the service to have ANSWERED. A 401/timeout used to fall
			// open and enable the paid feature on free accounts (confirmed live 2026-08-05);
			// fail-open now only ever protects a feature that is already running.
			$ent  = $want ? DEVDALYT_Tracker::fp_entitlement( true ) : null;
			$want = $want && $ent && ! empty( $ent['answered'] ) && ! empty( $ent['ok'] );
			$prev = (bool) get_option( 'devdalyt_first_party', false );
			update_option( 'devdalyt_first_party', $want );
			// Provision in the admin request, not on a visitor's first pageview: generates the
			// per-site random names, places the static script copy, schedules the daily refresh.
			if ( $want ) {
				DEVDALYT_Tracker::fp_provision();
			} else {
				DEVDALYT_Tracker::fp_unschedule();
			}
			// The beacon endpoint is baked into cached HTML — purge on every flip, or cached
			// pages keep posting to a path that now drops everything (audit 2026-08-05).
			if ( $prev !== $want ) {
				self::purge_page_caches();
			}
		}
		if ( array_key_exists( 'debug', $body ) )               { update_option( 'devdalyt_debug_mode', $on( 'debug' ) ); }
		if ( array_key_exists( 'delete_on_uninstall', $body ) ) { update_option( 'devdalyt_delete_data_on_uninstall', $on( 'delete_on_uninstall' ) ); }
		// Excluded roles: allowlisted against the editable roles (saved by the Settings footer).
		if ( array_key_exists( 'excluded_roles', $body ) ) {
			if ( ! function_exists( 'get_editable_roles' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			$raw = array_map( 'sanitize_key', (array) $body['excluded_roles'] );
			update_option( 'devdalyt_excluded_roles', array_values( array_intersect( $raw, array_keys( get_editable_roles() ) ) ) );
		}
		$resp = array( 'ok' => true );
		// The first_party switch can resolve differently than requested (entitlement said no,
		// no answer, not connected) — report the stored value so the admin UI can resync
		// instead of showing an ON switch that is really off.
		if ( array_key_exists( 'first_party', $body ) ) {
			$resp['first_party'] = (bool) get_option( 'devdalyt_first_party', false );
		}
		return rest_ensure_response( $resp );
	}

	public function stats( WP_REST_Request $req ) {
		$days = (int) $req->get_param( 'days' );
		$days = $days > 0 ? $days : 7;
		// Never contact DevDome for an unconnected site (the admin JS doesn't ask, but the
		// route itself must not phone home pre-consent either) — answer with empty tiles.
		if ( ! DEVDALYT_Analytics::is_connected() ) {
			return rest_ensure_response( array( 'period_days' => $days ) );
		}
		return rest_ensure_response( $this->api->get_stats( $days ) );
	}
}

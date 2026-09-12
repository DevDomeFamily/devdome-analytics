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
			// strict boolean: "false" as a string must not delete anything (review 2026-09-11)
			'args'                => array( 'purge' => array( 'type' => 'boolean', 'default' => false ) ),
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
			'args'                => array(
				// bounded: the screen offers 1 to 365 days, the route must not drive arbitrary lookbacks (review 2026-09-11)
				'days' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 7 ),
			),
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
		$r = self::do_disconnect( true === rest_sanitize_boolean( $req->get_param( 'purge' ) ) );
		// ok only when the local state is verifiably gone (review 2026-09-11 round 3).
		$out = array( 'ok' => ! empty( $r['local_ok'] ), 'remote_unlinked' => $r['remote_unlinked'], 'purged' => $r['purged'] );
		if ( empty( $r['local_ok'] ) ) {
			$out['error'] = 'devdalyt_disconnect_incomplete';
		}
		return rest_ensure_response( $out );
	}

	/**
	 * The Disconnect action itself, shared by the REST route and the disconnect ability (2026-09-11).
	 * Returns array{remote_unlinked:bool,purged:bool}: the account server's answer is checked, so a
	 * timeout or refusal is reported instead of "disconnected" while the account still lists the site.
	 */
	public static function do_disconnect( $purge ) {
		$purged = false;
		if ( $purge ) {
			$purged = ( new DEVDALYT_API() )->purge();
		}
		// Unlink server-side too (token-authenticated), same as the hub Disconnect — otherwise
		// the next verify re-paints "Connected" from the still-bound service record.
		$site   = (string) get_option( 'devdcorev1_site_id', '' );
		$token  = (string) get_option( 'devdcorev1_site_token', '' );
		$remote = true; // nothing to unlink when the site was never registered
		if ( '' !== $site && '' !== $token ) {
			$res    = wp_remote_post( 'https://api.devdome.com/plugin/disconnect', array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'site' => $site, 'token' => $token ) ),
			) );
			$code   = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			$remote = $code >= 200 && $code < 300;
		}
		update_option( 'devdcorev1_connected_at', '' );
		delete_option( 'devdcorev1_connect_started' ); // the consent stamp: no remote check runs again until a new Connect
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
		// Verified, not assumed (review 2026-09-11 round 2): a failed option write used to be
		// answered "disconnected: true" while the account id and the connected stamp survived.
		$local_ok = '' === (string) get_option( 'devdcorev1_account_id', '' )
			&& '' === (string) get_option( 'devdcorev1_connected_at', '' )
			&& ! get_option( 'devdcorev1_conn_state', false )
			&& (bool) get_option( 'devdalyt_user_disconnected', false );
		return array( 'remote_unlinked' => $remote, 'purged' => $purged, 'local_ok' => $local_ok );
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
		return rest_ensure_response( self::apply_settings( (array) $req->get_json_params() ) );
	}

	/**
	 * The Save action itself, shared by the REST route and the update-settings ability (2026-09-11):
	 * one set of keys, sanitizers and entitlement rules. Returns the response array.
	 */
	/** Strict switch value: bool, 0/1, or the spellings true/false/yes/no/on/off/1/0; anything else = null (refused). */
	public static function to_bool( $v ) {
		if ( is_bool( $v ) ) { return $v; }
		if ( is_int( $v ) && ( 0 === $v || 1 === $v ) ) { return 1 === $v; }
		if ( is_string( $v ) ) {
			$s = strtolower( trim( $v ) );
			if ( in_array( $s, array( '1', 'true', 'yes', 'on' ), true ) ) { return true; }
			if ( in_array( $s, array( '0', 'false', 'no', 'off', '' ), true ) ) { return false; }
		}
		return null;
	}

	public static function apply_settings( array $body ) {
		// Strict booleans (review 2026-09-11 round 2): `! empty()` read the STRING "false" as on and
		// answered ok. Anything that is not a real boolean (or its 0/1/"true"/"false" spellings) is
		// refused and reported in not_applied; nothing is stored for it.
		$invalid = array();
		$bool    = static function ( $key ) use ( $body ) {
			return self::to_bool( $body[ $key ] );
		};
		if ( array_key_exists( 'tracking', $body ) )            { $v = $bool( 'tracking' ); if ( null === $v ) { $invalid[] = 'tracking'; } else { update_option( 'devdalyt_tracking_enabled', $v ); } }
		if ( array_key_exists( 'clicks', $body ) )              { $v = $bool( 'clicks' ); if ( null === $v ) { $invalid[] = 'clicks'; } else { update_option( 'devdalyt_click_tracking_enabled', $v ); } }
		if ( array_key_exists( 'outbound', $body ) )            { $v = $bool( 'outbound' ); if ( null === $v ) { $invalid[] = 'outbound'; } else { update_option( 'devdalyt_outbound_tracking_enabled', $v ); } }
		if ( array_key_exists( 'ai', $body ) )                  { $v = $bool( 'ai' ); if ( null === $v ) { $invalid[] = 'ai'; } else { update_option( 'devdalyt_ai_tracking_enabled', $v ); } }
		if ( array_key_exists( 'bots', $body ) )                { $v = $bool( 'bots' ); if ( null === $v ) { $invalid[] = 'bots'; } else { update_option( 'devdalyt_bot_tracking_enabled', $v ); } }
		if ( array_key_exists( 'dnt_admins', $body ) )          { $v = $bool( 'dnt_admins' ); if ( null === $v ) { $invalid[] = 'dnt_admins'; } else { update_option( 'devdalyt_dnt_admins', $v ); } }
		if ( array_key_exists( 'dnt', $body ) )                 { $v = $bool( 'dnt' ); if ( null === $v ) { $invalid[] = 'dnt'; } else { update_option( 'devdalyt_respect_dnt', $v ); } }
		if ( array_key_exists( 'returning', $body ) )           { $v = $bool( 'returning' ); if ( null === $v ) { $invalid[] = 'returning'; } else { update_option( 'devdalyt_returning_visitors', $v ); } }
		if ( array_key_exists( 'first_party', $body ) ) {
			// First-Party Delivery is included in Pro and up. The entitlement lives on our
			// service and is asked here, live: a plan that does not include it keeps the
			// switch off and the settings screen shows the upgrade note. Nothing is blocked
			// or crippled — tracking continues exactly as before through the normal tag.
			$want = $bool( 'first_party' );
			if ( null === $want ) {
				// Refused input changes NOTHING (round 3): it used to fall through and switch the feature off.
				$invalid[] = 'first_party';
			} else {
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
			} // valid first_party value
		}
		if ( array_key_exists( 'debug', $body ) )               { $v = $bool( 'debug' ); if ( null === $v ) { $invalid[] = 'debug'; } else { update_option( 'devdalyt_debug_mode', $v ); } }
		if ( array_key_exists( 'delete_on_uninstall', $body ) ) { $v = $bool( 'delete_on_uninstall' ); if ( null === $v ) { $invalid[] = 'delete_on_uninstall'; } else { update_option( 'devdalyt_delete_data_on_uninstall', $v ); } }
		// Excluded roles: allowlisted against the editable roles (saved by the Settings footer).
		if ( array_key_exists( 'excluded_roles', $body ) ) {
			if ( ! function_exists( 'get_editable_roles' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			$raw = array_map( 'sanitize_key', (array) $body['excluded_roles'] );
			update_option( 'devdalyt_excluded_roles', array_values( array_intersect( $raw, array_keys( get_editable_roles() ) ) ) );
		}
		// Read back (review 2026-09-11): only what the database holds now counts. A refused first_party,
		// a role that is not editable here or a lost option write shows up in not_applied, never as ok.
		$now         = self::settings_snapshot();
		$not_applied = $invalid;
		foreach ( $body as $k => $want ) {
			if ( ! array_key_exists( $k, $now ) || in_array( $k, $invalid, true ) ) {
				continue;
			}
			if ( 'excluded_roles' === $k ) {
				$w = array_values( array_map( 'sanitize_key', (array) $want ) );
				sort( $w );
				$n = $now['excluded_roles'];
				sort( $n );
				if ( $w !== $n ) {
					$not_applied[] = $k;
				}
			} elseif ( self::to_bool( $want ) !== (bool) $now[ $k ] ) { // parsed the same way it was stored ("false" = off)
				$not_applied[] = $k;
			}
		}
		$resp = array( 'ok' => empty( $not_applied ), 'not_applied' => $not_applied, 'settings' => $now );
		// The first_party switch can resolve differently than requested (entitlement said no,
		// no answer, not connected) — report the stored value so the admin UI can resync
		// instead of showing an ON switch that is really off.
		if ( array_key_exists( 'first_party', $body ) ) {
			$resp['first_party'] = (bool) get_option( 'devdalyt_first_party', false );
		}
		return $resp;
	}

	/** Every tracking setting as stored, one shape for the screen, the REST answer and the abilities. */
	public static function settings_snapshot() {
		return array(
			'tracking'            => (bool) get_option( 'devdalyt_tracking_enabled', true ),
			'clicks'              => (bool) get_option( 'devdalyt_click_tracking_enabled', true ),
			'outbound'            => (bool) get_option( 'devdalyt_outbound_tracking_enabled', true ),
			'ai'                  => (bool) get_option( 'devdalyt_ai_tracking_enabled', true ),
			'bots'                => (bool) get_option( 'devdalyt_bot_tracking_enabled', true ),
			'dnt_admins'          => (bool) get_option( 'devdalyt_dnt_admins', true ),
			'dnt'                 => (bool) get_option( 'devdalyt_respect_dnt', true ),
			'returning'           => (bool) get_option( 'devdalyt_returning_visitors', true ),
			'first_party'         => (bool) get_option( 'devdalyt_first_party', false ),
			'debug'               => (bool) get_option( 'devdalyt_debug_mode', false ),
			'delete_on_uninstall' => (bool) get_option( 'devdalyt_delete_data_on_uninstall', false ),
			'excluded_roles'      => array_values( array_map( 'strval', (array) get_option( 'devdalyt_excluded_roles', array() ) ) ),
		);
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

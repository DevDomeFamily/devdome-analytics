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

		// Every route runs inside a guard window (DESIGN.md 24): a failed query inside it turns the
		// answer into a database error at devdalyt_rest_success(), never into "ok".
		// Disconnect and reset act on the shared identity / the hosted data: the network's capability on a
		// subdirectory multisite (Codex round 1), and a server-side confirm mirrors the typed-word dialog (DeepSeek round 1).
		$auth_conn = array( $this, 'can_connect' );
		$confirm   = array( 'confirm' => array( 'type' => 'boolean', 'required' => true, 'description' => 'Must be true: the screen asks for the typed word first.' ) );
		register_rest_route( $ns, '/disconnect', array(
			'methods'             => 'POST',
			'callback'            => devdalyt_rest_guarded( array( $this, 'disconnect' ) ),
			'permission_callback' => $auth_conn,
			// strict boolean: "false" as a string must not delete anything (review 2026-09-11)
			'args'                => $confirm + array( 'purge' => array( 'type' => 'boolean', 'default' => false ) ),
		) );
		register_rest_route( $ns, '/settings', array(
			'methods'             => 'POST',
			'callback'            => devdalyt_rest_guarded( array( $this, 'save_settings' ) ),
			'permission_callback' => $auth,
		) );
		register_rest_route( $ns, '/stats', array(
			'methods'             => 'GET',
			'callback'            => devdalyt_rest_guarded( array( $this, 'stats' ) ),
			'permission_callback' => $auth,
			'args'                => array(
				// bounded: the screen offers 1 to 365 days, the route must not drive arbitrary lookbacks (review 2026-09-11)
				'days' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 7 ),
			),
		) );
		register_rest_route( $ns, '/reset', array(
			'methods'             => 'POST',
			'callback'            => devdalyt_rest_guarded( array( $this, 'reset' ) ),
			'permission_callback' => $auth_conn,
			'args'                => $confirm,
		) );
	}

	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/** Connect / disconnect / purge: the shared-identity capability (manage_network on a subdirectory multisite). */
	public function can_connect() {
		return current_user_can( devdalyt_connect_cap() );
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
		if ( true !== rest_sanitize_boolean( $req->get_param( 'confirm' ) ) ) {
			return new WP_Error( 'devdalyt_confirm_required', __( 'Confirm the disconnect first.', 'devdome-analytics' ), array( 'status' => 400 ) );
		}
		$r = self::do_disconnect( true === rest_sanitize_boolean( $req->get_param( 'purge' ) ) );
		// ok only when the local state is verifiably gone (review 2026-09-11 round 3).
		$out = array( 'ok' => ! empty( $r['local_ok'] ), 'remote_unlinked' => $r['remote_unlinked'], 'purged' => $r['purged'] );
		if ( empty( $r['local_ok'] ) ) {
			$out['error'] = 'devdalyt_disconnect_incomplete';
		}
		return devdalyt_rest_success( $out );
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
			$data   = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
			$remote = $code >= 200 && $code < 300 && is_array( $data ) && isset( $data['ok'] ) && true === $data['ok']; // the service's own ok, not the status alone (Codex round 1)
		}
		// Every write is proved (DESIGN.md 24.5): each helper reads the row back past the option cache
		// and answers false when the value did not land. None short-circuits the others.
		$ok = devdalyt_option_write( 'devdcorev1_connected_at', '' );
		$ok = devdalyt_option_delete( 'devdcorev1_connect_started' ) && $ok; // the consent stamp: no remote check runs again until a new Connect
		// Disconnect wipes the WHOLE local identity + verdict cache — any survivor
		// (account_id, cached ok-state) re-paints a Connected UI somewhere in the suite.
		$ok = devdalyt_option_delete( 'devdcorev1_account_id' ) && $ok;
		$ok = devdalyt_option_delete( 'devdcorev1_account_email' ) && $ok;
		$ok = devdalyt_option_delete( 'devdcorev1_account_connected' ) && $ok; // legacy flag, unread since 1.5.0
		$ok = devdalyt_option_delete( 'devdcorev1_conn_state' ) && $ok;
		delete_transient( 'devdcorev1_conn_checked' );
		// The service may still have this site bound to the account; without this marker the
		// admin screen's remote-state sync would quietly reconnect what the user just ended.
		$ok = devdalyt_option_write( 'devdalyt_user_disconnected', time() ) && $ok; // the time, so a LATER hub reconnect can lift it (Codex round 2)
		// First-Party Delivery needs a connection: off, and its daily refresh unscheduled, so nothing calls home afterwards (Codex round 3).
		if ( get_option( 'devdalyt_first_party', false ) ) {
			$ok = devdalyt_option_write( 'devdalyt_first_party', false ) && $ok;
		}
		DEVDALYT_Tracker::fp_unschedule();
		self::purge_page_caches(); // drop cached HTML so /dd-go links stop being served
		// Verified, not assumed (review 2026-09-11 round 2): a failed option write used to be
		// answered "disconnected: true" while the account id and the connected stamp survived.
		$local_ok = $ok
			&& '' === (string) get_option( 'devdcorev1_account_id', '' )
			&& '' === (string) get_option( 'devdcorev1_connected_at', '' )
			&& ! get_option( 'devdcorev1_conn_state', false )
			&& (bool) get_option( 'devdalyt_user_disconnected', false );
		return array( 'remote_unlinked' => $remote, 'purged' => $purged, 'local_ok' => $local_ok );
	}

	/** Reset analytics: delete this site's collected data on DevDome and start fresh (stays connected). */
	public function reset( WP_REST_Request $req ) {
		if ( true !== rest_sanitize_boolean( $req->get_param( 'confirm' ) ) ) {
			return new WP_Error( 'devdalyt_confirm_required', __( 'Confirm the reset first.', 'devdome-analytics' ), array( 'status' => 400 ) );
		}
		$ok = $this->api->purge();
		return devdalyt_rest_success( array( 'ok' => $ok ) );
	}

	/**
	 * Save the tracking switches. Each option name is spelled out as a LITERAL at its
	 * update_option() call site — a map-driven loop hides the key behind a variable and the
	 * wp.org review scanner, unable to resolve it, assumes the worst.
	 */
	public function save_settings( WP_REST_Request $req ) {
		return devdalyt_rest_success( self::apply_settings( (array) $req->get_json_params() ) );
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
		$notes = array();
		// Strict booleans (review 2026-09-11 round 2): `! empty()` read the STRING "false" as on and
		// answered ok. Anything that is not a real boolean (or its 0/1/"true"/"false" spellings) is
		// refused and reported in not_applied; nothing is stored for it.
		$invalid = array();
		$before  = self::settings_snapshot(); // what cached pages were rendered from
		$bool    = static function ( $key ) use ( $body ) {
			return self::to_bool( $body[ $key ] );
		};
		if ( array_key_exists( 'tracking', $body ) )            { $v = $bool( 'tracking' ); if ( null === $v ) { $invalid[] = 'tracking'; } else { devdalyt_option_write( 'devdalyt_tracking_enabled', $v ); } }
		if ( array_key_exists( 'clicks', $body ) )              { $v = $bool( 'clicks' ); if ( null === $v ) { $invalid[] = 'clicks'; } else { devdalyt_option_write( 'devdalyt_click_tracking_enabled', $v ); } }
		if ( array_key_exists( 'outbound', $body ) )            { $v = $bool( 'outbound' ); if ( null === $v ) { $invalid[] = 'outbound'; } else { devdalyt_option_write( 'devdalyt_outbound_tracking_enabled', $v ); } }
		if ( array_key_exists( 'ai', $body ) )                  { $v = $bool( 'ai' ); if ( null === $v ) { $invalid[] = 'ai'; } else { devdalyt_option_write( 'devdalyt_ai_tracking_enabled', $v ); } }
		if ( array_key_exists( 'bots', $body ) )                { $v = $bool( 'bots' ); if ( null === $v ) { $invalid[] = 'bots'; } else { devdalyt_option_write( 'devdalyt_bot_tracking_enabled', $v ); } }
		if ( array_key_exists( 'dnt_admins', $body ) )          { $v = $bool( 'dnt_admins' ); if ( null === $v ) { $invalid[] = 'dnt_admins'; } else { devdalyt_option_write( 'devdalyt_dnt_admins', $v ); } }
		if ( array_key_exists( 'dnt', $body ) )                 { $v = $bool( 'dnt' ); if ( null === $v ) { $invalid[] = 'dnt'; } else { devdalyt_option_write( 'devdalyt_respect_dnt', $v ); } }
		if ( array_key_exists( 'returning', $body ) )           { $v = $bool( 'returning' ); if ( null === $v ) { $invalid[] = 'returning'; } else { devdalyt_option_write( 'devdalyt_returning_visitors', $v ); } }
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
			if ( $want && ! DEVDALYT_Analytics::is_connected( false ) ) {
				$want = false;
			}
			// Turning ON requires the service to have ANSWERED. A 401/timeout used to fall
			// open and enable the paid feature on free accounts (confirmed live 2026-08-05);
			// fail-open now only ever protects a feature that is already running.
			$ent  = $want ? DEVDALYT_Tracker::fp_entitlement( true ) : null;
			$prev = (bool) get_option( 'devdalyt_first_party', false );
			if ( $want && ( ! $ent || empty( $ent['answered'] ) ) ) {
				$want = $prev; // no answer = no change: a re-save of "on" during an outage must not switch a running feature off (DeepSeek round 4)
			} else {
				$want = $want && $ent && ! empty( $ent['ok'] );
			}
			if ( ! devdalyt_option_write( 'devdalyt_first_party', $want ) ) {
				$want = $prev; // the flag did not land (DESIGN.md 24.5): provision nothing the database does not say
			}
			// Provision in the admin request, not on a visitor's first pageview: generates the
			// per-site random names, places the static script copy, schedules the daily refresh.
			// A provision that did not fully land (no local script, no schedule) is said in `notes`:
			// the tag then serves from analytics.devdome.com until the daily refresh succeeds (Codex round 1).
			if ( $want ) {
				if ( ! DEVDALYT_Tracker::fp_provision() ) {
					$notes[] = 'first_party: the local script copy or its daily refresh could not be set up on this host; the tracker is served from analytics.devdome.com until the next refresh succeeds.';
				}
			} else {
				DEVDALYT_Tracker::fp_unschedule();
			}
			} // valid first_party value
		}
		if ( array_key_exists( 'debug', $body ) )               { $v = $bool( 'debug' ); if ( null === $v ) { $invalid[] = 'debug'; } else { devdalyt_option_write( 'devdalyt_debug_mode', $v ); } }
		if ( array_key_exists( 'delete_on_uninstall', $body ) ) { $v = $bool( 'delete_on_uninstall' ); if ( null === $v ) { $invalid[] = 'delete_on_uninstall'; } else { devdalyt_option_write( 'devdalyt_delete_data_on_uninstall', $v ); } }
		// Excluded roles: allowlisted against the editable roles (saved by the Settings footer).
		if ( array_key_exists( 'excluded_roles', $body ) ) {
			if ( ! function_exists( 'get_editable_roles' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			// A list of strings, nothing else (Codex round 1): null / a scalar used to be cast to an array and wipe the list.
			$list = $body['excluded_roles'];
			if ( ! is_array( $list ) || count( array_filter( $list, 'is_string' ) ) !== count( $list ) ) {
				$invalid[] = 'excluded_roles';
			} else {
				$raw = array_unique( array_map( 'sanitize_key', $list ) ); // stored once each (Codex round 10)
				devdalyt_option_write( 'devdalyt_excluded_roles', array_values( array_intersect( $raw, array_keys( get_editable_roles() ) ) ) );
			}
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
				$w = array_values( array_unique( array_map( 'sanitize_key', (array) $want ) ) ); // stored unique (DeepSeek round 9)
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
		// Every switch is baked into cached HTML (the tag's data-* config, the relay path): a page cached
		// before the save keeps the old behaviour until the cache is purged (Codex round 1).
		if ( $before !== $now ) {
			self::purge_page_caches();
		}
		$resp = array( 'ok' => empty( $not_applied ), 'not_applied' => $not_applied, 'settings' => $now );
		if ( $notes ) {
			$resp['notes'] = $notes;
		}
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
			'returning'           => (bool) get_option( 'devdalyt_returning_visitors', false ),
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
		if ( ! DEVDALYT_Analytics::is_connected( false ) ) { // a read never rewrites the identity (DeepSeek round 2)
			return devdalyt_rest_success( array( 'period_days' => $days ) );
		}
		return devdalyt_rest_success( $this->api->get_stats( $days ) );
	}
}

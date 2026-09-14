<?php
/**
 * Core loader — wires up the admin page, tracker, API client, and bot detector.
 */

defined( 'ABSPATH' ) || exit;

class DEVDALYT_Analytics {

	/** @var DEVDALYT_Analytics|null */
	private static $instance = null;

	/** @var DEVDALYT_API */
	public $api;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		require_once DEVDALYT_DIR . 'includes/class-devdome-api.php';
		require_once DEVDALYT_DIR . 'includes/class-devdome-admin.php';
		require_once DEVDALYT_DIR . 'includes/class-devdome-rest.php';
		require_once DEVDALYT_DIR . 'includes/class-devdome-tracker.php';
		require_once DEVDALYT_DIR . 'includes/class-devdome-bot-detector.php';

		$this->api = new DEVDALYT_API();

		if ( is_admin() ) {
			new DEVDALYT_Admin();
			add_action( 'admin_init', array( $this, 'maybe_complete_oauth_connect' ) );
		}

		new DEVDALYT_Rest( $this->api );
		new DEVDALYT_Tracker();
		new DEVDALYT_Bot_Detector( $this->api );
	}

	/**
	 * Connected once the user has linked the site and the backend confirmed it.
	 * `devdcorev1_connected_at` is suite-shared state (one connect covers every DevDome
	 * plugin), so it keeps the shared-core prefix rather than this plugin's.
	 */
	/**
	 * Cached-only "connected": the stop markers, the stored shared verdict and the stamp, in that order of
	 * authority, and NEVER a request or an identity rewrite. For visitor requests and read-only abilities
	 * (Codex round 4); the admin screen's sync is where the remote answer is refreshed.
	 */
	public static function is_connected_cached() {
		if ( get_option( 'devdalyt_user_disconnected', false ) || get_option( 'devdalyt_remote_disconnected', false ) ) {
			return false;
		}
		if ( '' === (string) get_option( 'devdcorev1_site_id', '' ) || '' === (string) get_option( 'devdcorev1_site_token', '' ) ) {
			return false;
		}
		$verdict = get_option( 'devdcorev1_conn_state', false );
		if ( is_array( $verdict ) ) {
			return ! empty( $verdict['ok'] ); // a stored verdict is definitive either way
		}
		return '' !== (string) get_option( 'devdcorev1_connected_at', '' );
	}

	public static function is_connected( $reconcile = true ) {
		// The owner pressed Disconnect (or the agent did): that stands until a NEW connect clears the
		// marker (maybe_complete_oauth_connect), even when the account server could not be told and
		// the shared verify still answers "linked" (review 2026-09-11: tracking resumed by itself).
		if ( get_option( 'devdalyt_user_disconnected', false ) || get_option( 'devdalyt_remote_disconnected', false ) ) {
			return false; // the 401/403 flip stands until the sync sees the service accept the site again (Codex round 2)
		}
		// One suite-wide truth: the server-verified connection state the hub renders
		// (cached; makes no remote call before the user's explicit connect action).
		// The connected_at timestamp is only the fallback - old-core migrations can
		// leave it empty on a site whose token is verifiably linked (cavenyc 2026-08-01),
		// and the two answers must never diverge.
		if ( function_exists( 'devdcorev1_connection_state' ) ) {
			$state = devdcorev1_connection_state( false, (bool) $reconcile ); // read abilities pass false: no identity rewrite from a read (Codex round 1)
			if ( ! empty( $state['ok'] ) ) {
				return true;
			}
			// A stored verification verdict is DEFINITIVE: once the server has answered
			// "not linked", a stale local connected_at timestamp must never overrule it
			// and paint a Connected UI (2026-08-11: disconnect left the badge green).
			if ( false !== get_option( 'devdcorev1_conn_state', false ) ) {
				return false;
			}
		}
		// A stamp without the identity rows is no connection (DeepSeek round 7), the same rule as is_connected_cached().
		return '' !== (string) get_option( 'devdcorev1_connected_at', '' )
			&& '' !== (string) get_option( 'devdcorev1_site_id', '' )
			&& strlen( (string) get_option( 'devdcorev1_site_token', '' ) ) >= 20;
	}

	/**
	 * One-click connect return handler. devdome.com/connect redirects the admin back to our
	 * settings page with ?dd_connect=DD######## & state=<nonce> after they authorize. We verify
	 * the nonce, store the Account ID, link the site (token-authenticated), then clean the URL.
	 * The site token never leaves WordPress; the redirect only carries the public Account ID.
	 */
	public function maybe_complete_oauth_connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this IS the connect return; the CSRF guard is the single-use transient correlation on `rt` below, not a form nonce.
		if ( ! isset( $_GET['page'], $_GET['dd_connect'], $_GET['rt'] ) || ! is_string( $_GET['page'] ) || ! is_string( $_GET['rt'] ) || 'devdome-analytics' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		$clean = admin_url( 'admin.php?page=devdome-analytics' );
		if ( ! current_user_can( devdalyt_connect_cap() ) ) {
			return; // the shared identity is the network's on a subdirectory multisite (Codex round 1)
		}
		devdalyt_db_guard_begin(); // window (DESIGN.md 24); every exit below is a redirect
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$rt    = sanitize_text_field( wp_unslash( $_GET['rt'] ) );
		// The rt must match a request THIS site started (its single-use nonce is in our transient).
		// That correlation — not a reusable form nonce — is the CSRF guard (I9 P5).
		$nonce = '' !== $rt ? get_transient( 'devdalyt_conn_' . $rt ) : false;
		if ( false === $nonce ) {
			wp_safe_redirect( add_query_arg( 'dd_error', 'expired', $clean ) );
			exit;
		}
		// Claim the Account ID server-to-server (site-token authenticated); it never rode the browser.
		// The correlation transient stays until the claim SUCCEEDS (review 2026-09-11 round 3): a
		// timeout or refusal used to burn it first, so the retry could only say "expired".
		$claim = $this->api->connect_claim( $rt, (string) $nonce );
		if ( empty( $claim['ok'] ) || empty( $claim['account_id'] ) || ! preg_match( '/^DD\d{8}$/', (string) $claim['account_id'] ) ) {
			$why = isset( $claim['message'] ) ? substr( sanitize_text_field( (string) $claim['message'] ), 0, 160 ) : '';
			wp_safe_redirect( add_query_arg( array( 'dd_error' => 'verify', 'dd_why' => rawurlencode( $why ), 'dd_retry' => $rt ), $clean ) );
			exit;
		}
		// Proved writes (DESIGN.md 24.5): the site is connected only when BOTH the account id and the
		// stamp are in the database. A write that did not land keeps the correlation transient, so the
		// retry link on the error banner can finish the same handshake.
		// The stop markers and the stale verdict are part of "connected" (is_connected() refuses on any of
		// them), so they are proved BEFORE the correlation transient goes: a delete that did not land keeps
		// the retry link working instead of a clean redirect over a site that still says disconnected.
		// The stop marker is RAISED before the first write and lowered by the LAST one (DeepSeek round 5, like the
		// adoption): whatever fails in between, every reader still says "not connected", even when the rollback
		// below cannot run because the guard already saw a failed query.
		if ( ! devdalyt_option_write( 'devdalyt_remote_disconnected', 1 ) ) {
			wp_safe_redirect( add_query_arg( array( 'dd_error' => 'verify', 'dd_why' => rawurlencode( substr( devdalyt_db_guard_message(), 0, 200 ) ), 'dd_retry' => $rt ), $clean ) );
			exit;
		}
		$landed = devdalyt_option_write( 'devdcorev1_account_id', (string) $claim['account_id'] )
			&& devdalyt_option_write( 'devdcorev1_connected_at', gmdate( 'c' ) )
			&& devdalyt_option_delete( 'devdalyt_user_disconnected' ) // explicit connect clears the stop marker
			&& devdalyt_option_delete( 'devdcorev1_conn_state' ) // a stored negative verdict is definitive for is_connected()
			&& devdalyt_option_delete( 'devdalyt_remote_disconnected' ); // last: only a complete handshake lowers the guard
		if ( ! $landed ) {
			// Roll the half state back past the guard's mark (DeepSeek round 3): a site is connected or it is not,
			// never an account id without its stamp. The correlation transient stays for the retry link.
			devdalyt_db_guard_rebase();
			devdalyt_option_delete( 'devdcorev1_account_id' );
			devdalyt_option_delete( 'devdcorev1_connected_at' );
			wp_safe_redirect( add_query_arg( array( 'dd_error' => 'verify', 'dd_why' => rawurlencode( substr( devdalyt_db_guard_message(), 0, 200 ) ), 'dd_retry' => $rt ), $clean ) );
			exit;
		}
		delete_transient( 'devdalyt_conn_' . $rt );
		delete_transient( 'devdcorev1_conn_checked' );
		if ( function_exists( 'devdcorev1_connection_state' ) ) {
			devdcorev1_connection_state( true );
		}
		DEVDALYT_Rest::purge_page_caches();
		wp_safe_redirect( $clean );
		exit;
	}
}

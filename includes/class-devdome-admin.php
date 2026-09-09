<?php
/**
 * Admin page — a server-rendered DevDome Suite (`.dd-app`) screen: connection status,
 * today's headline stats (fetched async via REST so the page never blocks on the backend),
 * tracking switches, and account actions. The full dashboard lives on DevDome.
 * Styling comes from the built Tailwind bundle in assets/devdome-tools-tw.css.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/devdome-tools-menu.php';

class DEVDALYT_Admin {

	const PAGE = 'devdome-analytics';

	/**
	 * REST key + label + description for each tracking switch (order = display order).
	 * The option name deliberately does NOT live here: the current value is read from
	 * build_state(), where every get_option() key is a literal at its call site.
	 */
	const SWITCHES = array(
		array( 'tracking',   'Enable Tracking',      'Master switch. Sends pageviews to DevDome to power your dashboard.' ),
		array( 'clicks',     'Track Clicks',         'Record clicks on the page.' ),
		array( 'outbound',   'Track Outbound Links', 'Record clicks that leave your site.' ),
		array( 'ai',         'Track AI Referrals',   'Detect visits referred by AI assistants (ChatGPT, Perplexity, …).' ),
		array( 'bots',       'Track Bot Visits',     'Record known crawler / bot hits.' ),
		array( 'dnt_admins', 'Do Not Track Admins',  'Skip tracking for logged-in administrators.' ),
		array( 'dnt',        'Respect Do Not Track', 'Honor the browser "Do Not Track" signal.' ),
		// The consent warning is part of the switch, not buried in a doc. Off by default on new
		// sites: DevDome then writes NOTHING to a visitor's device and needs no cookie banner.
		array( 'returning',  'Track Returning Visitors', 'Sets a first-party cookie so the same visitor is recognised across days, and clicks can be tied back to their visit (needed for affiliate attribution). You may need visitor consent for this. Leave it off and DevDome stores nothing on your visitors\' devices.' ),
		// Honest claim on purpose: "most, not all" - never advertise a full ad-block bypass.
		array( 'first_party', 'First-Party Delivery (Ad-Block Resistant)', 'Serves the tracking script from your own domain and relays events through your site server-side, using randomized names unique to your site. Ordinary ad blockers that block third-party analytics domains cannot drop it, so you count visitors they normally hide. Bypasses most, not all, blockers. Works alongside caching and speed plugins without configuration.' ),
	);

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_devdalyt_connect_go', array( $this, 'handle_connect_go' ) );
	}

	/**
	 * The user pressed "Connect your DevDome account" — the ONE place the connect handshake
	 * starts. No request leaves the site before this explicit, nonce-checked action: the page
	 * render itself never contacts DevDome. Registers the connect request (site-authenticated),
	 * stashes its single-use nonce in a transient, and sends the browser to devdome.com with
	 * only the opaque handle.
	 */
	public function handle_connect_go() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'devdome-analytics' ) );
		}
		check_admin_referer( 'devdalyt_connect_go' );
		// Suite-shared consent stamp: this click is the explicit opt-in that first allows the
		// shared hub's account check (devdcorev1_connection_state) to verify remotely.
		update_option( 'devdcorev1_connect_started', time(), false );
		$clean = admin_url( 'admin.php?page=devdome-analytics' );
		$start = ( new DEVDALYT_API() )->connect_start( $clean );
		if ( empty( $start['ok'] ) ) {
			wp_safe_redirect( add_query_arg( 'dd_error', 'start', $clean ) );
			exit;
		}
		set_transient( 'devdalyt_conn_' . $start['request_token'], $start['nonce'], 600 );
		$account_url = defined( 'DEVDALYT_DEFAULT_ACCOUNT_URL' ) ? DEVDALYT_DEFAULT_ACCOUNT_URL : 'https://devdome.com';
		// wp_redirect (not wp_safe_redirect): devdome.com is an external, known-fixed host.
		wp_redirect( $account_url . '/connect/?' . http_build_query( array( 'rt' => $start['request_token'] ) ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed first-party host, never user input.
		exit;
	}

	public function add_menu() {
		add_submenu_page(
			DEVDCOREV1_TOOLS_MENU_SLUG,
			__( 'DevDome Analytics', 'devdome-analytics' ),
			__( 'Analytics', 'devdome-analytics' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' ),
			1
		);
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		$css = DEVDALYT_DIR . 'assets/devdome-tools-tw.css';
		$ver = file_exists( $css ) ? (string) filemtime( $css ) : DEVDALYT_VERSION;
		wp_enqueue_style( 'devdalyt-ui', DEVDALYT_URL . 'assets/devdome-tools-tw.css', array(), $ver );
		wp_enqueue_style( 'dashicons' );

		// Screen behaviour ships as a real enqueued file — no <script> block in the markup.
		$js   = DEVDALYT_DIR . 'assets/da-admin.js';
		$jver = file_exists( $js ) ? (string) filemtime( $js ) : DEVDALYT_VERSION;
		wp_enqueue_script( 'devdalyt-admin', DEVDALYT_URL . 'assets/da-admin.js', array(), $jver, true );

		// Page-scoped tweaks on top of the shared stylesheet (kept out of the markup —
		// no inline <style> blocks on the screen). The built bundle here predates the
		// suite tab bar / sticky footer classes, so they ride along inline.
		wp_add_inline_style( 'devdalyt-ui', self::inline_css() );
	}

	/** Page-scoped CSS attached to the shared stylesheet via wp_add_inline_style(). */
	private static function inline_css() {
		return '
			.dd-app .dd-tabs { display:flex; align-items:center; gap:4px; background:#fff; padding:0 24px; }
			.dd-app .dd-tab { display:inline-flex; align-items:center; gap:6px; padding:12px 16px; font-size:14px; font-weight:600; color:#6b7280; text-decoration:none; border-bottom:2px solid transparent; transition:color .15s,border-color .15s; }
			.dd-app .dd-tab:hover { color:#1f2937; }
			.dd-app .dd-tab.is-active { color:#4338ca; border-bottom-color:#4f46e5; }
			.dd-app .dd-tab .dashicons { font-size:16px; width:16px; height:16px; line-height:16px; }
			/* Tabs: active = underline only — never a focus square lingering after a click. */
			.dd-app .dd-tab:focus, .dd-app .dd-tab:focus-visible { outline:none; box-shadow:none; }
			/* Sticky save footer (content-width rounded card, same as the rest of the suite). */
			.dd-app .dd-footer { position:sticky; bottom:0; z-index:100; margin-top:32px; background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 1px 2px 0 rgba(0,0,0,.05); }
			.dd-app .dd-footer-inner { display:flex; align-items:center; gap:16px; padding:12px 20px; }
			.dd-app .dd-footer-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; flex-shrink:0; }
			.dd-app .dd-hint { display:block; margin-top:6px; font-size:12px; color:#6b7280; }
			/* Settings: option lists render vertically, one per row. */
			.dd-app .da-optlist { display:block; }
			.dd-app .da-optlist .dd-opt { display:flex; align-items:center; gap:6px; margin:0 0 8px; }
			.dd-app .da-optlist .dd-opt:last-child { margin-bottom:0; }
		';
	}

	/**
	 * State handed to the admin JS (also returned by the REST connect route).
	 *
	 * @return array<string,mixed>
	 */
	public static function build_state() {
		return array(
			'connected'      => DEVDALYT_Analytics::is_connected(),
			'domain'         => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'account_id'     => (string) get_option( 'devdcorev1_account_id', '' ),
			'account_email'  => (string) get_option( 'devdcorev1_account_email', '' ),
			'last_event_at'  => (string) get_option( 'devdalyt_last_event_sent_at', '' ),
			'plugin_version' => DEVDALYT_VERSION,
			'dashboard_url'  => (string) get_option( 'devdalyt_dashboard_url', DEVDALYT_DEFAULT_DASHBOARD_URL ),
			'settings'       => array(
				'tracking'            => (bool) get_option( 'devdalyt_tracking_enabled', true ),
				'clicks'              => (bool) get_option( 'devdalyt_click_tracking_enabled', true ),
				'outbound'            => (bool) get_option( 'devdalyt_outbound_tracking_enabled', true ),
				'ai'                  => (bool) get_option( 'devdalyt_ai_tracking_enabled', true ),
				'bots'                => (bool) get_option( 'devdalyt_bot_tracking_enabled', true ),
				'dnt_admins'          => (bool) get_option( 'devdalyt_dnt_admins', true ),
				'dnt'                 => (bool) get_option( 'devdalyt_respect_dnt', true ),
				'returning'           => (bool) get_option( 'devdalyt_returning_visitors', true ),
				'first_party'         => (bool) get_option( 'devdalyt_first_party', false ),
				// Advisory only: drives the "included in Pro and up" note next to the switch.
				// fp_entitlement_cached() never touches the network — the old call fell through
				// to a 6s remote fetch on a cold cache, blocking this render (audit 2026-08-05).
				'first_party_allowed' => (bool) DEVDALYT_Tracker::fp_entitlement_cached()['ok'],
				// The service rejected this site's credential (token rotated / never synced):
				// delivery was auto-disabled and the switch shows a reconnect note.
				'first_party_auth_failed' => (bool) get_option( 'devdalyt_fp_auth_failed', false ),
				'debug'               => (bool) get_option( 'devdalyt_debug_mode', false ),
				'delete_on_uninstall' => (bool) get_option( 'devdalyt_delete_data_on_uninstall', false ),
			),
		);
	}

	/**
	 * Keep the LOCAL connection state honest against the service, both directions
	 * (owner 2026-08-05). One status call when the admin opens this screen, at most every
	 * 15 minutes, sending only the site id and its secret token:
	 *
	 *  - Locally NOT connected, service says the site is bound to an account: adopt it. A
	 *    site verified through the devdome.com wizard used to stay "not connected" here —
	 *    two truths, one confusing screen. An explicit local Disconnect STICKS (marker
	 *    below); only a new explicit connect clears it.
	 *  - Locally connected, service DEFINITIVELY rejects our credential (401/403): the site
	 *    was removed on the dashboard or its token was rotated — flip to disconnected and
	 *    say why, instead of showing green while every call fails. Network errors and 5xx
	 *    change NOTHING in either direction.
	 */
	private static function maybe_sync_remote_connection() {
		$site  = (string) get_option( 'devdcorev1_site_id', '' );
		$token = (string) get_option( 'devdcorev1_site_token', '' );
		if ( '' === $site || '' === $token || false !== get_transient( 'devdalyt_remote_state_sync' ) ) {
			return;
		}
		set_transient( 'devdalyt_remote_state_sync', 1, 15 * MINUTE_IN_SECONDS );
		$connected = DEVDALYT_Analytics::is_connected();
		if ( ! $connected && get_option( 'devdalyt_user_disconnected', false ) ) {
			return;
		}
		$r = wp_remote_post( (string) get_option( 'devdalyt_status_endpoint', DEVDALYT_DEFAULT_STATUS_ENDPOINT ), array(
			'timeout' => 8,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'site_id' => $site, 'site_domain' => $site, 'site_token' => $token ) ),
		) );
		if ( is_wp_error( $r ) ) {
			return;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$b    = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( 200 === $code && is_array( $b ) && ! empty( $b['ok'] ) ) {
			delete_option( 'devdalyt_remote_disconnected' );
			if ( ! $connected && ! empty( $b['account_id'] ) && preg_match( '/^DD\d{8}$/', (string) $b['account_id'] ) ) {
				update_option( 'devdcorev1_account_id', (string) $b['account_id'] );
				update_option( 'devdcorev1_connected_at', gmdate( 'c' ) );
				delete_option( 'devdalyt_user_disconnected' );
				DEVDALYT_Rest::purge_page_caches(); // cached HTML must start carrying the tracker
			}
			return;
		}
		// Definitive rejection while the screen says connected. Only the timestamp path can
		// be flipped locally; legacy hub-state installs keep their old behavior untouched.
		if ( $connected && ( 401 === $code || 403 === $code )
			&& '' !== (string) get_option( 'devdcorev1_connected_at', '' ) ) {
			update_option( 'devdcorev1_connected_at', '' );
			update_option( 'devdalyt_remote_disconnected', 1 );
			DEVDALYT_Rest::purge_page_caches(); // stop serving the tracker from cached HTML
		}
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'devdome-analytics' ) );
		}

		self::maybe_sync_remote_connection();
		// Entitlement freshness on its own short gate: a plan change (upgrade OR downgrade)
		// must show on the First-Party row within minutes, not the 15-min connection cadence
		// or the day-long verdict cache. One cheap GET, admins-only, this screen only;
		// fp_entitlement() makes no request at all while the site is unconnected.
		if ( false === get_transient( 'devdalyt_fp_ent_sync' ) ) {
			set_transient( 'devdalyt_fp_ent_sync', 1, 2 * MINUTE_IN_SECONDS );
			DEVDALYT_Tracker::fp_entitlement( true );
		}
		$connected   = DEVDALYT_Analytics::is_connected();
		$account_id  = (string) get_option( 'devdcorev1_account_id', '' );
		$dashboard   = (string) get_option( 'devdalyt_dashboard_url', DEVDALYT_DEFAULT_DASHBOARD_URL );
		$account_url = defined( 'DEVDALYT_DEFAULT_ACCOUNT_URL' ) ? DEVDALYT_DEFAULT_ACCOUNT_URL : 'https://devdome.com';
		$rest        = esc_url_raw( rest_url( 'devdome-analytics/v1/' ) );
		$nonce       = wp_create_nonce( 'wp_rest' );

		// One-click connect (two-sided, I9 P5): rendering this page makes NO request to DevDome.
		// The handshake starts only in handle_connect_go(), after the user presses the consent
		// button below — the form posts to admin-post.php, which registers the request and
		// forwards the browser with only an opaque token.
		$oauth_error = isset( $_GET['dd_error'] ) ? sanitize_text_field( wp_unslash( $_GET['dd_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect

		// ?tab= picks the initial active panel (hash + client-side switching take over after load).
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only tab selector
		if ( ! in_array( $tab, array( 'overview', 'settings' ), true ) ) {
			$tab = 'overview';
		}
		$excluded_roles = (array) get_option( 'devdalyt_excluded_roles', array() );
		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		$roles = get_editable_roles();

		// key, label, accent hex, format — labels/colors/formats are 1:1 with the dashboard. 10 tiles = 2 rows of 5.
		$metrics = array(
			array( 'visitors',        'Visitors',             '#1967d2', 'int'  ),
			array( 'live',            'Live',                 '#188038', 'int'  ),
			array( 'bots',            'Bots',                 '#be123c', 'int'  ),
			array( 'clicks',          'Clicks',               '#8430ce', 'int'  ),
			array( 'pageviews',       'Pageviews',            '#0097a7', 'int'  ),
			array( 'ctr_visitors',    'CTR (Visitors)',       '#34a853', 'pct2' ),
			array( 'ctr_pageviews',   'CTR (Pageviews)',      '#137333', 'pct2' ),
			array( 'pages_per_visit', 'Pages Per Visit',      '#827717', 'dec2' ),
			array( 'avg_session',     'Avg Session Duration', '#e37400', 'mmss' ),
			array( 'bounce_rate',     'Bounce Rate',          '#d01884', 'pct1' ),
		);
		?>
		<div class="dd-app min-h-screen bg-gray-50 text-[#3c434a] font-sans text-[13px]"
		     id="da-app" data-rest="<?php echo esc_attr( $rest ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-dashboard="<?php echo esc_attr( $dashboard ); ?>" data-connected="<?php echo $connected ? '1' : '0'; ?>">
			<div class="bg-white border-b border-gray-200 shadow-sm" style="position:sticky;top:var(--wp-admin--admin-bar--height,32px);z-index:100;">
				<div class="max-w-5xl px-6 py-4 flex items-center gap-3">
					<div class="p-1.5 rounded text-white inline-flex items-center justify-center" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
					</div>
					<h1 class="text-xl font-bold text-gray-800" style="margin:0;line-height:1.25;">DevDome Analytics</h1>
					<div style="margin-left:auto;display:flex;align-items:center;gap:16px;font-size:13px;font-weight:600;color:#374151;">
						<span>Status: <span class="da-pill <?php echo $connected ? 'is-on' : 'is-off'; ?>" id="da-status"><?php echo $connected ? 'Connected' : 'Not connected'; ?></span></span>
						<?php if ( $connected ) : ?>
							<a class="dd-btn-primary" href="<?php echo esc_url( $dashboard ); ?>" target="_blank" rel="noopener">View Full Analytics</a>
						<?php endif; ?>
						<a class="da-bug-btn" href="<?php echo esc_url( 'https://devdome.com/report-bug?plugin=devdome-analytics&v=' . DEVDALYT_VERSION ); ?>" target="_blank" rel="noopener noreferrer" title="Report a bug" style="width:36px;height:36px;border-radius:50px;border:1px solid #dadce0;background:#fff;display:grid;place-items:center;color:#5f6368;text-decoration:none;">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 2 1.88 1.88"/><path d="M14.12 3.88 16 2"/><path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/><path d="M12 20v-9"/><path d="M6.53 9C4.6 8.8 3 7.1 3 5"/><path d="M6 13H2"/><path d="M3 21c0-2.1 1.7-3.9 3.8-4"/><path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/><path d="M22 13h-4"/><path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/></svg>
						</a>
					</div>
				</div>
				<div class="dd-tabs max-w-5xl" style="margin:0;border-bottom:0;" role="tablist">
					<a class="dd-tab <?php echo 'overview' === $tab ? 'is-active' : ''; ?>" href="#overview" role="tab" data-dd-tab="overview"><span class="dashicons dashicons-visibility"></span> Overview</a>
					<a class="dd-tab <?php echo 'settings' === $tab ? 'is-active' : ''; ?>" href="#settings" role="tab" data-dd-tab="settings"><span class="dashicons dashicons-admin-generic"></span> Settings</a>
				</div>
			</div>
			<main>
				<?php // Both panels render once; switching is instant client-side (hash-synced, ?tab= picks the landing panel). ?>
				<div class="dd-tabpanel<?php echo 'overview' === $tab ? ' is-active' : ''; ?>" data-dd-panel="overview" role="tabpanel">
				<div class="max-w-5xl px-6 py-6 space-y-8">

					<?php if ( ! $connected ) : ?>
					<!-- Connect (centered hero — owns the page until the site is linked) -->
					<section style="display:flex;justify-content:center;padding-top:32px;">
						<div class="dd-card" style="max-width:480px;width:100%;text-align:center;padding:40px 36px;">
							<div style="width:60px;height:60px;border-radius:16px;background:#eef2ff;display:inline-flex;align-items:center;justify-content:center;margin-bottom:18px;">
								<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
							</div>
							<h2 style="margin:0 0 6px;font-size:20px;font-weight:700;color:#1f2937;">Connect your site to DevDome</h2>
							<p style="margin:0 0 12px;font-size:13px;color:#6b7280;">Link this site to your DevDome account to start collecting analytics.</p>
							<p style="margin:0 0 24px;font-size:12px;color:#6b7280;">Opening this screen only checks whether this site is already connected to a DevDome account (it sends the site&rsquo;s domain and its secret site token, nothing else). No visitor tracking starts until the site is connected. Connecting links the site to your account &mdash; see the readme&rsquo;s External services section and the <a href="https://devdome.com/privacy-policy" target="_blank" rel="noopener">privacy policy</a>.</p>

							<?php if ( get_option( 'devdalyt_remote_disconnected', false ) ) : ?>
							<p style="margin:0 0 20px;padding:10px 12px;background:#fef7e0;border:1px solid #f6e3a1;border-radius:8px;color:#7a5900;font-size:13px;">
								<?php esc_html_e( 'This site was disconnected on the DevDome dashboard (removed or its token was rotated). Connect again to resume tracking.', 'devdome-analytics' ); ?>
							</p>
							<?php endif; ?>
							<?php if ( '' !== $oauth_error ) : ?>
							<p style="margin:0 0 20px;padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#b91c1c;font-size:13px;">
								<?php
								echo esc_html(
									'verify' === $oauth_error ? 'We could not verify this site with DevDome. Please try again.'
									: ( 'badid' === $oauth_error ? 'That account did not look right. Please try again.'
									: ( 'start' === $oauth_error ? 'We could not reach DevDome to start the connection. Please try again shortly.'
									: 'That connect link expired. Please try again.' ) )
								);
								?>
							</p>
							<?php endif; ?>

							<div data-panel="account">
								<style>.dd-cgo-btn:hover{background:#1d4ed8 !important;border-color:#1d4ed8 !important;}</style>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
									<input type="hidden" name="action" value="devdalyt_connect_go">
									<?php wp_nonce_field( 'devdalyt_connect_go' ); ?>
									<button type="submit" class="dd-cgo-btn" style="display:inline-flex;align-items:center;justify-content:center;gap:6px;font-size:14px;font-weight:600;border-radius:8px;padding:10px 20px;text-decoration:none;cursor:pointer;line-height:1;white-space:nowrap;transition:.12s;color:#fff;background:#2563eb;border:1px solid #2563eb;box-shadow:0 4px 10px -3px rgba(37,99,235,.5);">Connect your DevDome account</button>
								</form>
								<p class="description" style="margin:12px 0 0;">Opens devdome.com to sign in, then links this site automatically.</p>
							</div>
						</div>
					</section>
					<?php endif; ?>

					<!-- Overview (preview tiles render even before connecting, so the page isn't bare) -->
					<section>
						<div class="dd-sec-head">
							<span class="dashicons dashicons-visibility dd-ico"></span><h2 class="dd-h2">Overview</h2>
							<div class="dd-dd" data-name="da_range" style="margin-left:auto;width:140px;"><input type="hidden" name="da_range" value="1"><div class="dd-dd-trigger" tabindex="0"><span class="dd-dd-label">24 hours</span><svg class="dd-dd-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path></svg></div><div class="dd-dd-panel">
								<div class="dd-dd-opt is-selected" data-value="1">24 hours</div>
								<div class="dd-dd-opt" data-value="7">7 days</div>
								<div class="dd-dd-opt" data-value="30">30 days</div>
								<div class="dd-dd-opt" data-value="90">3 months</div>
								<div class="dd-dd-opt" data-value="365">12 months</div>
							</div></div>
						</div>
						<div class="dd-card">
								<div class="dd-stats" id="da-grid" style="grid-template-columns:repeat(5,1fr);display:grid;">
								<?php foreach ( $metrics as $m ) : ?>
									<div class="dd-stat" style="padding:16px;">
										<div class="dd-stat-num" id="da-m-<?php echo esc_attr( $m[0] ); ?>" style="color:<?php echo esc_attr( $m[2] ); ?>;">—</div>
										<div class="dd-stat-lbl"><?php echo esc_html( $m[1] ); ?></div>
										<?php // (live is its own tile now; no per-tile subtext) ?>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					</section>

					<?php if ( $connected ) : ?>
					<!-- Account (management — only once connected; connecting lives in the hero above) -->
					<section>
						<div class="dd-sec-head"><span class="dashicons dashicons-admin-links dd-ico"></span><h2 class="dd-h2">DevDome Account</h2></div>
						<div class="dd-card">
							<?php if ( $connected && '' !== $account_id ) : ?>
							<p style="margin:0 0 14px;color:#374151;">Account ID: <strong><?php echo esc_html( $account_id ); ?></strong></p>
							<?php endif; ?>
							<div id="da-account-connected" style="display:<?php echo $connected ? 'flex' : 'none'; ?>;flex-wrap:wrap;gap:10px;">
								<button type="button" class="dd-btn" id="da-reset" style="order:2;">Reset Analytics</button>
								<button type="button" class="dd-btn-danger" id="da-disconnect" style="order:1;">Disconnect</button>
								<div id="da-disc-confirm" style="display:none;order:4;flex-basis:100%;width:100%;margin-top:8px;border-top:1px solid #f3f4f6;padding-top:14px;">
									<label class="dd-opt" style="font-weight:600;"><input type="checkbox" class="dd-check" id="da-purge"> Also delete my data on DevDome</label>
									<p class="description" style="margin:6px 0 12px;">Your data is kept and you can reconnect anytime. It&rsquo;s auto-deleted after 90 days of inactivity. Tick the box to delete it now (e.g. before removing the site for good).</p>
									<p style="margin:0 0 8px;color:#374151;">Type <strong>DISCONNECT</strong> to confirm.</p>
										<input type="text" id="da-disc-input" class="dd-input" autocomplete="off" placeholder="DISCONNECT" style="max-width:240px;margin-bottom:10px;">
										<div style="display:flex;gap:10px;">
											<button type="button" class="dd-btn-danger" id="da-disc-yes" disabled>Disconnect</button>
											<button type="button" class="dd-btn" id="da-disc-no">Cancel</button>
										</div>
								</div>
							</div>
							<div id="da-reset-confirm" style="display:none;margin-top:8px;border-top:1px solid #f3f4f6;padding-top:14px;">
									<p style="margin:0 0 8px;font-weight:600;color:#374151;">This permanently deletes all collected data and starts fresh — it can’t be undone.</p>
										<p style="margin:0 0 8px;color:#374151;">Type <strong>RESET</strong> to confirm.</p>
										<input type="text" id="da-reset-input" class="dd-input" autocomplete="off" placeholder="RESET" style="max-width:240px;margin-bottom:10px;">
										<div style="display:flex;gap:10px;">
											<button type="button" class="dd-btn-danger" id="da-reset-yes" disabled>Reset Analytics</button>
											<button type="button" class="dd-btn" id="da-reset-no">Cancel</button>
										</div>
								</div>
						</div>
					</section>
					<?php endif; ?>

				</div>
				</div>
				<div class="dd-tabpanel<?php echo 'settings' === $tab ? ' is-active' : ''; ?>" data-dd-panel="settings" role="tabpanel">
				<div class="max-w-5xl px-6 py-6 space-y-8">

					<!-- Tracking -->
					<section>
						<div class="dd-sec-head"><span class="dashicons dashicons-chart-bar dd-ico"></span><h2 class="dd-h2">Tracking</h2></div>
						<div class="dd-card">
							<table class="form-table"><tbody>
							<?php
							$state         = self::build_state();
							$switch_values = $state['settings'];
							$fp_connected  = ! empty( $state['connected'] );
							$fp_allowed    = ! empty( $switch_values['first_party_allowed'] );
							$fp_authfail   = ! empty( $switch_values['first_party_auth_failed'] );
							foreach ( self::SWITCHES as $sw ) :
								list( $key, $label, $desc ) = $sw;
								$on     = ! empty( $switch_values[ $key ] );
								$frozen = ( 'first_party' === $key && ( ! $fp_connected || ! $fp_allowed ) ); ?>
								<?php
								// Service entitlement gate (Pro and up): the reason sits in its own
								// full-width strip ABOVE the frozen row, never inside the value cell.
								if ( $frozen && ! $fp_connected ) : ?>
									<tr>
										<td colspan="2" style="padding:20px 0 16px;">
											<style>.dd-cgo-btn:hover{background:#1d4ed8 !important;border-color:#1d4ed8 !important;}</style>
											<div style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;">
												<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;">
													<input type="hidden" name="action" value="devdalyt_connect_go">
													<?php wp_nonce_field( 'devdalyt_connect_go' ); ?>
													<button type="submit" class="dd-cgo-btn" style="display:inline-flex;align-items:center;justify-content:center;gap:6px;font-size:14px;font-weight:600;border-radius:8px;padding:10px 20px;text-decoration:none;cursor:pointer;line-height:1;white-space:nowrap;transition:.12s;color:#fff;background:#2563eb;border:1px solid #2563eb;box-shadow:0 4px 10px -3px rgba(37,99,235,.5);"><?php esc_html_e( 'Connect your DevDome account', 'devdome-analytics' ); ?></button>
												</form>
												<span style="font-size:13px;color:#6b7280;"><?php esc_html_e( 'Requires a DevDome account.', 'devdome-analytics' ); ?></span>
											</div>
										</td>
									</tr>
								<?php elseif ( $frozen ) : ?>
									<tr>
										<td colspan="2" style="padding:20px 0 16px;">
											<p style="margin:0;font-size:13px;color:#6b7280;">
												<?php esc_html_e( 'Included in the Pro plan and above.', 'devdome-analytics' ); ?>
												<a href="https://devdome.com/pricing/" target="_blank" rel="noopener"><?php esc_html_e( 'See plans', 'devdome-analytics' ); ?></a>
											</p>
										</td>
									</tr>
								<?php endif; ?>
								<tr<?php echo $frozen ? ' style="opacity:.5;pointer-events:none;"' : ''; ?>>
									<th><?php echo esc_html( $label ); ?></th>
									<td>
										<label class="dd-opt"><input type="checkbox" class="dd-check" data-setting="<?php echo esc_attr( $key ); ?>" <?php checked( $on ); ?><?php disabled( $frozen ); ?>> Enable</label>
										<p class="description"><?php echo esc_html( $desc ); ?></p>
										<?php if ( 'first_party' === $key && $fp_authfail ) : ?>
											<p class="description" style="color:#b91c1c;">
												<?php esc_html_e( 'DevDome rejected this site\'s credentials, so First-Party Delivery was switched off. Disconnect and reconnect the site, then enable it again.', 'devdome-analytics' ); ?>
											</p>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody></table>
						</div>
					</section>

					<!-- Excluded roles (saved via the sticky footer, not on change) -->
					<section>
						<div class="dd-sec-head"><span class="dashicons dashicons-groups dd-ico"></span><h2 class="dd-h2">Excluded roles</h2></div>
						<div class="dd-card">
							<div class="dd-opts da-optlist">
								<?php foreach ( $roles as $role => $info ) : ?>
									<label class="dd-opt"><input type="checkbox" class="dd-check" data-role="<?php echo esc_attr( $role ); ?>" <?php checked( in_array( $role, $excluded_roles, true ) ); ?>> <?php echo esc_html( translate_user_role( $info['name'] ) ); ?></label>
								<?php endforeach; ?>
							</div>
							<span class="dd-hint">Logged-in users with these roles are never tracked.</span>
						</div>
					</section>

					<footer class="dd-footer">
						<div class="dd-footer-inner">
							<div class="dd-footer-actions">
								<button type="button" class="dd-btn-primary" id="da-save" style="gap:8px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>Save Settings</button>
								<span id="da-saved-note" style="display:none;color:#059669;font-weight:600;font-size:13px;">Saved.</span>
							</div>
						</div>
					</footer>

				</div>
				</div>
			</main>
		</div>
		<?php
	}
}

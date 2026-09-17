<?php
/**
 * Tracker — injects the DevDome script into <head> on public frontend pages only.
 */

defined( 'ABSPATH' ) || exit;

class DEVDALYT_Tracker {

	/** data-* attributes for the enqueued tag, filled in by inject(). */
	private $tag_attrs = array();

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'inject' ) );
		add_filter( 'script_loader_tag', array( $this, 'tracker_tag' ), 10, 3 );
		// First-party click collector: track.js (and the inline detector below) beacon outbound clicks
		// to this SAME-DOMAIN path so ad-blockers / privacy browsers can't drop them; we forward the
		// event to the analytics backend server-side. Registered on every request (it's an endpoint).
		add_action( 'init', array( $this, 'maybe_collect' ), 0 );
		// Daily refresh of the first-party static script copy (no-op while the switch is off).
		add_action( 'devdalyt_fp_refresh', array( __CLASS__, 'fp_refresh_script' ) );
	}

	/** Same-origin `/dd-e` endpoint: receive an outbound-click beacon and forward it server-side to the
	 *  analytics ingest. Same-domain → never blocked. The link stays clean (no redirect through us). */
	public function maybe_collect() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = untrailingslashit( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		// First-party event relay (2026-08-05): a per-site RANDOM path (never a fixed name a
		// blocklist could target — the Plausible-proven pattern) receives every tracker event
		// and forwards it server-side, so domain-based ad blockers have nothing to block. The
		// script itself is a static file in uploads/ (see fp_script_url), not a PHP endpoint.
		$fp = self::fp_paths( false );
		if ( $fp && $path === self::endpoint_request_path( $fp['ep'] ) ) {
			$this->relay_tracker_event();
		}
		if ( self::endpoint_request_path( '/dd-e' ) !== $path ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			status_header( 204 );
			exit;
		}
		// Master switch off: beacons from pages cached while it was on are dropped here, the one
		// place every sender passes (review 2026-09-11). Same for a visitor's Do Not Track signal:
		// the cached page cannot know it, this request does.
		if ( ! get_option( 'devdalyt_tracking_enabled', true ) ) {
			nocache_headers();
			status_header( 204 );
			exit;
		}
		if ( ( '1' === ( isset( $_SERVER['HTTP_DNT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) : '' ) || '1' === ( isset( $_SERVER['HTTP_SEC_GPC'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) : '' ) )
			&& (bool) get_option( 'devdalyt_respect_dnt', true ) ) {
			nocache_headers();
			status_header( 204 );
			exit;
		}
		// The endpoint path is printed in every page's source, so it is public knowledge —
		// require the browser-stamped same-site Origin/Referer a real page beacon always
		// carries, or this relay is an open forwarder anyone can script against the ingest.
		if ( ! $this->sender_is_same_site() ) {
			status_header( 204 );
			exit;
		}
		// Only a connected site forwards events; an unconnected site injects no tracker, so any
		// POST here is noise — drop it instead of acting as an open forwarder to the ingest.
		if ( ! self::is_connected_cheap() ) {
			status_header( 204 );
			exit;
		}
		// Excluded-role users never contribute events — drop their beacons too (covers pages
		// cached before the exclusion was saved, which still carry the tracker snippet).
		if ( $this->user_is_excluded() ) {
			status_header( 204 );
			exit;
		}
		// The switches are enforced HERE too (review 2026-09-11 round 2): cached HTML keeps the old
		// tag and keeps posting after the admin turned outbound-click tracking off.
		if ( ! get_option( 'devdalyt_tracking_enabled', true ) || ! get_option( 'devdalyt_outbound_tracking_enabled', true ) ) {
			status_header( 204 );
			exit;
		}
		// Light Cloudflare-aware per-IP/minute flood guard (mirrors the devdome-core beacon) so the
		// same-origin endpoint can't be abused to hammer the ingest. Best-effort: if the shared
		// helper is unavailable we skip the guard rather than risk dropping legitimate beacons.
		if ( get_option( 'devdalyt_dnt_admins', true ) && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			nocache_headers(); // "Do Not Track Admins" is enforced here too: the cached page cannot know who is reading it (Codex round 1)
			status_header( 204 );
			exit;
		}
		{
			$ip   = self::limiter_ip(); // the peer, or the Cloudflare header only when Cloudflare delivered the request (Codex round 1)
			$key  = 'devdalyt_dde_' . md5( $ip ) . '_' . (int) floor( time() / 60 );
			$hits = (int) get_transient( $key );
			if ( $hits >= 120 ) {
				status_header( 429 );
				exit;
			}
			set_transient( $key, $hits + 1, 120 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public stateless beacon, no form/state; body is validated below and the ingest re-validates and scores it.
		$body = file_get_contents( 'php://input' );
		if ( false === $body || '' === $body || strlen( $body ) > 8192 ) {
			status_header( 204 );
			exit;
		}
		$data = $this->build_relay_payload( json_decode( $body, true ) );
		if ( null === $data ) {
			status_header( 204 );
			exit;
		}
		$body    = (string) wp_json_encode( $data );
		$headers = array( 'Content-Type' => 'text/plain;charset=UTF-8' );
		// Relay secret: lets the ingest trust the forwarded ip/country (anti-forgery gate).
		// Provisioned outside the plugin - nothing in the suite WRITES it, five plugins read it.
		// It used to live under the bare vendor prefix, the one that got the suite rejected, so
		// this plugin reads its own name. includes/migrate.php copies the old value across on
		// upgrade (COPY, never move: Affiliate Manager, Product Importer and Redirect Manager
		// still read the old row until they get the same rename pass).
		// FLEET OPS: when provisioning a NEW site, write BOTH names until those three are renamed.
		$relay = (string) get_option( 'devdalyt_relay_secret', '' );
		if ( '' !== $relay ) {
			$headers['X-DD-Relay'] = $relay;
		} else {
			// Customer installs have no fleet secret: the site token is what lets the ingest trust the forwarded
			// visitor identity, exactly like the first-party relay (DeepSeek round 6).
			$token = (string) get_option( 'devdcorev1_site_token', '' );
			if ( '' !== $token ) {
				$headers['X-DD-Site-Token'] = $token;
			}
		}
		$endpoint = (string) get_option( 'devdalyt_api_endpoint', DEVDALYT_DEFAULT_API_ENDPOINT );
		wp_remote_post( $endpoint, array(
			'body'     => $body,
			'headers'  => $headers,
			'timeout'  => 4,
			'blocking' => true, // the browser beacon is fire-and-forget; block here so we never drop the forward
		) );
		nocache_headers();
		status_header( 204 );
		exit;
	}

	/**
	 * The request path a browser really uses for a site-relative endpoint. On a subdirectory
	 * install (or subdirectory multisite), home_url('/x') is https://example.com/blog/x — the
	 * page beacons to /blog/x while the stored name is the bare /x, so comparing against the
	 * stored name silently discarded every event on those installs (audit 2026-08-05).
	 *
	 * @param string $ep Site-relative endpoint name, e.g. '/dd-e'.
	 * @return string The path to compare the incoming request against.
	 */
	private static function endpoint_request_path( $ep ) {
		return untrailingslashit( (string) wp_parse_url( home_url( $ep ), PHP_URL_PATH ) );
	}

	/**
	 * Browser-stamped same-site proof for the public beacon endpoints. fetch()/sendBeacon POSTs
	 * carry an Origin (or at least a same-site Referer) the page's own script cannot forge; a
	 * scripted third-party caller controls neither. Not airtight against a targeted forger with
	 * a shell — the same documented trade the ingest's sender gate makes — but it closes the
	 * open-forwarder case: without it, anyone who read the page source could pump events
	 * through this relay with our trusted credential attached.
	 *
	 * @return bool Whether the request proves it came from a page on this site.
	 */
	private function sender_is_same_site() {
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$home = preg_replace( '/^www\./', '', $home );
		foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $h ) {
			if ( empty( $_SERVER[ $h ] ) ) {
				continue;
			}
			$v = trim( sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) );
			if ( '' === $v || 'null' === $v ) {
				continue;
			}
			// The first header present DECIDES (Origin outranks Referer) — same rule as the ingest.
			$host = strtolower( (string) wp_parse_url( $v, PHP_URL_HOST ) );
			$host = preg_replace( '/^www\./', '', $host );
			return '' !== $host && $host === $home;
		}
		return false;
	}

	/**
	 * is_connected() without the possible remote hop: the connected_at option row answers for
	 * every normally-connected site; only legacy installs with a verifiably-linked token but an
	 * empty stamp fall through to the shared hub state (cached 12h). Keeps a visitor's beacon
	 * request from ever blocking on an outbound account check.
	 *
	 * @return bool
	 */
	public static function is_connected_cheap() {
		if ( get_option( 'devdalyt_user_disconnected', false ) || get_option( 'devdalyt_remote_disconnected', false ) ) {
			return false; // an explicit Disconnect, or a definitive rejection, stands whatever stamp survived (Codex rounds 1-2)
		}
		$verdict = get_option( 'devdcorev1_conn_state', false );
		if ( is_array( $verdict ) && empty( $verdict['ok'] ) ) {
			return false; // a stored negative verdict outranks the stamp, exactly as in is_connected() (Codex round 2)
		}
		if ( '' === (string) get_option( 'devdcorev1_site_id', '' ) || '' === (string) get_option( 'devdcorev1_site_token', '' ) ) {
			return false; // a stamp without an identity (partial migration) cannot send anything the ingest would accept (DeepSeek round 3)
		}
		if ( '' !== (string) get_option( 'devdcorev1_connected_at', '' ) ) {
			return true;
		}
		return DEVDALYT_Analytics::is_connected_cached(); // cached only: never a request or an identity rewrite from a visitor (Codex round 4)
	}

	/** The address a relay's flood guard keys on: the Cloudflare header counts only when Cloudflare delivered the request. */
	private static function limiter_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		if ( self::ip_is_cloudflare( $remote ) && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf = filter_var( trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) ), FILTER_VALIDATE_IP );
			if ( false !== $cf ) {
				return $cf;
			}
		}
		return $remote;
	}

	/**
	 * Rebuild the relayed event before it is forwarded. json_decode() parses, it does not
	 * sanitize — so the relay forwards NOTHING it hasn't cleaned itself: the payload is rebuilt
	 * from an allowlist, every field individually validated and sanitized, unknown fields
	 * dropped. /dd-e carries exactly one event, the inline detector's outbound click; anything
	 * else is a probe and is rejected.
	 *
	 * @param mixed $raw Decoded request body.
	 * @return array<string,mixed>|null The clean payload, or null when the body is not the one event we relay.
	 */
	private function build_relay_payload( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw['event_type'] ) || ! is_string( $raw['event_type'] )
			|| 'amazon_outbound_click' !== sanitize_key( $raw['event_type'] ) ) {
			return null;
		}
		$url  = static function ( $k ) use ( $raw ) {
			$v = isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ? esc_url_raw( substr( $raw[ $k ], 0, 2000 ) ) : '';
			return '' !== $v ? $v : null;
		};
		$id   = static function ( $k ) use ( $raw ) {
			return isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ? substr( preg_replace( '/[^A-Za-z0-9._-]/', '', $raw[ $k ] ), 0, 64 ) : '';
		};
		$slug = static function ( $k ) use ( $raw ) {
			return isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ? substr( sanitize_key( $raw[ $k ] ), 0, 32 ) : '';
		};
		$text = static function ( $k, $len ) use ( $raw ) {
			return isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ? substr( sanitize_text_field( $raw[ $k ] ), 0, $len ) : '';
		};
		// Site/account identity always comes from OUR options, never from the client payload —
		// a forged body cannot report clicks into another site or account.
		$site = (string) get_option( 'devdcorev1_site_id', '' );
		$acct = (string) get_option( 'devdcorev1_account_id', '' );
		$asin = isset( $raw['asin'] ) && is_string( $raw['asin'] ) ? substr( preg_replace( '/[^A-Z0-9]/', '', strtoupper( $raw['asin'] ) ), 0, 20 ) : '';
		// page_path is path+query and may hold %XX escapes (sanitize_text_field would strip them):
		// allowlist printable ASCII only — no control bytes, no spaces, nothing a URI can't contain.
		$path = isset( $raw['page_path'] ) && is_string( $raw['page_path'] ) ? substr( preg_replace( '/[^\x21-\x7E]/', '', $raw['page_path'] ), 0, 2000 ) : '';
		$data = array(
			'event_type'  => 'amazon_outbound_click',
			'site_id'     => $site,
			'site_domain' => $site,
			'account_id'  => preg_match( '/^DD\d{8}$/', $acct ) ? $acct : null,
			'page_url'    => $url( 'page_url' ),
			'page_path'   => '' !== $path ? $path : null,
			'referrer'    => $url( 'referrer' ),
			'visitor_id'  => $id( 'visitor_id' ),
			'session_id'  => $id( 'session_id' ),
			'browser'     => $slug( 'browser' ),
			'os'          => $slug( 'os' ),
			'device_type' => $slug( 'device_type' ),
			'user_agent'  => $text( 'user_agent', 500 ),
			'language'    => $text( 'language', 35 ),
			'target_url'  => $url( 'target_url' ),
			'via'         => 'click',
			'asin'        => '' !== $asin ? $asin : null,
		);
		// On a first-party site the clicks ride this lane while pageviews ride the fp relay —
		// tag them too, or the "visitors recovered" metric under-counts and can never be
		// backfilled (the ingest stores the capture marker from day one for that reason).
		if ( get_option( 'devdalyt_first_party', false ) ) {
			$data['fp'] = true;
		}
		return $this->stamp_visitor_identity( $data );
	}

	/**
	 * We forward server-side, so the ingest would otherwise see OUR ip/country. Stamp the real
	 * visitor's country (Cloudflare edge header) and IP into the payload so geo attribution and
	 * per-visitor identity stay correct. Shared by the /dd-e click relay and the /dd-p
	 * first-party event relay.
	 *
	 * @param array<string,mixed> $data Sanitized payload about to be forwarded.
	 * @return array<string,mixed> The payload with country/ip stamped when available.
	 */
	private function stamp_visitor_identity( $data ) {
		// Forwarded-identity trust (audit 2026-08-05): these headers are client-typed text
		// unless the connection itself vouches for them. CF-Connecting-IP / CF-IPCOUNTRY count
		// only when Cloudflare delivered the request (REMOTE_ADDR inside Cloudflare's published
		// ranges); X-Forwarded-For only when a LOCAL reverse proxy terminated the connection
		// (REMOTE_ADDR private/loopback). Anything else falls back to REMOTE_ADDR — before
		// this, a curl with a spoofed header chose the stored visitor IP, its geo/ASN, beat the
		// noCountry bot gate and could dodge or poison the owner's excludeIps rules.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) ) : '';
		$via_cf = self::ip_is_cloudflare( $remote );
		if ( $via_cf && ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$cc = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) ), 0, 2 ) );
			if ( '' !== $cc && 'XX' !== $cc && 'T1' !== $cc ) {
				$data['country'] = $cc;
			}
		}
		// Forward the REAL visitor IP too — without it the ingest hashes OUR server IP, collapsing
		// every relayed event onto one ip_hash (wrong geo, broken per-visitor identity).
		$vip = '';
		if ( $via_cf && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$vip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( '' !== $remote && false === filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE )
			&& ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$vip   = trim( $parts[0] );
		}
		if ( '' === $vip ) {
			$vip = $remote;
		}
		if ( '' !== $vip && filter_var( $vip, FILTER_VALIDATE_IP ) ) {
			$data['ip'] = $vip;
		}
		return $data;
	}

	/**
	 * Is this address one of Cloudflare's published edge ranges? Decides whether the CF-*
	 * headers on the request were stamped by Cloudflare or typed by a client. The list is
	 * https://www.cloudflare.com/ips/ (stable for years; shipped static on purpose — no
	 * runtime fetch). Sites running mod_cloudflare/real-ip already see the visitor's address
	 * in REMOTE_ADDR, return false here, and fall through to exactly that address.
	 *
	 * @param string $ip Connection REMOTE_ADDR.
	 * @return bool
	 */
	private static function ip_is_cloudflare( $ip ) {
		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$v4 = array(
			'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
			'141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
			'197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
			'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		);
		$v6 = array(
			'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
			'2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
		);
		$ranges = ( false !== strpos( $ip, ':' ) ) ? $v6 : $v4;
		foreach ( $ranges as $cidr ) {
			if ( self::ip_in_cidr( $ip, $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Binary CIDR containment for IPv4 and IPv6.
	 *
	 * @param string $ip   Validated IP address.
	 * @param string $cidr Range in a.b.c.d/n or hex::/n form.
	 * @return bool
	 */
	private static function ip_in_cidr( $ip, $cidr ) {
		list( $net, $bits ) = explode( '/', $cidr, 2 );
		$ip_bin  = inet_pton( $ip );
		$net_bin = inet_pton( $net );
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		$rem   = $bits % 8;
		if ( $bytes > 0 && 0 !== substr_compare( $ip_bin, $net_bin, 0, $bytes ) ) {
			return false;
		}
		if ( 0 === $rem ) {
			return true;
		}
		$mask = 0xFF << ( 8 - $rem ) & 0xFF;
		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $net_bin[ $bytes ] ) & $mask );
	}

	/**
	 * First-party resource names, RANDOM per site (Plausible-proven): a fixed path like
	 * /dd-p would be one blocklist line away from dying fleet-wide; hex segments generated
	 * once per site cannot be pattern-matched. `ep` = the event-relay path; `dir`/`file` =
	 * the static script copy under uploads/. Hex-only, so an accidental permalink collision
	 * is practically impossible.
	 *
	 * @param bool $create Generate + persist the names when missing.
	 * @return array{ep:string,dir:string,file:string}|null
	 */
	public static function fp_paths( $create ) {
		$p = get_option( 'devdalyt_fp_paths', null );
		// Shape-validated where they are USED, not just at uninstall: these names become a
		// filesystem write target in fp_refresh_script(), so a tampered option row (which
		// already implies option-write access, but still) must never steer that write.
		// A row that fails the pattern is treated as absent and regenerated.
		if ( is_array( $p ) && ! empty( $p['ep'] ) && ! empty( $p['dir'] ) && ! empty( $p['file'] )
			&& preg_match( '/^\/[a-f0-9]{12}$/', (string) $p['ep'] )
			&& preg_match( '/^[a-f0-9]{10}$/', (string) $p['dir'] )
			&& preg_match( '/^[a-f0-9]{8}\.js$/', (string) $p['file'] ) ) {
			return $p;
		}
		if ( ! $create ) {
			return null;
		}
		$p = array(
			'ep'   => '/' . bin2hex( random_bytes( 6 ) ),
			'dir'  => bin2hex( random_bytes( 5 ) ),
			'file' => bin2hex( random_bytes( 4 ) ) . '.js',
		);
		if ( ! devdalyt_option_write( 'devdalyt_fp_paths', $p ) ) {
			return null; // paths that are not in the database would be advertised once and never answered
		}
		return $p;
	}

	/**
	 * Is First-Party Delivery included in this site's DevDome plan? The gate lives on OUR
	 * service, not in plugin code: it is a service entitlement (Pro and up), asked once a day
	 * and cached. Three distinct outcomes (audit 2026-08-05 — "no answer" must never read as
	 * "yes" when turning the feature ON):
	 *   answered    — the service really replied; `ok` is its verdict.
	 *   auth_failed — the service REJECTED our credential (401/403): the token was rotated or
	 *                 never synced. Not a plan verdict; the relay would be dropped server-side.
	 *   neither     — network/5xx: unknown. `ok` stays true so a flaky connection never stops
	 *                 a paying customer's already-running delivery, but callers that ENABLE
	 *                 must require `answered`.
	 * Real answers cache for a day; failures for an hour, so a blip is retried soon instead of
	 * holding a wrong state for 24h. Returns array{ok,plan,url,answered,auth_failed}.
	 */
	public static function fp_entitlement( $force = false ) {
		$cached = get_transient( 'devdalyt_fp_entitlement' );
		if ( ! $force && is_array( $cached ) ) {
			return $cached + array( 'answered' => false, 'auth_failed' => false );
		}
		$site  = (string) get_option( 'devdcorev1_site_id', '' );
		$token = (string) get_option( 'devdcorev1_site_token', '' );
		$out   = array( 'ok' => true, 'plan' => '', 'url' => 'https://devdome.com/pricing/', 'answered' => false, 'auth_failed' => false );
		if ( '' === $site || '' === $token ) {
			return $out; // not connected yet: nothing to ask
		}
		$base = (string) get_option( 'devdalyt_status_endpoint', DEVDALYT_DEFAULT_STATUS_ENDPOINT );
		$url  = str_replace( '/api/plugin/status', '/api/plugin/entitlements', $base );
		if ( $url === $base ) {
			return $out; // no entitlements endpoint could be derived: not answered, never the status route's answer (DeepSeek round 11)
		}
		// Token in the Authorization header, never the query string: it is a long-lived secret
		// and query strings leak into server/proxy logs (the worker route prefers Bearer).
		$r    = wp_remote_get( add_query_arg( array( 'site' => rawurlencode( $site ) ), $url ), array(
			'timeout' => 6,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		) );
		$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
		if ( 200 === $code ) {
			$b = json_decode( (string) wp_remote_retrieve_body( $r ), true );
			if ( is_array( $b ) && array_key_exists( 'first_party', $b ) ) {
				$out['answered'] = true;
				// A real true, on a successful answer: {"ok":false,"first_party":"false"} used to count as entitled (Codex round 8).
				$out['ok']       = ( ! isset( $b['ok'] ) || true === $b['ok'] ) && true === DEVDALYT_Rest::to_bool( $b['first_party'] );
				$out['plan']     = isset( $b['plan'] ) ? sanitize_key( (string) $b['plan'] ) : '';
				if ( ! empty( $b['upgrade_url'] ) ) {
					$out['url'] = esc_url_raw( (string) $b['upgrade_url'] );
				}
			}
		} elseif ( 401 === $code || 403 === $code ) {
			$out['auth_failed'] = true;
		}
		set_transient( 'devdalyt_fp_entitlement', $out, $out['answered'] ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * The cached entitlement WITHOUT any network fallback — for page renders, which must never
	 * block on a remote call (the old build_state() path could stall up to 6s on a cold cache).
	 *
	 * @return array{ok:bool,plan:string,url:string,answered:bool,auth_failed:bool}
	 */
	public static function fp_entitlement_cached() {
		$cached = get_transient( 'devdalyt_fp_entitlement' );
		if ( is_array( $cached ) ) {
			return $cached + array( 'answered' => false, 'auth_failed' => false );
		}
		return array( 'ok' => true, 'plan' => '', 'url' => 'https://devdome.com/pricing/', 'answered' => false, 'auth_failed' => false );
	}

	/** Provision first-party delivery (called when the switch turns ON): generate the random
	 *  names, pull the script copy, and schedule the daily refresh. */
	/** @return bool true when the relay path, the local script copy AND the daily refresh are all in place. */
	public static function fp_provision() {
		$paths  = self::fp_paths( true );
		$script = self::fp_refresh_script(); // may turn the feature off (entitlement said no): then nothing is scheduled
		if ( ! get_option( 'devdalyt_first_party', false ) ) {
			return false;
		}
		$due = wp_next_scheduled( 'devdalyt_fp_refresh' );
		if ( ! $due ) {
			$due = false !== wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'devdalyt_fp_refresh' ) && wp_next_scheduled( 'devdalyt_fp_refresh' );
		}
		return is_array( $paths ) && $script && (bool) $due;
	}

	/** Tear down the refresh cron (called when the switch turns OFF). The static file may
	 *  stay — it is inert without the enqueue and is replaced on the next provision. */
	public static function fp_unschedule() {
		$ts = wp_next_scheduled( 'devdalyt_fp_refresh' );
		if ( $ts ) {
			wp_unschedule_event( $ts, 'devdalyt_fp_refresh' );
		}
	}

	/**
	 * Place the BUNDLED tracker (assets/track.js, shipped in the plugin zip) as a static file
	 * under uploads/<random>/ — served by the web server with zero PHP per load (and zero
	 * cache-plugin interaction: it is just an asset). Daily cron + provision both land here.
	 * Nothing is downloaded at runtime (wp.org guideline 8: no remote executable code);
	 * tracker updates arrive with plugin releases, so the enqueue's plugin version busts
	 * browser/CDN caches exactly when the bytes can change.
	 *
	 * The cron also re-checks the entitlement. Only a DEFINITIVE outcome changes anything:
	 * "plan says no" turns the feature off; a rejected credential (auth_failed) falls back to
	 * the CDN tag and flags "reconnect required" in the admin — its relayed events were being
	 * dropped server-side as unverified with no signal (audit 2026-08-05). An unreachable
	 * service changes nothing: fail-open protects a feature that is already ON, never enables.
	 *
	 * @return bool Whether a valid local copy exists after the call.
	 */
	public static function fp_refresh_script() {
		if ( ! get_option( 'devdalyt_first_party', false ) || ! self::is_connected_cheap() ) {
			return false; // a disconnected site asks nothing (Codex round 3); disconnect also turns the feature off and unschedules
		}
		$ent = self::fp_entitlement();
		if ( ( ! empty( $ent['answered'] ) && empty( $ent['ok'] ) ) || ! empty( $ent['auth_failed'] ) ) {
			// Only a flag that LANDED takes the cron and the caches with it (DeepSeek round 1): if the write was lost the
			// feature is still on in the database and tomorrow's refresh gets to try again.
			if ( devdalyt_option_write( 'devdalyt_first_party', false ) ) {
				if ( ! empty( $ent['auth_failed'] ) ) {
					devdalyt_option_write( 'devdalyt_fp_auth_failed', 1 );
				}
				self::fp_unschedule();
				// The relay endpoint is baked into cached HTML; regenerate it or cached pages keep
				// beaconing to a path that now drops everything until their TTL runs out.
				DEVDALYT_Rest::purge_page_caches();
			}
			return false;
		}
		if ( ! empty( $ent['answered'] ) ) {
			devdalyt_option_delete( 'devdalyt_fp_auth_failed' );
		}
		$p   = self::fp_paths( true );
		// Paths that are not in the database = no destination (Codex round 2): without this the folder and
		// file names were empty and the "overwrite" move below targeted the uploads root itself.
		if ( ! is_array( $p ) || empty( $p['dir'] ) || empty( $p['file'] ) || ! preg_match( '/^[a-f0-9]{10}$/', (string) $p['dir'] ) || ! preg_match( '/^[a-f0-9]{8}\.js$/', (string) $p['file'] ) ) {
			return self::fp_script_url() !== '';
		}
		$src = DEVDALYT_DIR . 'assets/track.js';
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		// An initialisation that failed (FTP credentials, an unsupported method) leaves an unusable object (Codex round 2).
		if ( true !== WP_Filesystem() || ! $wp_filesystem || ! $wp_filesystem->exists( $src ) ) {
			return self::fp_script_url() !== '';
		}
		$js = (string) $wp_filesystem->get_contents( $src );
		// Sanity bounds: the real tracker is ~20-30 KB. An empty or absurd body must never
		// replace a good copy — a broken read degrades to the previous file or the CDN tag.
		if ( '' === $js || strlen( $js ) > 262144 ) {
			return self::fp_script_url() !== '';
		}
		$up  = wp_get_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . $p['dir'];
		$dst = trailingslashit( $dir ) . $p['file'];
		// Never write through a link (review 2026-09-11): a linked folder or file at the random name
		// would let the copy land outside uploads. Degrade to the CDN tag instead.
		if ( is_link( $up['basedir'] ) || is_link( $dir ) || is_link( $dst ) ) {
			return self::fp_script_url() !== '';
		}
		if ( $wp_filesystem->exists( $dst ) && md5( (string) $wp_filesystem->get_contents( $dst ) ) === md5( $js ) ) {
			self::fp_copy_botd( $wp_filesystem, $dir ); // the bot detector rides along (owner 2026-09-15)
			return self::fp_script_url() !== ''; // already current (no mtime touch); "valid local copy" = one the visitor will be served
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		// Canonical containment (review 2026-09-11 round 2): whatever links sit above uploads, the
		// resolved destination must still be inside the resolved uploads folder.
		$real_dir  = realpath( $dir );
		$real_base = realpath( $up['basedir'] );
		if ( ! $real_dir || ! $real_base || 0 !== strpos( trailingslashit( $real_dir ), trailingslashit( $real_base ) ) ) {
			return self::fp_script_url() !== '';
		}
		// Atomic swap: write a sibling temp file, verify its bytes, then move it over the live copy.
		// A partial write used to replace a working script with a truncated one that
		// fp_script_url() then served because "it exists".
		$tmp = $dst . '.' . substr( md5( uniqid( '', true ) ), 0, 8 ) . '.tmp';
		if ( ! $wp_filesystem->put_contents( $tmp, $js, FS_CHMOD_FILE ) ) {
			$wp_filesystem->delete( $tmp ); // a partial temp file never stays behind (DeepSeek round 11)
			return $wp_filesystem->exists( $dst ) && self::fp_script_url() !== '';
		}
		if ( md5( (string) $wp_filesystem->get_contents( $tmp ) ) !== md5( $js ) || ! $wp_filesystem->move( $tmp, $dst, true ) ) {
			$wp_filesystem->delete( $tmp );
			return $wp_filesystem->exists( $dst ) && self::fp_script_url() !== '';
		}
		self::fp_copy_botd( $wp_filesystem, $dir ); // the bot detector rides along (owner 2026-09-15)
		return self::fp_script_url() !== '';
	}

	/**
	 * The bot detector (assets/botd.js, FingerprintJS BotD, MIT) next to the local tracker copy (owner 2026-09-15):
	 * track.js used to import /botd.js relative to its endpoint, which on a first-party site is the customer's own
	 * domain, so the request 404ed and every pageview went out without a bot verdict. Best effort and fail-open:
	 * a copy that did not land leaves the tag's data-botd empty and track.js falls back to the CDN file.
	 * $dir has already passed the link and containment checks of fp_refresh_script().
	 */
	private static function fp_copy_botd( $fs, $dir ) {
		$src = DEVDALYT_DIR . 'assets/botd.js';
		$dst = trailingslashit( $dir ) . 'botd.js';
		// Its own containment proof (DeepSeek round 7): the "already current" fast path of fp_refresh_script() reaches
		// this before the tracker's realpath() check, and a linked ancestor must never steer this write either.
		$up        = wp_get_upload_dir();
		$real_dir  = realpath( $dir );
		$real_base = realpath( $up['basedir'] );
		if ( is_link( $up['basedir'] ) || is_link( $dir ) || ! $real_dir || ! $real_base || 0 !== strpos( trailingslashit( $real_dir ), trailingslashit( $real_base ) ) ) {
			return false;
		}
		if ( ! $fs->exists( $src ) || is_link( $dst ) ) {
			return false;
		}
		$js = (string) $fs->get_contents( $src );
		if ( '' === $js || strlen( $js ) > 262144 ) {
			return false;
		}
		if ( $fs->exists( $dst ) && md5( (string) $fs->get_contents( $dst ) ) === md5( $js ) ) {
			return true;
		}
		$tmp = $dst . '.' . substr( md5( uniqid( '', true ) ), 0, 8 ) . '.tmp';
		if ( ! $fs->put_contents( $tmp, $js, FS_CHMOD_FILE ) ) {
			$fs->delete( $tmp );
			return false;
		}
		if ( md5( (string) $fs->get_contents( $tmp ) ) !== md5( $js ) || ! $fs->move( $tmp, $dst, true ) ) {
			$fs->delete( $tmp );
			return false;
		}
		return true;
	}

	/** URL of the local bot-detector copy, or '' (track.js then loads the CDN file). Same rules as fp_script_url(). */
	public static function fp_botd_url() {
		if ( ! get_option( 'devdalyt_first_party', false ) ) {
			return ''; // the switch decides, not a file left behind by an earlier provisioning
		}
		$script = self::fp_script_url();
		if ( '' === $script ) {
			return '';
		}
		$p    = self::fp_paths( false );
		$up   = wp_get_upload_dir();
		$fdir = trailingslashit( $up['basedir'] ) . $p['dir'];
		$file = trailingslashit( $fdir ) . 'botd.js';
		if ( is_link( $file ) || ! file_exists( $file ) || (int) @filesize( $file ) < 1024 ) {
			return '';
		}
		return dirname( $script ) . '/botd.js';
	}

	/** URL of the local static script copy, or '' when it does not exist (degrade to CDN). */
	public static function fp_script_url() {
		$p = self::fp_paths( false );
		if ( ! $p ) {
			return '';
		}
		$up   = wp_get_upload_dir();
		$fdir = trailingslashit( $up['basedir'] ) . $p['dir'];
		$file = trailingslashit( $fdir ) . $p['file'];
		// Never serve through a link (DeepSeek round 2): the refresh refuses to WRITE through one, the URL must refuse to SERVE one.
		if ( is_link( $up['basedir'] ) || is_link( $fdir ) || is_link( $file ) || ! file_exists( $file ) || (int) @filesize( $file ) < 1024 ) {
			return ''; // missing, linked or truncated (the real tracker is ~20-30 KB): the CDN tag serves instead
		}
		// Media-offload/CDN plugins filter uploads baseurl to a third-party host. Serving the
		// "first-party" tracker from there defeats the whole feature (a blocker matches the CDN
		// again) and usually 404s — the offloader never uploaded our file, while the local-path
		// check above still passes. Only a same-host URL counts; anything else degrades to the
		// normal CDN tag (audit 2026-08-05).
		$up_host   = strtolower( (string) wp_parse_url( $up['baseurl'], PHP_URL_HOST ) );
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $up_host || $up_host !== $home_host ) {
			return '';
		}
		// The page's own scheme (DeepSeek round 3): an http:// uploads baseurl on an https page is mixed content the browser blocks.
		return trailingslashit( set_url_scheme( $up['baseurl'], is_ssl() ? 'https' : 'http' ) ) . trailingslashit( $p['dir'] ) . $p['file'];
	}

	/**
	 * Same-origin `/dd-p`: first-party event relay. track.js posts EVERY event here (the
	 * injected __DDCFG.endpoint points at this path when First-Party Delivery is on) and we
	 * forward it server-side to the analytics ingest — same pattern as /dd-e, generalized to
	 * the tracker's full event set. The payload is rebuilt from an allowlist, identity comes
	 * from OUR options, and the forward authenticates with the relay secret (fleet) or the
	 * site token so the ingest may trust the stamped visitor ip/country.
	 */
	private function relay_tracker_event() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method || ! get_option( 'devdalyt_first_party', false ) || ! get_option( 'devdalyt_tracking_enabled', true ) || ! self::is_connected_cheap() || $this->user_is_excluded() ) {
			nocache_headers();
			status_header( 204 );
			exit;
		}
		// The random path is printed in every page's source — public knowledge, not a secret.
		// Require the browser-stamped same-site Origin/Referer a real page beacon carries, or
		// this relay is an authenticated open forwarder: anyone could mint trusted events into
		// the dashboard, burn the billed allowance and eat the rate budget (audit 2026-08-05).
		if ( ! $this->sender_is_same_site() ) {
			nocache_headers();
			status_header( 204 );
			exit;
		}
		// Excluded roles and "Do Not Track Admins" are enforced on the relay too: the cached page cannot know who is reading it (Codex round 1).
		if ( $this->user_is_excluded() || ( get_option( 'devdalyt_dnt_admins', true ) && is_user_logged_in() && current_user_can( 'manage_options' ) ) ) {
			nocache_headers();
			status_header( 204 );
			exit;
		}
		// The dashboard's "Respect Do Not Track" cannot see the visitor on a relayed event
		// (the forward comes from this server) — enforce it here, where the header still is.
		if ( ( '1' === ( isset( $_SERVER['HTTP_DNT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) : '' ) || '1' === ( isset( $_SERVER['HTTP_SEC_GPC'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) : '' ) )
			&& (bool) get_option( 'devdalyt_respect_dnt', true ) ) {
			nocache_headers();
			status_header( 204 );
			exit;
		}
		// Same flood guard as /dd-e, its own counter: pageview+engagement is chattier than
		// clicks. Only with a persistent object cache — without one every transient is two
		// wp_options writes PER EVENT, which costs more than the guard protects (the worker
		// has its own per-site limiter either way).
		if ( wp_using_ext_object_cache() ) {
			$ip   = self::limiter_ip();
			$key  = 'devdalyt_ddp_' . md5( $ip ) . '_' . (int) floor( time() / 60 );
			$hits = (int) get_transient( $key );
			if ( $hits >= 240 ) {
				status_header( 429 );
				exit;
			}
			set_transient( $key, $hits + 1, 120 );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public stateless beacon, no form/state; body is validated below and the ingest re-validates and scores it.
		$body = file_get_contents( 'php://input' );
		if ( false === $body || '' === $body || strlen( $body ) > 8192 ) {
			nocache_headers();
			status_header( 204 );
			exit;
		}
		$data = $this->build_fp_payload( json_decode( $body, true ) );
		// Event-specific switches (review 2026-09-11 round 2): pages cached before a switch was turned
		// off still carry the old tag. Not an enumeration of types (the ingest owns that list), just
		// the two families the switches name: outbound clicks and the other clicks.
		if ( null !== $data ) {
			$t        = (string) $data['event_type'];
			$is_out   = false !== strpos( $t, 'outbound' );
			$is_click = false !== strpos( $t, 'click' ) || 'search' === $t; // the search words travel under Track Clicks (Codex round 4)
			if ( ( $is_out && ! get_option( 'devdalyt_outbound_tracking_enabled', true ) )
				|| ( $is_click && ! $is_out && ! get_option( 'devdalyt_click_tracking_enabled', true ) ) ) {
				$data = null;
			}
		}
		if ( null !== $data ) {
			$headers = array( 'Content-Type' => 'text/plain;charset=UTF-8' );
			$relay   = (string) get_option( 'devdalyt_relay_secret', '' );
			if ( '' !== $relay ) {
				$headers['X-DD-Relay'] = $relay;
			} else {
				// Customer installs have no fleet relay secret; the per-site token is what
				// lets the ingest trust the forwarded visitor identity (and the sender).
				$token = (string) get_option( 'devdcorev1_site_token', '' );
				if ( '' !== $token ) {
					$headers['X-DD-Site-Token'] = $token;
				}
			}
			// Non-blocking on purpose: this runs inside a visitor's beacon request, and pageview
			// + engagement pings are chatty — blocking 4s per event exhausted PHP-FPM pools on
			// shared hosting (audit 2026-08-05). The ingest is fire-and-forget; /dd-e stays
			// blocking only because clicks are rare and race page unload.
			wp_remote_post( (string) get_option( 'devdalyt_api_endpoint', DEVDALYT_DEFAULT_API_ENDPOINT ), array(
				'body'     => (string) wp_json_encode( $data ),
				'headers'  => $headers,
				'timeout'  => 2,
				'blocking' => false,
			) );
		}
		nocache_headers();
		status_header( 204 );
		exit;
	}

	/**
	 * Rebuild a relayed tracker event before it is forwarded (allowlist, every field validated,
	 * unknown fields dropped — same contract as build_relay_payload, covering the tracker's
	 * whole event set). Site/account identity always comes from OUR options, never the client.
	 *
	 * @param mixed $raw Decoded request body.
	 * @return array<string,mixed>|null The clean payload, or null when it is not a tracker event.
	 */
	private function build_fp_payload( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw['event_type'] ) || ! is_string( $raw['event_type'] ) ) {
			return null;
		}
		// Sanitized + bounded, NOT enumerated: the ingest is the authority on event types. A
		// PHP-side copy of track.js's list silently dropped everything it lagged behind on
		// (the Amazon mobile-handler events, any future type) with no log and no alarm
		// (audit 2026-08-05). Junk still dies here: sanitize_key strips anything that is not
		// [a-z0-9_-], and the ingest re-validates whatever passes.
		$type = substr( sanitize_key( $raw['event_type'] ), 0, 40 );
		if ( '' === $type ) {
			return null;
		}
		$url  = static function ( $k ) use ( $raw ) {
			$v = isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ? esc_url_raw( substr( $raw[ $k ], 0, 2000 ) ) : '';
			return '' !== $v ? $v : null;
		};
		$id   = static function ( $k ) use ( $raw ) {
			return isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ? substr( preg_replace( '/[^A-Za-z0-9._-]/', '', $raw[ $k ] ), 0, 64 ) : '';
		};
		$slug = static function ( $k ) use ( $raw ) {
			return isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ? substr( sanitize_key( $raw[ $k ] ), 0, 32 ) : '';
		};
		$text = static function ( $k, $len ) use ( $raw ) {
			return isset( $raw[ $k ] ) && is_string( $raw[ $k ] ) ? substr( sanitize_text_field( $raw[ $k ] ), 0, $len ) : '';
		};
		$num  = static function ( $k, $max ) use ( $raw ) {
			return isset( $raw[ $k ] ) && is_numeric( $raw[ $k ] ) ? max( 0, min( (int) $raw[ $k ], $max ) ) : null;
		};
		$site = (string) get_option( 'devdcorev1_site_id', '' );
		$acct = (string) get_option( 'devdcorev1_account_id', '' );
		$asin = isset( $raw['asin'] ) && is_string( $raw['asin'] ) ? substr( preg_replace( '/[^A-Z0-9]/', '', strtoupper( $raw['asin'] ) ), 0, 20 ) : '';
		// page_path is path+query and may hold %XX escapes (sanitize_text_field would strip them):
		// allowlist printable ASCII only — no control bytes, no spaces, nothing a URI can't contain.
		$path = isset( $raw['page_path'] ) && is_string( $raw['page_path'] ) ? substr( preg_replace( '/[^\x21-\x7E]/', '', $raw['page_path'] ), 0, 2000 ) : '';
		$data = array(
			'event_type'    => $type,
			'site_id'       => $site,
			'site_domain'   => $site,
			'account_id'    => preg_match( '/^DD\d{8}$/', $acct ) ? $acct : null,
			'page_url'      => $url( 'page_url' ),
			'page_path'     => '' !== $path ? $path : null,
			'referrer'      => $url( 'referrer' ),
			'visitor_id'    => $id( 'visitor_id' ),
			'session_id'    => $id( 'session_id' ),
			'browser'       => $slug( 'browser' ),
			'os'            => $slug( 'os' ),
			'device_type'   => $slug( 'device_type' ),
			'user_agent'    => $text( 'user_agent', 500 ),
			'language'      => $text( 'language', 35 ),
			'timezone'      => $text( 'timezone', 64 ),
			// Set by track.js only when a social in-app webview stripped the referrer; without
			// it all FB/IG/TikTok traffic on a first-party site collapses into "direct".
			'in_app'        => $text( 'in_app', 100 ),
			'screen_width'  => $num( 'screen_width', 20000 ),
			'screen_height' => $num( 'screen_height', 20000 ),
			'wd'            => true === DEVDALYT_Rest::to_bool( isset( $raw['wd'] ) ? $raw['wd'] : false ),
			'source_marker' => $text( 'source_marker', 100 ),
			'target_url'    => $url( 'target_url' ),
			'via'           => $slug( 'via' ),
			'asin'          => '' !== $asin ? $asin : null,
			// Event-specific extras, each individually bounded.
			'engaged_seconds' => $num( 'engaged_seconds', 100000 ),
			'interacted'      => true === DEVDALYT_Rest::to_bool( isset( $raw['interacted'] ) ? $raw['interacted'] : false ),
			'query'           => $text( 'query', 200 ),
			'host'            => $text( 'host', 100 ),
			'transit_from'    => $text( 'transit_from', 100 ),
			'transit_via'     => $text( 'transit_via', 20 ),
			'rc'              => $num( 'rc', 50 ),
			'botd'            => isset( $raw['botd'] ) && null !== DEVDALYT_Rest::to_bool( $raw['botd'] ) ? DEVDALYT_Rest::to_bool( $raw['botd'] ) : null,
			'botd_kind'       => $slug( 'botd_kind' ),
			'ai_ref'          => get_option( 'devdalyt_ai_tracking_enabled', true ) ? 1 : 0, // the saved switch, never the client's claim (Codex round 7)
			// Marks the event as delivered through this site's own domain, so the dashboard can
			// report how many visitors first-party delivery recovered. Set by US, never the client.
			'fp'              => true,
		);
		// Drop empty-string/null extras so the forwarded body matches what track.js would have
		// sent directly (the ingest treats absent and empty differently for some fields).
		foreach ( array( 'source_marker', 'via', 'query', 'host', 'transit_from', 'transit_via', 'botd_kind', 'timezone', 'in_app' ) as $k ) {
			if ( '' === $data[ $k ] || null === $data[ $k ] ) {
				unset( $data[ $k ] );
			}
		}
		foreach ( array( 'screen_width', 'screen_height', 'engaged_seconds', 'rc', 'botd', 'ai_ref' ) as $k ) {
			if ( null === $data[ $k ] ) {
				unset( $data[ $k ] );
			}
		}
		return $this->stamp_visitor_identity( $data );
	}

	public function inject() {
		if ( ! $this->should_track() ) {
			return;
		}

		$site_id      = (string) get_option( 'devdcorev1_site_id', '' );
		$account_id   = (string) get_option( 'devdcorev1_account_id', '' );
		$script_url   = (string) get_option( 'devdalyt_script_url', DEVDALYT_DEFAULT_SCRIPT_URL );
		$api_endpoint = (string) get_option( 'devdalyt_api_endpoint', DEVDALYT_DEFAULT_API_ENDPOINT );
		// First-Party Delivery (2026-08-05): the BROWSER only ever talks to this site's own
		// domain — the script is a static copy under uploads/<random>/ and events go to a
		// per-site random path — and the server forwards. Domain-based ad blockers have
		// nothing to match, and random names give blocklists nothing to fingerprint. The
		// options above keep the real upstream URLs; only what the page embeds changes.
		// Script copy missing (readonly uploads, failed fetch)? Degrade to the CDN tag —
		// the event relay stays first-party either way.
		$fp_paths = get_option( 'devdalyt_first_party', false ) ? self::fp_paths( false ) : null; // read-only on a visitor render; provisioning creates the paths
		if ( is_array( $fp_paths ) && ! empty( $fp_paths['ep'] ) ) { // paths that could not be stored = plain CDN tag this render (DeepSeek round 1)
			$fp_js    = self::fp_script_url();
			// Copy missing (readonly uploads, a cleanup plugin)? Degrade to the CDN tag and let
			// the daily cron/settings save rebuild it. NEVER rebuild from a visitor's render:
			// on a host where the write keeps failing that turned every single pageview into
			// blocking outbound HTTP, forever (audit 2026-08-05).
			if ( '' !== $fp_js ) {
				$script_url = $fp_js;
			}
			$api_endpoint = home_url( $fp_paths['ep'] );
		}
		$clicks       = get_option( 'devdalyt_click_tracking_enabled', true ) ? 'true' : 'false';
		$outbound     = get_option( 'devdalyt_outbound_tracking_enabled', true ) ? 'true' : 'false';
		$ai           = get_option( 'devdalyt_ai_tracking_enabled', true ) ? 'true' : 'false';
		$dnt          = get_option( 'devdalyt_respect_dnt', true ) ? 'true' : 'false';
		// The ONE place the user-facing switch is inverted into the tracker's flag. Returning-visitor
		// tracking OFF means cookieless: track.js writes NOTHING to the device (no cookie, no
		// localStorage, no outbox) and sends no visitor/session id — the server derives a rotating
		// one from the request instead. Default true so an upgrade never silently drops the cookie.
		$cookieless   = get_option( 'devdalyt_returning_visitors', false ) ? 'false' : 'true'; // a missing row stores nothing on the device

		// Note: the site_token is intentionally NOT printed in the public page —
		// the browser tracker is identified by site_id (domain) only. The token
		// stays server-side and is used for the admin "Test connection" call.
		// data-endpoint tells track.js where to beacon events (the ingest API);
		// the data-*-tracking flags let track.js honor the admin switches.
		//
		// The tag loads `async`, where document.currentScript is null at run time — so the script
		// can't read its own data-* attrs. Print window.__DDCFG first as the reliable config source.
		// data-cfasync="false" keeps Cloudflare Rocket Loader from deferring/rewriting the tag.
		// First-party beacon endpoint on THIS site's own domain. track.js posts the outbound-click here
		// instead of the third-party tracker (so blockers can't drop it); the inline detector below is
		// the no-track.js fallback. `fp` also tells track.js the inline detector owns clicks → no dupe.
		$fp_endpoint = esc_url_raw( home_url( '/dd-e' ) );
		// Escaping for a <script> context: JSON_HEX_* renders <, >, &, ' and " as \uXXXX, so no
		// stored value can close the tag or break out of the literal. JS decodes them identically.
		$js_esc = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

		// Registered, never hand-printed (wp.org: no script blocks written into the markup).
		// The version is the PLUGIN's: the tracker file is served by our CDN and is not
		// versioned by us, but an unversioned enqueue is a Plugin Check warning and
		// leaves a stale file cached in the browser across plugin upgrades.
		wp_enqueue_script( 'devdalyt-tracker', $script_url, array(), DEVDALYT_VERSION, false );

		// Config BEFORE the tag: the tag loads `async`, where document.currentScript is null at
		// run time, so track.js cannot read its own data-* attributes. __DDCFG is the reliable
		// source; the attributes stay for the dashboard ownership check and older track.js.
		$botd_url = self::fp_botd_url(); // '' outside first-party mode: track.js loads the CDN copy
		$cfg_js = sprintf(
			'window.__DDCFG={site:%s,siteId:%s,account:%s,endpoint:%s,respectDnt:%s,cookieless:%s,clicks:%s,outbound:%s,fp:%s,ai:%s,botd:%s,debug:%s};',
			wp_json_encode( $site_id, $js_esc ),
			wp_json_encode( $site_id, $js_esc ),
			wp_json_encode( $account_id, $js_esc ),
			wp_json_encode( $api_endpoint, $js_esc ),
			$dnt,        // 'true' | 'false' literal - track.js honors navigator.doNotTrack when true
			$cookieless, // 'true' | 'false' literal - track.js writes nothing to the device when true
			$clicks,     // 'true' | 'false' literal - track.js sends no click events when false (review 2026-09-11)
			$outbound,   // 'true' | 'false' literal - track.js sends no outbound/ad click events when false
			wp_json_encode( $fp_endpoint, $js_esc ),
			$ai,         // 'true' | 'false' literal - AI-assistant referrers are labelled "ai" on the dashboard when true (owner 2026-09-15)
			wp_json_encode( $botd_url, $js_esc ), // local bot-detector copy in first-party mode, '' = the CDN file
			get_option( 'devdalyt_debug_mode', false ) ? 'true' : 'false' // track.js logs every event it sends to the console (Codex round 8: the switch had no effect)
		);
		wp_add_inline_script( 'devdalyt-tracker', $cfg_js, 'before' );

		// Outbound-click detector, attached AFTER the tag. wp_add_inline_script prints it in its
		// OWN <script> block, so it still runs when an ad-blocker refuses the external src - which
		// is the entire point of it: clicks stay counted when track.js never loads.
		$dnt_on = ( 'true' === $dnt && ( '1' === ( isset( $_SERVER['HTTP_DNT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) : '' ) || '1' === ( isset( $_SERVER['HTTP_SEC_GPC'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) : '' ) ) );
		if ( 'true' === $outbound && ! $dnt_on ) {
			wp_add_inline_script( 'devdalyt-tracker', $this->click_detector_js( $fp_endpoint, $site_id, $account_id, 'true' === $dnt, 'true' === $cookieless ), 'after' );
		}

		// data-account also lets the DevDome dashboard verify ownership by reading the live page.
		$this->tag_attrs = array(
			'data-cfasync'           => 'false', // stops Cloudflare Rocket Loader deferring the tag
			'data-site-id'           => $site_id,
			'data-endpoint'          => $api_endpoint,
			'data-click-tracking'    => $clicks,
			'data-outbound-tracking' => $outbound,
			'data-ai-tracking'       => $ai,
			'data-respect-dnt'       => $dnt,
			'data-cookieless'        => $cookieless,
			'data-no-optimize'       => '1',      // cache/optimiser plugins: leave this tag alone
			'data-no-defer'          => '1',
		);
		if ( '' !== $botd_url ) {
			$this->tag_attrs['data-botd'] = $botd_url;
		}
		if ( '' !== $account_id ) {
			$this->tag_attrs['data-account'] = $account_id;
		}
	}

	/** data-* and async are the two things wp_enqueue_script cannot set, so they go on here. */
	public function tracker_tag( $tag, $handle, $src ) {
		if ( 'devdalyt-tracker' !== $handle || ! $this->tag_attrs ) {
			return $tag;
		}
		// Built by wp_get_script_tag(), not by concatenating a tag ourselves: core
		// escapes every attribute AND Plugin Check's NonEnqueuedScript sniff reads
		// a literal script tag in plugin source as hand-printed markup (one ERROR,
		// and wp.org wants zero).
		$attrs = array_merge( array( 'src' => $src, 'async' => true ), $this->tag_attrs );
		$ours  = wp_get_script_tag( $attrs );
		// WordPress hands this filter the WHOLE block for the handle: the inline "before" config (window.__DDCFG),
		// the src tag and any "after" script. Returning only our tag threw the config away on every WordPress site
		// (live-verified on test2 2026-09-15: no __DDCFG in the page, so track.js ran on its defaults and the
		// switches never reached it). Only the src element is swapped; everything around it stays.
		// Only the element that carries our handle's id (or our src) is swapped; its nonce / type="module" survive on the
		// replacement, and when nothing matches the block is returned untouched (Codex round 7: the old fallback
		// dropped both inline blocks).
		$done    = false;
		$swapped = preg_replace_callback( '~<script\b([^>]*)>\s*</script>\s*~i', function ( $m ) use ( $attrs, $src, &$done ) {
			if ( $done || ( false === strpos( $m[1], 'devdalyt-tracker-js' ) && false === strpos( $m[1], $src ) ) ) {
				return $m[0];
			}
			$done = true;
			// Every attribute of the original element survives (integrity, crossorigin, referrerpolicy, a nonce written with
			// spaces around "="); ours win where they overlap (Codex round 8).
			$orig = array();
			if ( preg_match_all( '/([a-zA-Z_:][-\w:.]*)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?/', $m[1], $aa, PREG_SET_ORDER ) ) {
				foreach ( $aa as $a ) {
					$name = strtolower( $a[1] );
					if ( in_array( $name, array( 'src', 'async', 'defer' ), true ) ) {
						continue;
					}
					$orig[ $name ] = isset( $a[2] ) && '' !== $a[2] ? trim( $a[2], "\"'" ) : true;
				}
			}
			$attrs = array_merge( $orig, $attrs );
			return wp_get_script_tag( $attrs );
		}, (string) $tag );
		return ( $done && is_string( $swapped ) ) ? $swapped : $tag;
	}

	/** The inline, unblockable outbound-click detector. Self-contained (no external file), beacons
	 *  same-origin to /dd-e. Counts a click the moment a visitor navigates to an external domain. */
	private function click_detector_js( $fp, $site_id, $account_id = '', $respect_dnt = true, $cookieless = true ) {
		// Same <script>-context escaping as inject(): JSON_HEX_* keeps <, >, &, ' and " out of the
		// literal so no stored value can close the tag. JS decodes the escapes to the same string.
		$cfg = wp_json_encode(
			array( 'fp' => (string) $fp, 's' => (string) $site_id, 'ac' => (string) $account_id, 'dnt' => (bool) $respect_dnt, 'cl' => (bool) $cookieless ), // cl: cookieless = in-memory ids only (DeepSeek round 7)
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		$js = <<<'DDJS'
(function(){try{
var C=__DDCFG_JSON__,FP=C.fp,S=C.s;
if(window.__ddClick)return;window.__ddClick=1;
if(C.dnt&&(navigator.doNotTrack==='1'||window.doNotTrack==='1'||navigator.msDoNotTrack==='1'||navigator.globalPrivacyControl===true))return;
var M={};function rnd(){return (self.crypto&&crypto.randomUUID)?crypto.randomUUID():(Date.now().toString(36)+Math.random().toString(36).slice(2));}
function gid(k,sn){if(C.cl){return M[k]||(M[k]=rnd());}try{var st=window[sn];var v=st.getItem(k);if(!v){v=rnd();st.setItem(k,v);}return v;}catch(e){return M[k]||(M[k]=rnd());}}
var ua=navigator.userAgent||'';
var os=/Windows/.test(ua)?'windows':/Mac OS/.test(ua)?'macos':/Android/.test(ua)?'android':/iPhone|iPad|iPod/.test(ua)?'ios':/Linux/.test(ua)?'linux':'other';
var br=/Edg\//.test(ua)?'edge':/Firefox\//.test(ua)?'firefox':/Chrome\//.test(ua)?'chrome':(/Safari\//.test(ua)&&!/Chrome\//.test(ua))?'safari':'other';
var dev=/Mobile|Android|iPhone|iPad/.test(ua)?'mobile':'desktop';
var SH=/facebook\.|fb\.me|twitter\.|\/\/x\.com|linkedin\.|instagram\.|pinterest\.|reddit\.|redd\.it|whatsapp|wa\.me|t\.me|telegram|tiktok\.|youtube\.|youtu\.be|sharer|\/intent\/|\/submit\?/i;
document.addEventListener('click',function(e){
var a=e.target&&e.target.closest?e.target.closest('a'):null;if(!a)return;
var h=a.getAttribute('href')||'';if(!h||/^(#|mailto:|tel:|sms:|javascript:)/i.test(h))return;
var u;try{u=new URL(h,location.href);}catch(_){return;}
// Same-host links (incl. /go/ and other transit slugs) are NOT beaconed: the server side of the
// hop logs the redirect event itself — beaconing here double-counted every cloaked buy click.
if(!u.host||u.host===location.host)return;if(SH.test(u.href))return;
var p={event_type:'amazon_outbound_click',site_id:S,site_domain:S,account_id:C.ac||null,page_url:location.href,page_path:location.pathname+location.search,referrer:document.referrer||null,visitor_id:gid('td_vid','localStorage'),session_id:gid('td_sid','sessionStorage'),browser:br,os:os,device_type:dev,user_agent:ua,language:navigator.language,target_url:u.href,via:'click',asin:((a.getAttribute('data-asin')||'').toUpperCase())||null};
var b=JSON.stringify(p);
try{if(navigator.sendBeacon){navigator.sendBeacon(FP,new Blob([b],{type:'text/plain;charset=UTF-8'}));}else{fetch(FP,{method:'POST',body:b,keepalive:true,mode:'same-origin'});}}catch(_){}
},true);
}catch(e){}})();
DDJS;
		return str_replace( '__DDCFG_JSON__', $cfg, $js );
	}

	/** Decide whether the script should be printed for this request. */
	private function should_track() {
		if ( ! self::is_connected_cheap() ) { // the visitor path never verifies remotely or rewrites the identity (DeepSeek round 2)
			return false;
		}
		// A Redirect Manager rule is handling this render (JS/click-gated redirect page) — the page
		// is a transit hop, not content. Without this, every pass-through logged a pageview +
		// engagement (phantom "visits" that also consume the billed pageview allowance); the hop
		// itself is reported by RM's server-side redirect event.
		// $GLOBALS['devdome_rm_matched'] is DevDome Redirect Manager's global, not ours — read only.
		if ( ! empty( $GLOBALS['devdome_rm_matched'] ) ) {
			return false;
		}
		if ( ! get_option( 'devdalyt_tracking_enabled', true ) ) {
			return false;
		}
		// Never on admin / login / ajax / cron / REST / feeds / previews.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_preview() || is_customize_preview() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		// Don't track logged-in administrators (when the "Do not track admins" switch is on).
		if ( get_option( 'devdalyt_dnt_admins', true ) && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return false;
		}
		// Never track logged-in users holding an excluded role (Settings → Excluded roles).
		if ( $this->user_is_excluded() ) {
			return false;
		}
		return true;
	}

	/** True when the current logged-in user holds any excluded role (never tracked). */
	private function user_is_excluded() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$excluded = (array) get_option( 'devdalyt_excluded_roles', array() );
		if ( ! $excluded ) {
			return false;
		}
		return (bool) array_intersect( (array) wp_get_current_user()->roles, $excluded );
	}
}

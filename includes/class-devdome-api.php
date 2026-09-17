<?php
/**
 * API client — talks to DevDome over HTTPS via wp_remote_post.
 * Sends basic technical data plus the site administrator's e-mail address (disclosed in the readme); never visitor, content or order data.
 */

defined( 'ABSPATH' ) || exit;

class DEVDALYT_API {

	/**
	 * Test the connection: POST site_id + site_token (+ technical status) to DevDome.
	 *
	 * @return array{ok:bool,message:string,last_event_at?:string}
	 */
	/**
	 * Delete this site's collected data on DevDome (authenticated by site_id + site_token).
	 * Used by Reset Analytics and by Disconnect when the user opts to delete now.
	 *
	 * @return bool True only when the service answered 2xx AND {"ok":true} (Codex round 1: a 200 with ok:false is not a purge).
	 */
	public function purge() {
		$status   = (string) get_option( 'devdalyt_status_endpoint', DEVDALYT_DEFAULT_STATUS_ENDPOINT );
		$endpoint = str_replace( '/plugin/status', '/plugin/purge', $status );
		$site_id  = (string) get_option( 'devdcorev1_site_id', '' );
		$token    = (string) get_option( 'devdcorev1_site_token', '' );
		if ( '' === $site_id || '' === $token || $endpoint === $status ) {
			return false; // no purge endpoint could be derived: the status endpoint would answer ok:true without deleting anything (DeepSeek round 10)
		}
		$resp = wp_remote_post( $endpoint, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'site_id' => $site_id, 'site_domain' => $site_id, 'site_token' => $token ) ),
		) );
		if ( is_wp_error( $resp ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		return $code >= 200 && $code < 300 && is_array( $data ) && isset( $data['ok'] ) && true === $data['ok'];
	}

	/**
	 * Two-sided connect — step 1. Register a short-lived connect request with DevDome
	 * (site-authenticated) and return the opaque handle the browser will carry. The single-use
	 * nonce is returned to us here and is NEVER placed in a browser URL.
	 *
	 * @param string $return_url Admin URL DevDome redirects the browser back to after authorize.
	 * @return array{ok:bool,request_token?:string,nonce?:string,message?:string}
	 */
	public function connect_start( $return_url ) {
		$status   = (string) get_option( 'devdalyt_status_endpoint', DEVDALYT_DEFAULT_STATUS_ENDPOINT );
		$endpoint = str_replace( '/plugin/status', '/plugin/connect/start', $status );
		$site_id  = (string) get_option( 'devdcorev1_site_id', '' );
		$token    = (string) get_option( 'devdcorev1_site_token', '' );
		if ( '' === $site_id || '' === $token || $endpoint === $status ) { // no connect endpoint derivable = never the status route (DeepSeek round 11)
			return array( 'ok' => false, 'message' => __( 'This site is not initialised yet.', 'devdome-analytics' ) );
		}
		$resp = wp_remote_post( $endpoint, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'site_id' => $site_id, 'site_domain' => $site_id, 'site_token' => $token, 'return_url' => (string) $return_url ) ),
		) );
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'message' => $resp->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 === (int) wp_remote_retrieve_response_code( $resp ) && is_array( $data ) && isset( $data['ok'] ) && true === $data['ok'] && ! empty( $data['request_token'] ) && ! empty( $data['nonce'] ) ) { // ok:true as well as the fields (DeepSeek round 5)
			return array( 'ok' => true, 'request_token' => (string) $data['request_token'], 'nonce' => (string) $data['nonce'] );
		}
		return array( 'ok' => false, 'message' => is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : __( 'Could not start connect.', 'devdome-analytics' ) );
	}

	/**
	 * Two-sided connect — step 3. Claim the authorized request server-to-server and receive the
	 * Account ID (never carried in the browser). Single-use on the backend.
	 *
	 * @param string $request_token Opaque handle returned by connect_start().
	 * @param string $nonce         Single-use nonce returned by connect_start().
	 * @return array{ok:bool,account_id?:string,message?:string}
	 */
	public function connect_claim( $request_token, $nonce ) {
		$status   = (string) get_option( 'devdalyt_status_endpoint', DEVDALYT_DEFAULT_STATUS_ENDPOINT );
		$endpoint = str_replace( '/plugin/status', '/plugin/connect/claim', $status );
		$site_id  = (string) get_option( 'devdcorev1_site_id', '' );
		$token    = (string) get_option( 'devdcorev1_site_token', '' );
		if ( '' === $site_id || '' === $token || $endpoint === $status ) {
			return array( 'ok' => false, 'message' => __( 'This site is not initialised yet.', 'devdome-analytics' ) );
		}
		$resp = wp_remote_post( $endpoint, array(
			'timeout' => 30, // several sequential DB round trips server-side; 15 s abandoned live claims (2026-09-11)
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'site_id' => $site_id, 'site_domain' => $site_id, 'site_token' => $token, 'request_token' => (string) $request_token, 'nonce' => (string) $nonce ) ),
		) );
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'message' => $resp->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 === (int) wp_remote_retrieve_response_code( $resp ) && is_array( $data ) && isset( $data['ok'] ) && true === $data['ok'] && ! empty( $data['account_id'] ) ) {
			return array( 'ok' => true, 'account_id' => (string) $data['account_id'] );
		}
		return array( 'ok' => false, 'message' => is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : __( 'Could not complete connect.', 'devdome-analytics' ) );
	}

	public function test_connection() {
		$endpoint = (string) get_option( 'devdalyt_status_endpoint', DEVDALYT_DEFAULT_STATUS_ENDPOINT );
		$site_id  = (string) get_option( 'devdcorev1_site_id', '' );
		$token    = (string) get_option( 'devdcorev1_site_token', '' );

		if ( '' === $site_id || '' === $token ) {
			return array( 'ok' => false, 'message' => __( 'Enter a Site ID and Site Token first.', 'devdome-analytics' ) );
		}

		$resp = wp_remote_post( $endpoint, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $this->status_payload( $site_id, $token ) ),
		) );

		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'message' => $resp->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( 200 === $code && is_array( $data ) && isset( $data['ok'] ) && true === $data['ok'] ) {
			devdalyt_option_write( 'devdalyt_last_connection_test', gmdate( 'c' ) ); // proved like every write; bookkeeping only, the answer does not depend on it
			if ( ! empty( $data['last_event_at'] ) ) {
				devdalyt_option_write( 'devdalyt_last_event_sent_at', sanitize_text_field( $data['last_event_at'] ) );
			}
			return array(
				'ok'            => true,
				'message'       => __( 'Connected successfully.', 'devdome-analytics' ),
				'last_event_at' => isset( $data['last_event_at'] ) ? (string) $data['last_event_at'] : '',
			);
		}

		$msg = is_array( $data ) && ! empty( $data['error'] )
			? (string) $data['error']
			: sprintf( /* translators: %d: HTTP status code */ __( 'Connection failed (HTTP %d).', 'devdome-analytics' ), $code );
		return array( 'ok' => false, 'message' => $msg );
	}

	/**
	 * Send a server-side bot_visit event (visibility only — never blocks).
	 *
	 * @param array<string,string> $bot { name, type }
	 */
	public function send_bot_visit( $bot ) {
		$endpoint = (string) get_option( 'devdalyt_api_endpoint', DEVDALYT_DEFAULT_API_ENDPOINT );
		$site_id  = (string) get_option( 'devdcorev1_site_id', '' );
		if ( '' === $site_id ) {
			return;
		}

		// Note: the site_token is intentionally NOT sent here. Bot-visit ingest is identified by
		// site_id/site_domain like every other tracker event; the token only gates the private
		// /plugin/* endpoints (status, purge, stats), so it must not ride the public ingest endpoint.
		// Path only, never the query string (DeepSeek round 1): ?email=... or ?token=... must not travel with a bot hit.
		$path    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '/';
		$path    = '' === $path ? '/' : $path;
		$payload = array(
			'event_type'  => 'bot_visit',
			'site_id'     => $site_id,
			'site_domain' => $site_id,
			'url'         => home_url( $path ),
			'path'        => $path,
			'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'bot_name'    => isset( $bot['name'] ) ? sanitize_text_field( $bot['name'] ) : '',
			'bot_type'    => isset( $bot['type'] ) ? sanitize_text_field( $bot['type'] ) : 'unknown_bot',
			'is_known_bot'=> true,
			'timestamp'   => gmdate( 'c' ),
		);

		// Fire-and-forget: don't slow the page for a bot.
		wp_remote_post( $endpoint, array(
			'timeout'  => 4,
			'blocking' => false,
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => wp_json_encode( $payload ),
		) );
	}

	/**
	 * Fetch the overview metric tiles for this site from DevDome. Passes through the
	 * rich fields the admin UI renders (1:1 with the dashboard's /analytics/stats).
	 *
	 * @param int $days Lookback window.
	 * @return array<string,mixed>
	 */
	public function get_stats( $days = 7 ) {
		$days     = min( 365, max( 1, (int) $days ) );
		$endpoint = (string) get_option( 'devdalyt_stats_endpoint', DEVDALYT_DEFAULT_STATS_ENDPOINT );
		$site     = (string) get_option( 'devdcorev1_site_id', '' );
		$token    = (string) get_option( 'devdcorev1_site_token', '' );
		$empty    = array(
			'visitors'            => null,
			'live'                => null,
			'bots'                => null,
			'clicks'              => null,
			'pageviews'           => null,
			'ctr_visitors'        => null,
			'ctr_pageviews'       => null,
			'pages_per_visit'     => null,
			'avg_session_seconds' => null,
			'bounce_rate'         => null,
			'last_event_at'       => '',
			'period_days'         => $days,
		);

		// POST with the site_token (0.6.3+): stats are per-site data, so the backend
		// authenticates the read with the same token that gates /plugin/status.
		$resp = wp_remote_post( $endpoint, array(
			'timeout' => 12,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'site' => $site, 'days' => $days, 'site_token' => $token ) ),
		) );
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return $empty;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $data ) ) {
			return $empty;
		}
		$num = static function ( $key ) use ( $data ) {
			return isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ? $data[ $key ] + 0 : null;
		};
		return array(
			'visitors'            => $num( 'visitors' ),
			'live'                => $num( 'live' ),
			'bots'                => $num( 'bots' ),
			'clicks'              => $num( 'clicks' ),
			'pageviews'           => $num( 'pageviews' ),
			'ctr_visitors'        => $num( 'ctr_visitors' ),
			'ctr_pageviews'       => $num( 'ctr_pageviews' ),
			'pages_per_visit'     => $num( 'pages_per_visit' ),
			'avg_session_seconds' => $num( 'avg_session_seconds' ),
			'bounce_rate'         => $num( 'bounce_rate' ),
			'last_event_at'       => isset( $data['last_event_at'] ) ? (string) $data['last_event_at'] : '',
			'period_days'         => $days,
		);
	}

	/** Technical-only status payload (no user/content data). */
	private function status_payload( $site_id, $token ) {
		return array(
			'site_id'           => $site_id,
			'site_token'        => $token,
			'account_id'        => (string) get_option( 'devdcorev1_account_id', '' ),
			'site_url'          => home_url(),
			'site_domain'       => wp_parse_url( home_url(), PHP_URL_HOST ),
			'site_name'         => get_bloginfo( 'name' ),
			'admin_email'       => get_bloginfo( 'admin_email' ),
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'plugin_version'    => DEVDALYT_VERSION,
			'active_theme_name' => wp_get_theme()->get( 'Name' ),
			'timezone'          => wp_timezone_string(),
			'language'          => get_bloginfo( 'language' ),
			'is_multisite'      => is_multisite(),
		);
	}
}

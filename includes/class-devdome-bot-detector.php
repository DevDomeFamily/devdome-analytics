<?php
/**
 * Bot detector — server-side visibility only. Sends a bot_visit event when the
 * User-Agent clearly matches a known bot, on public frontend requests.
 * This plugin NEVER blocks bots.
 */

defined( 'ABSPATH' ) || exit;

class DEVDALYT_Bot_Detector {

	/** @var DEVDALYT_API */
	private $api;

	/** name => type. */
	const BOTS = array(
		// AI bots.
		'GPTBot'        => 'ai_bot',
		'ChatGPT-User'  => 'ai_bot',
		'ClaudeBot'     => 'ai_bot',
		'PerplexityBot' => 'ai_bot',
		'Google-Extended' => 'ai_bot',
		'Bytespider'    => 'ai_bot',
		'CCBot'         => 'ai_bot',
		// Search / SEO bots.
		'Googlebot'     => 'search_bot',
		'bingbot'       => 'search_bot',
		'Slurp'         => 'search_bot',
		'DuckDuckBot'   => 'search_bot',
		'Baiduspider'   => 'search_bot',
		'YandexBot'     => 'search_bot',
		'AhrefsBot'     => 'seo_bot',
		'SemrushBot'    => 'seo_bot',
		'MJ12bot'       => 'seo_bot',
		'DotBot'        => 'seo_bot',
		'Screaming Frog' => 'seo_bot',
	);

	public function __construct( DEVDALYT_API $api ) {
		$this->api = $api;
		add_action( 'wp', array( $this, 'maybe_report' ) );
	}

	public function maybe_report() {
		if ( ! DEVDALYT_Analytics::is_connected() ) {
			return;
		}
		// The master switch stops EVERY sender, not only the page tag (review 2026-09-11).
		if ( ! get_option( 'devdalyt_tracking_enabled', true ) ) {
			return;
		}
		if ( ! get_option( 'devdalyt_bot_tracking_enabled', true ) ) {
			return;
		}
		// Public frontend requests only — never admin/ajax/cron/REST.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		// The same privacy controls as the page tag (review 2026-09-11 round 3): a Do Not Track
		// request, a logged-in administrator under "do not track admins", or an excluded role
		// must not be reported as a bot visit either.
		if ( '1' === ( isset( $_SERVER['HTTP_DNT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) : '' )
			&& (bool) get_option( 'devdalyt_respect_dnt', true ) ) {
			return;
		}
		if ( is_user_logged_in() ) {
			if ( get_option( 'devdalyt_dnt_admins', true ) && current_user_can( 'manage_options' ) ) {
				return;
			}
			$excluded = (array) get_option( 'devdalyt_excluded_roles', array() );
			if ( $excluded && array_intersect( (array) wp_get_current_user()->roles, $excluded ) ) {
				return;
			}
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $ua ) {
			return;
		}

		foreach ( self::BOTS as $name => $type ) {
			if ( false !== stripos( $ua, $name ) ) {
				$this->api->send_bot_visit( array( 'name' => $name, 'type' => $type ) );
				return; // one report per request
			}
		}
	}
}

<?php
/**
 * WordPress Abilities API layer (WordPress 6.9+): DevDome Analytics exposed as typed, discoverable
 * abilities for AI agents and MCP clients (through the official WordPress MCP Adapter).
 *
 * Coverage = every action of the plugin's own screen, audited against the code (2026-09-11):
 * class-devdome-rest.php (settings save, stats, disconnect with optional purge, reset),
 * class-devdome-admin.php (connect flow, state), class-devdome-api.php (connection test). This
 * plugin is a thin client: it injects the tracker and the numbers live on analytics.devdome.com,
 * so the reads here are the same calls the dashboard widget makes, and every write goes through
 * the same handler the screen uses (one source of truth, same sanitizers, same entitlement rules).
 *
 * Disconnect and reset delete or unlink collected data and require confirm: true; they are
 * annotated destructive. Agent output never carries e-mail addresses or the site token. On
 * WordPress older than 6.9 the API does not exist and this file registers nothing.
 */

defined( 'ABSPATH' ) || exit;

function devdalyt_ability_ids() {
	return array(
		'devdome-analytics/get-status',
		'devdome-analytics/get-stats',
		'devdome-analytics/get-settings',
		'devdome-analytics/test-connection',
		'devdome-analytics/update-settings',
		'devdome-analytics/start-connect',
		'devdome-analytics/disconnect',
		'devdome-analytics/reset-data',
	);
}

add_action( 'wp_abilities_api_categories_init', 'devdalyt_register_ability_category' );
function devdalyt_register_ability_category() {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}
	wp_register_ability_category( 'devdome-analytics', array(
		'label'       => __( 'DevDome Analytics', 'devdome-analytics' ),
		'description' => __( 'Cookieless WordPress analytics with human and bot traffic split: connection status, traffic numbers for the last 1, 7 or 30 days, tracking settings, connect, disconnect and data reset.', 'devdome-analytics' ),
	) );
}

function devdalyt_ability_can() {
	return current_user_can( 'manage_options' );
}

/** start-connect, disconnect, reset-data: the shared-identity capability (manage_network on a subdirectory multisite). */
function devdalyt_ability_can_connect() {
	return current_user_can( devdalyt_connect_cap() );
}

/** read / modify / destroy; destructive: false = reads, null = modifies, true = destructive. */
function devdalyt_ability_meta( $kind, $idempotent = null ) {
	$map = array(
		'read'    => array( 'readonly' => true,  'destructive' => false, 'idempotent' => true ),
		'modify'  => array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ),
		'destroy' => array( 'readonly' => false, 'destructive' => true,  'idempotent' => true ),
	);
	$ann = isset( $map[ $kind ] ) ? $map[ $kind ] : $map['modify'];
	if ( null !== $idempotent ) {
		$ann['idempotent'] = (bool) $idempotent;
	}
	return array(
		'public'       => true,
		'show_in_rest' => true,
		'annotations'  => $ann,
		'mcp'          => array( 'type' => 'tool' ),
	);
}

/** confirm: true or a WP_Error the agent must show the user. */
function devdalyt_ability_confirmed( $input ) {
	if ( is_array( $input ) && isset( $input['confirm'] ) && true === $input['confirm'] ) {
		return true;
	}
	return new WP_Error( 'devdalyt_confirm_required', __( 'This changes the connection or deletes collected data and cannot be undone by the agent. Pass confirm: true after the user agreed.', 'devdome-analytics' ) );
}

/** Output sanitizer at the ability boundary: no e-mail address and no site token reach an agent. */
function devdalyt_ability_strip( $v ) {
	if ( $v instanceof WP_Error ) {
		// error messages and error data are agent output too (review 2026-09-11)
		$e = new WP_Error();
		foreach ( $v->get_error_codes() as $code ) {
			$data = $v->get_error_data( $code );
			foreach ( $v->get_error_messages( $code ) as $m ) {
				$e->add( $code, devdalyt_ability_strip( $m ), null === $data ? null : devdalyt_ability_strip( $data ) );
			}
		}
		return $e;
	}
	if ( is_object( $v ) ) {
		return (object) devdalyt_ability_strip( get_object_vars( $v ) );
	}
	if ( is_array( $v ) ) {
		foreach ( $v as $k => $x ) {
			if ( 'site_token' === $k || 'token' === $k ) {
				$v[ $k ] = '<token>';
				continue;
			}
			$v[ $k ] = devdalyt_ability_strip( $x );
		}
		return $v;
	}
	if ( ! is_string( $v ) || '' === $v ) {
		return $v;
	}
	$v = preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '<email>', $v );
	$token = (string) get_option( 'devdcorev1_site_token', '' );
	if ( '' !== $token && false !== strpos( $v, $token ) ) {
		$v = str_replace( $token, '<token>', $v );
	}
	// Absolute server paths (review 2026-09-11 round 2): an error text like "Read failed at
	// /var/www/site/wp-config.php" is a fingerprint of the host and stays inside the site.
	// URLs keep their paths (a "://" prefix is not touched); only filesystem roots are masked.
	if ( defined( 'ABSPATH' ) && '' !== (string) ABSPATH && false !== strpos( $v, (string) ABSPATH ) ) {
		$v = str_replace( (string) ABSPATH, '<site>/', $v );
	}
	$v = preg_replace( '~(?<![\w:/.])(?:/[A-Za-z0-9._-]+){2,}/?~', '<path>', $v );          // /var/www/site/wp-config.php, /Users/alice/site
	$v = preg_replace( '~(?<![\w])[A-Za-z]:[\\\\/][^\s"\'<>]*~', '<path>', $v );          // C:\inetpub\wwwroot\wp-config.php
	return $v;
}

/* ------------------------------ handlers ------------------------------ */

function devdalyt_ability_settings() {
	return DEVDALYT_Rest::settings_snapshot(); // one shape for the screen, the REST answer and the abilities
}

/**
 * Keys whose requested value COLLECTS MORE than the stored one (review 2026-09-11): switching a tracker on,
 * switching a do-not-track rule off, or removing a role from the exclusions. Those need confirm: true.
 */
function devdalyt_ability_expands_collection( array $body, array $now ) {
	$more = array();
	foreach ( array( 'tracking', 'clicks', 'outbound', 'ai', 'bots', 'returning', 'first_party' ) as $k ) {
		if ( array_key_exists( $k, $body ) && ! empty( $body[ $k ] ) && empty( $now[ $k ] ) ) {
			$more[] = $k;
		}
	}
	foreach ( array( 'dnt', 'dnt_admins' ) as $k ) {
		if ( array_key_exists( $k, $body ) && empty( $body[ $k ] ) && ! empty( $now[ $k ] ) ) {
			$more[] = $k;
		}
	}
	if ( array_key_exists( 'excluded_roles', $body ) && is_array( $body['excluded_roles'] ) ) {
		$want = array_map( 'sanitize_key', $body['excluded_roles'] );
		if ( array_diff( $now['excluded_roles'], $want ) ) {
			$more[] = 'excluded_roles';
		}
	}
	return $more;
}

function devdalyt_ability_get_status( $input = array() ) {
	$connected = DEVDALYT_Analytics::is_connected_cached();
	return array(
		'connected'           => $connected,
		'account_id'          => $connected ? (string) get_option( 'devdcorev1_account_id', '' ) : '',
		'connected_at'        => (string) get_option( 'devdcorev1_connected_at', '' ),
		'connect_started'     => (bool) get_option( 'devdcorev1_connect_started', 0 ),
		'remote_disconnected' => (bool) get_option( 'devdalyt_remote_disconnected', false ),
		'tracking_enabled'    => (bool) get_option( 'devdalyt_tracking_enabled', true ),
		'first_party'         => (bool) get_option( 'devdalyt_first_party', false ),
		'last_event_at'       => (string) get_option( 'devdalyt_last_event_sent_at', '' ),
		'site_id'             => (string) get_option( 'devdcorev1_site_id', '' ),
		'dashboard_url'       => (string) get_option( 'devdalyt_dashboard_url', DEVDALYT_DEFAULT_DASHBOARD_URL ),
		'plugin_version'      => DEVDALYT_VERSION,
		'wordpress_version'   => get_bloginfo( 'version' ),
	);
}

function devdalyt_ability_get_stats( $input = array() ) {
	$input = is_array( $input ) ? $input : array();
	$days  = isset( $input['days'] ) ? (int) $input['days'] : 7;
	if ( ! in_array( $days, array( 1, 7, 30 ), true ) ) {
		$days = 7;
	}
	if ( ! DEVDALYT_Analytics::is_connected_cached() ) {
		// Same rule as the REST stats route: an unconnected site never contacts DevDome.
		return new WP_Error( 'devdalyt_not_connected', __( 'This site is not connected to a DevDome account, so there are no analytics to read. Use start-connect first.', 'devdome-analytics' ) );
	}
	$stats = ( new DEVDALYT_API() )->get_stats( $days );
	$stats['available'] = isset( $stats['visitors'] ) && null !== $stats['visitors'];
	if ( ! $stats['available'] ) {
		$stats['note'] = __( 'DevDome did not answer the stats request; the numbers are empty, not zero. Try again in a minute.', 'devdome-analytics' );
	}
	return $stats;
}

function devdalyt_ability_get_settings( $input = array() ) {
	$s = devdalyt_ability_settings();
	$s['first_party_auth_failed'] = (bool) get_option( 'devdalyt_fp_auth_failed', false );
	$s['connected']               = DEVDALYT_Analytics::is_connected_cached();
	return $s;
}

function devdalyt_ability_test_connection( $input = array() ) {
	// No calling home before the site was ever connected (review 2026-09-11 round 2, wp.org
	// Guideline 7): the probe sends the site token and metadata to DevDome. Without a completed
	// connect, a saved account id or a pressed Connect button there is nothing to test.
	if ( ! DEVDALYT_Analytics::is_connected_cached()
		&& '' === (string) get_option( 'devdcorev1_account_id', '' )
		&& ! get_option( 'devdcorev1_connect_started', 0 ) ) {
		return array( 'ok' => false, 'message' => __( 'This site is not connected to a DevDome account. Connect it first from DevDome Tools.', 'devdome-analytics' ), 'last_event_at' => '', 'connected' => false );
	}
	$r = ( new DEVDALYT_API() )->test_connection();
	return array(
		'ok'            => ! empty( $r['ok'] ),
		'message'       => isset( $r['message'] ) ? (string) $r['message'] : '',
		'last_event_at' => isset( $r['last_event_at'] ) ? (string) $r['last_event_at'] : '',
		'connected'     => DEVDALYT_Analytics::is_connected_cached(),
	);
}

function devdalyt_ability_update_settings( $input = array() ) {
	$input = is_array( $input ) ? $input : array();
	$keys  = array( 'tracking', 'clicks', 'outbound', 'ai', 'bots', 'dnt_admins', 'dnt', 'returning', 'first_party', 'debug', 'delete_on_uninstall', 'excluded_roles' );
	$body  = array();
	foreach ( $keys as $k ) {
		if ( array_key_exists( $k, $input ) ) {
			$body[ $k ] = $input[ $k ];
		}
	}
	if ( ! $body ) {
		return array( 'updated' => false, 'not_applied' => array(), 'settings' => devdalyt_ability_settings() );
	}
	if ( isset( $body['excluded_roles'] ) && ( ! is_array( $body['excluded_roles'] ) || count( array_filter( $body['excluded_roles'], 'is_string' ) ) !== count( $body['excluded_roles'] ) ) ) {
		return new WP_Error( 'devdalyt_invalid_input', __( 'excluded_roles must be a list of role slugs.', 'devdome-analytics' ) ); // strings only: sanitize_key on an array would be a TypeError (DeepSeek round 10)
	}
	// Strict booleans BEFORE the confirm logic (review 2026-09-11 round 2): the string "false" used
	// to count as "turn on" and even pass the "collects more" gate. Refused with the key named.
	foreach ( $body as $k => $v ) {
		if ( 'excluded_roles' === $k ) { continue; }
		$b = DEVDALYT_Rest::to_bool( $v );
		if ( null === $b ) {
			/* translators: %s is the setting key. */
			return new WP_Error( 'devdalyt_invalid_input', sprintf( __( '%s must be true or false.', 'devdome-analytics' ), $k ) );
		}
		$body[ $k ] = $b;
	}
	if ( ! empty( $body['first_party'] ) && ! DEVDALYT_Analytics::is_connected( false ) ) {
		return new WP_Error( 'devdalyt_not_connected', __( 'First-party delivery needs a connected DevDome account (Pro and up). Use start-connect first.', 'devdome-analytics' ) );
	}
	// Collecting MORE about visitors (a tracker on, a do-not-track rule off, a role no longer excluded) is
	// a privacy decision the site owner makes, not the agent: confirm: true, like every DevDome ability that
	// lowers a protection (review 2026-09-11).
	$more = devdalyt_ability_expands_collection( $body, devdalyt_ability_settings() );
	// Scheduling the settings for deletion at uninstall is a data decision too (DeepSeek round 5).
	if ( ! empty( $body['delete_on_uninstall'] ) && empty( devdalyt_ability_settings()['delete_on_uninstall'] ) ) {
		$ok = devdalyt_ability_confirmed( $input );
		if ( is_wp_error( $ok ) ) {
			return new WP_Error( 'devdalyt_confirm_required', __( 'delete_on_uninstall removes every setting when the plugin is uninstalled. Pass confirm: true after the user agreed.', 'devdome-analytics' ) );
		}
	}
	if ( $more ) {
		$ok = devdalyt_ability_confirmed( $input );
		if ( is_wp_error( $ok ) ) {
			/* translators: %s is the comma-separated list of switches being turned on. */
			return new WP_Error( 'devdalyt_confirm_required', sprintf( __( 'This change collects more visitor data (%s). Pass confirm: true after the user agreed.', 'devdome-analytics' ), implode( ', ', $more ) ) );
		}
	}
	// The screen's own save handler: same keys, same sanitizers, same entitlement rule for first_party,
	// same read-back (only what the database holds now counts).
	$r           = DEVDALYT_Rest::apply_settings( $body );
	$now         = isset( $r['settings'] ) && is_array( $r['settings'] ) ? $r['settings'] : devdalyt_ability_settings();
	$not_applied = isset( $r['not_applied'] ) && is_array( $r['not_applied'] ) ? $r['not_applied'] : array();
	$out = array( 'updated' => empty( $not_applied ), 'not_applied' => $not_applied, 'settings' => $now );
	if ( ! empty( $r['notes'] ) && is_array( $r['notes'] ) ) {
		$out['notes'] = array_values( array_map( 'strval', $r['notes'] ) ); // a partial first-party provision is said to the agent too (Codex round 2)
	}
	if ( in_array( 'first_party', $not_applied, true ) ) {
		$out['note'] = __( 'first_party stayed off: the connected account does not include First-Party Delivery, or DevDome did not answer. Nothing else was blocked.', 'devdome-analytics' );
	}
	return $out;
}

function devdalyt_ability_start_connect( $input = array() ) {
	$input = is_array( $input ) ? $input : array();
	$ok    = devdalyt_ability_confirmed( $input ); // the handshake sends the site identity to DevDome: the user says yes first (DeepSeek round 4)
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	if ( DEVDALYT_Analytics::is_connected() ) {
		return new WP_Error( 'devdalyt_already_connected', __( 'This site is already connected to a DevDome account. Use disconnect first to link a different one.', 'devdome-analytics' ) );
	}
	// Exactly what the Connect button does, without the redirect: the user opens the returned link,
	// approves on devdome.com and is sent back to the plugin screen, which completes the link.
	if ( ! devdalyt_option_write( 'devdcorev1_connect_started', time() ) ) {
		return new WP_Error( 'devdalyt_connect_failed', __( 'The connect consent could not be stored on this site (database problem), so the connection was not started. Try again.', 'devdome-analytics' ) );
	}
	$clean = admin_url( 'admin.php?page=devdome-analytics' );
	$start = ( new DEVDALYT_API() )->connect_start( $clean );
	if ( empty( $start['ok'] ) ) {
		return new WP_Error( 'devdalyt_connect_failed', isset( $start['message'] ) ? (string) $start['message'] : __( 'Could not start the connect flow.', 'devdome-analytics' ) );
	}
	if ( ! set_transient( 'devdalyt_conn_' . $start['request_token'], $start['nonce'], 20 * MINUTE_IN_SECONDS ) || get_transient( 'devdalyt_conn_' . $start['request_token'] ) !== $start['nonce'] ) {
		// The approve link only works when its return can be matched to this start (review 2026-09-11).
		return new WP_Error( 'devdalyt_connect_failed', __( 'The connect request could not be stored on this site (object cache or database problem), so its approve link would never complete. Try again.', 'devdome-analytics' ) );
	}
	$account_url = defined( 'DEVDALYT_DEFAULT_ACCOUNT_URL' ) ? DEVDALYT_DEFAULT_ACCOUNT_URL : 'https://devdome.com';
	return array(
		'url'          => $account_url . '/connect/?' . http_build_query( array( 'rt' => $start['request_token'] ) ),
		'expires_in'   => 600,
		'instructions' => __( 'Open this link in the browser where the site owner is signed in to DevDome, approve the site, and you are sent back to the plugin screen. The link works once, for ten minutes.', 'devdome-analytics' ),
	);
}

function devdalyt_ability_disconnect( $input = array() ) {
	$input = is_array( $input ) ? $input : array();
	$ok    = devdalyt_ability_confirmed( $input );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	if ( ! DEVDALYT_Analytics::is_connected() && '' === (string) get_option( 'devdcorev1_account_id', '' ) ) {
		return new WP_Error( 'devdalyt_not_connected', __( 'This site is not connected to a DevDome account.', 'devdome-analytics' ) );
	}
	$purge = isset( $input['purge'] ) ? DEVDALYT_Rest::to_bool( $input['purge'] ) : false;
	if ( null === $purge ) {
		return new WP_Error( 'devdalyt_invalid_input', __( 'purge must be true or false.', 'devdome-analytics' ) ); // "false" as a string never purges (DeepSeek round 1)
	}
	$r = DEVDALYT_Rest::do_disconnect( $purge );
	if ( empty( $r['local_ok'] ) ) {
		return new WP_Error( 'devdalyt_disconnect_incomplete', __( 'The site could not be fully disconnected: a local setting could not be written. Check the database and try again.', 'devdome-analytics' ) );
	}
	return array(
		'disconnected'    => true,
		'remote_unlinked' => ! empty( $r['remote_unlinked'] ),
		'data_purged'     => ! empty( $r['purged'] ),
		'note'            => empty( $r['remote_unlinked'] ) ? __( 'The site is disconnected locally, but the DevDome account server could not be told; it may still list this site. Remove it from the account at devdome.com or reconnect and disconnect again.', 'devdome-analytics' ) : '',
	);
}

function devdalyt_ability_reset_data( $input = array() ) {
	$input = is_array( $input ) ? $input : array();
	$ok    = devdalyt_ability_confirmed( $input );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	if ( ! DEVDALYT_Analytics::is_connected() ) {
		return new WP_Error( 'devdalyt_not_connected', __( 'This site is not connected to a DevDome account, so there is no collected data to delete.', 'devdome-analytics' ) );
	}
	$purged = ( new DEVDALYT_API() )->purge();
	if ( ! $purged ) {
		return new WP_Error( 'devdalyt_reset_failed', __( 'DevDome did not confirm the deletion; the collected data is still there. Try again in a minute.', 'devdome-analytics' ) );
	}
	return array( 'reset' => true, 'connected' => DEVDALYT_Analytics::is_connected() );
}

/* ------------------------------ registration ------------------------------ */

add_action( 'wp_abilities_api_init', 'devdalyt_register_abilities' );
function devdalyt_register_abilities() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	// Empty properties must be a PHP array, not stdClass (core validates by array access).
	$empty   = array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false );
	$confirm = array( 'confirm' => array( 'type' => 'boolean', 'description' => 'Must be true. Ask the user before passing it: this unlinks the site or deletes collected data on DevDome and cannot be undone by the agent.' ) );
	$bool    = function ( $desc ) { return array( 'type' => 'boolean', 'description' => $desc ); };
	$settings_props = array(
		'tracking'            => $bool( 'Master switch: inject the DevDome tracker on the public site.' ),
		'clicks'              => $bool( 'Record clicks on links and buttons.' ),
		'outbound'            => $bool( 'Record outbound clicks to other domains.' ),
		'ai'                  => $bool( 'Record visits from AI assistants and crawlers as a separate segment.' ),
		'bots'                => $bool( 'Record bot visits (shown apart from human traffic).' ),
		'dnt_admins'          => $bool( 'Do not track logged-in administrators.' ),
		'dnt'                 => $bool( 'Respect the browser Do Not Track signal.' ),
		'returning'           => $bool( 'Tell new from returning visitors: the page tracker keeps a first-party id on the device. Off = the page tracker stores nothing (the outbound-click detector keeps an anonymous random id of its own either way).' ),
		'first_party'         => $bool( 'First-Party Delivery: serve the tracker and beacon from this site (needs a connected Pro account; stays off when the account does not include it).' ),
		'debug'               => $bool( 'Debug mode for the tracker.' ),
		'delete_on_uninstall' => $bool( 'Delete the plugin settings when the plugin is uninstalled.' ),
		'excluded_roles'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Role slugs whose logged-in visits are not tracked (only editable roles of this site are kept).' ),
	);
	$settings_out = array( 'type' => 'object', 'properties' => $settings_props );

	// Every answer, success or WP_Error, passes the boundary sanitizer: no e-mail address and no
	// site token reach an agent from any handler.
	$guarded = function ( $cb ) {
		return function ( $input = array() ) use ( $cb ) {
			// Guard window (DESIGN.md 24): a query that failed inside the ability makes the answer a
			// database error, whatever the handler made of the empty read.
			devdalyt_db_guard_begin();
			try {
				$r = call_user_func( $cb, $input );
				if ( devdalyt_db_guard_active() ) { // even over the handler's own error text (DeepSeek round 7): "not connected" from a failed read is a database error
					$r = new WP_Error( 'devdalyt_db_error', devdalyt_db_guard_message() );
				}
			} finally {
				devdalyt_db_guard_end();
			}
			if ( is_wp_error( $r ) ) {
				$clean = new WP_Error();
				foreach ( $r->get_error_codes() as $code ) {
					foreach ( $r->get_error_messages( $code ) as $msg ) {
						$clean->add( $code, devdalyt_ability_strip( $msg ), devdalyt_ability_strip( $r->get_error_data( $code ) ) );
					}
				}
				return $clean;
			}
			return devdalyt_ability_strip( $r );
		};
	};
	$reg = function ( $id, $label, $desc, $in, $out, $cb, $kind, $idempotent = null ) use ( $guarded ) {
		$conn = in_array( $id, array( 'devdome-analytics/start-connect', 'devdome-analytics/disconnect', 'devdome-analytics/reset-data' ), true );
		wp_register_ability( $id, array(
			'label'               => $label,
			'description'         => $desc,
			'category'            => 'devdome-analytics',
			'input_schema'        => $in,
			'output_schema'       => $out,
			'execute_callback'    => $guarded( $cb ),
			'permission_callback' => $conn ? 'devdalyt_ability_can_connect' : 'devdalyt_ability_can',
			'meta'                => devdalyt_ability_meta( $kind, $idempotent ),
		) );
	};

	$reg( 'devdome-analytics/get-status', __( 'Get analytics status', 'devdome-analytics' ),
		__( 'Whether this WordPress site is connected to a DevDome Analytics account, the public account id, when it connected, whether a connect is in progress or the account server reported the site unlinked, whether tracking and First-Party Delivery are on, when the last event was sent, and the dashboard link. Read-only. Same facts as the Overview tab.', 'devdome-analytics' ),
		$empty, array( 'type' => 'object', 'properties' => array(
			'connected' => $bool( '' ), 'account_id' => array( 'type' => 'string' ), 'connected_at' => array( 'type' => 'string' ), 'connect_started' => $bool( '' ), 'remote_disconnected' => $bool( '' ),
			'tracking_enabled' => $bool( '' ), 'first_party' => $bool( '' ), 'last_event_at' => array( 'type' => 'string' ), 'site_id' => array( 'type' => 'string' ), 'dashboard_url' => array( 'type' => 'string' ),
			'plugin_version' => array( 'type' => 'string' ), 'wordpress_version' => array( 'type' => 'string' ),
		) ),
		'devdalyt_ability_get_status', 'read' );

	$reg( 'devdome-analytics/get-stats', __( 'Get traffic numbers', 'devdome-analytics' ),
		__( 'Traffic of this site for the last 1, 7 or 30 days from DevDome Analytics, the same numbers the plugin dashboard shows: human visitors, live visitors, bot visits, clicks, pageviews, click-through rates, pages per visit, average session seconds, bounce rate, last event time. Cookieless and bot-filtered. Needs a connected account; an unconnected site never contacts DevDome. Read-only.', 'devdome-analytics' ),
		array( 'type' => 'object', 'properties' => array( 'days' => array( 'type' => 'integer', 'enum' => array( 1, 7, 30 ), 'default' => 7, 'description' => 'Period: 1 (today), 7 or 30 days.' ) ), 'additionalProperties' => false ),
		array( 'type' => 'object', 'properties' => array(
			'period_days' => array( 'type' => 'integer' ), 'available' => $bool( 'false when DevDome did not answer; every number is then null, not zero.' ), 'note' => array( 'type' => 'string' ),
			'visitors' => array( 'type' => array( 'number', 'null' ) ), 'live' => array( 'type' => array( 'number', 'null' ) ), 'bots' => array( 'type' => array( 'number', 'null' ) ), 'clicks' => array( 'type' => array( 'number', 'null' ) ),
			'pageviews' => array( 'type' => array( 'number', 'null' ) ), 'ctr_visitors' => array( 'type' => array( 'number', 'null' ) ), 'ctr_pageviews' => array( 'type' => array( 'number', 'null' ) ), 'pages_per_visit' => array( 'type' => array( 'number', 'null' ) ),
			'avg_session_seconds' => array( 'type' => array( 'number', 'null' ) ), 'bounce_rate' => array( 'type' => array( 'number', 'null' ) ), 'last_event_at' => array( 'type' => 'string' ),
		) ),
		'devdalyt_ability_get_stats', 'read' );

	$reg( 'devdome-analytics/get-settings', __( 'Get analytics settings', 'devdome-analytics' ),
		__( 'Every tracking setting of DevDome Analytics as stored: master tracking switch, click, outbound, AI and bot tracking, do-not-track for administrators and for the browser signal, returning-visitor detection, First-Party Delivery (and whether its entitlement check failed), debug mode, delete-on-uninstall, excluded roles, and whether the site is connected. Read-only.', 'devdome-analytics' ),
		$empty, array( 'type' => 'object', 'properties' => array_merge( $settings_props, array( 'first_party_auth_failed' => $bool( '' ), 'connected' => $bool( '' ) ) ) ),
		'devdalyt_ability_get_settings', 'read' );

	$reg( 'devdome-analytics/test-connection', __( 'Test the DevDome connection', 'devdome-analytics' ),
		__( 'Ask the DevDome service whether this site is linked and reachable (the Test connection button): ok, the service message and the time of the last event received. Makes one request to analytics.devdome.com and records when it ran and, when the service reports one, the time of the last event; changes no tracking setting.', 'devdome-analytics' ),
		$empty, array( 'type' => 'object', 'properties' => array( 'ok' => $bool( '' ), 'message' => array( 'type' => 'string' ), 'last_event_at' => array( 'type' => 'string' ), 'connected' => $bool( '' ) ) ),
		'devdalyt_ability_test_connection', 'modify', false ); // every call sends a new probe and stamps the result: not idempotent (DeepSeek round 2)

	$reg( 'devdome-analytics/update-settings', __( 'Update analytics settings', 'devdome-analytics' ),
		__( 'Change any tracking settings; only the keys you pass change, through the same handler the Settings screen uses. Every value is read back from the database before updated: true is returned; keys that could not be applied are listed in not_applied (first_party stays off unless the connected account includes First-Party Delivery and DevDome answered; excluded_roles keeps only roles that exist on this site). Turning tracking off stops data collection until turned on again. Changes that collect MORE visitor data (turning a tracker on, turning a do-not-track rule off, removing an excluded role) require confirm: true; ask the user first. Same validation as the Settings screen.', 'devdome-analytics' ),
		array( 'type' => 'object', 'properties' => array_merge( $settings_props, array( 'confirm' => array( 'type' => 'boolean', 'description' => 'Required (true) when delete_on_uninstall is switched on, and when the change collects more visitor data: a tracker switched on, a do-not-track rule switched off, a role removed from the exclusions. Ask the user first.' ) ) ), 'additionalProperties' => false ),
		array( 'type' => 'object', 'properties' => array( 'updated' => $bool( 'true only when every passed key holds the passed value now' ), 'not_applied' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ), 'notes' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Warnings about a partial First-Party Delivery provision.' ), 'note' => array( 'type' => 'string' ), 'settings' => $settings_out ) ),
		'devdalyt_ability_update_settings', 'modify', true );

	$reg( 'devdome-analytics/start-connect', __( 'Start connecting to a DevDome account', 'devdome-analytics' ),
		__( 'Start the one-click connect (the Connect button): returns a devdome.com link the site owner opens in a browser where they are signed in to DevDome; approving there sends them back to the plugin screen, which completes the link. The link works once for ten minutes; each call creates a new one. Refused when the site is already connected.', 'devdome-analytics' ),
		array( 'type' => 'object', 'properties' => array( 'confirm' => array( 'type' => 'boolean', 'description' => 'Must be true: starting the connect sends this site\'s identity to DevDome. Ask the user first.' ) ), 'required' => array( 'confirm' ), 'additionalProperties' => false ), array( 'type' => 'object', 'properties' => array( 'url' => array( 'type' => 'string' ), 'expires_in' => array( 'type' => 'integer' ), 'instructions' => array( 'type' => 'string' ) ) ),
		'devdalyt_ability_start_connect', 'modify', false );

	$reg( 'devdome-analytics/disconnect', __( 'Disconnect from the DevDome account', 'devdome-analytics' ),
		__( 'Unlink this site from its DevDome account (the Disconnect button): tracking stops sending, the local account identity is cleared and the account server is told; page caches are purged. With purge: true the collected data of this site on DevDome is deleted as well (otherwise it expires with the retention period). Cannot be undone by the agent: requires confirm: true; ask the user first. remote_unlinked: false means the account server could not be told and may still list the site.', 'devdome-analytics' ),
		array( 'type' => 'object', 'properties' => array_merge( $confirm, array( 'purge' => $bool( 'Also delete all collected data of this site on DevDome. Default false.' ) ) ), 'required' => array( 'confirm' ), 'additionalProperties' => false ),
		array( 'type' => 'object', 'properties' => array( 'disconnected' => $bool( '' ), 'remote_unlinked' => $bool( '' ), 'data_purged' => $bool( '' ), 'note' => array( 'type' => 'string' ) ) ),
		'devdalyt_ability_disconnect', 'destroy', false );

	$reg( 'devdome-analytics/reset-data', __( 'Delete collected analytics data', 'devdome-analytics' ),
		__( 'Delete all analytics data collected for this site on DevDome and start fresh; the site stays connected (the Reset analytics button). Cannot be undone: requires confirm: true; ask the user first. Reported as done only when DevDome confirmed the deletion.', 'devdome-analytics' ),
		array( 'type' => 'object', 'properties' => $confirm, 'required' => array( 'confirm' ), 'additionalProperties' => false ),
		array( 'type' => 'object', 'properties' => array( 'reset' => $bool( '' ), 'connected' => $bool( '' ) ) ),
		'devdalyt_ability_reset_data', 'destroy', false );
}

/**
 * Official WordPress MCP Adapter: list our abilities as direct tools on its default server
 * (next to its discover / execute meta-tools). Harmless when the adapter is not installed.
 */
add_filter( 'mcp_adapter_default_server_config', 'devdalyt_mcp_default_server_tools' );
function devdalyt_mcp_default_server_tools( $config ) {
	if ( ! is_array( $config ) ) {
		return $config;
	}
	$tools = isset( $config['tools'] ) && is_array( $config['tools'] ) ? $config['tools'] : array();
	$config['tools'] = array_values( array_unique( array_merge( $tools, devdalyt_ability_ids() ) ) );
	return $config;
}

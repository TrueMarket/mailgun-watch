<?php
/**
 * Dev tool: simulates Mailgun webhook calls against this site and checks
 * the Email Log reacts correctly — no Mailgun account, DNS or public URL
 * needed. Every event is signed with WPEL_MAILGUN_SIGNING_KEY exactly the
 * way Mailgun signs them, so it exercises the real handle_webhook() path.
 *
 * Run from the site shell (Local → right-click site → Open site shell):
 *
 *   wp eval-file wp-content/plugins/mailgun-mail-monitor/tools/simulate-webhook.php [options]
 *
 * Options (positional, because `wp eval-file` rejects unknown --flags):
 *   row=<id>    Use an existing log row instead of creating a fake one.
 *               Handy after a real send — note it really changes that row.
 *   http        POST over HTTP to the site's REST URL instead of dispatching
 *               in-process. Tests the web server / permalinks too.
 *   url=<url>   POST over HTTP to this webhook URL (implies http), e.g. a
 *               cloudflared/ngrok tunnel URL, to check the tunnel works
 *               before pointing Mailgun at it.
 *   cleanup     Delete the fake row afterwards (never deletes a row=<id> row).
 *
 * Only sends accepted / delivered / opened events, so it never triggers
 * failure alerts (email/SMS).
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

if ( ! class_exists( 'WPEL_Mailgun_Monitor' ) ) {
	WP_CLI::error( 'Mailgun Watch is not active on this site.' );
}

global $wpdb;

/* Options ------------------------------------------------------------------ */

$opts = array(
	'row'     => 0,
	'http'    => false,
	'url'     => '',
	'cleanup' => false,
);
foreach ( isset( $args ) ? (array) $args : array() as $arg ) {
	list( $k, $v ) = array_pad( explode( '=', $arg, 2 ), 2, null );
	if ( ! array_key_exists( $k, $opts ) ) {
		WP_CLI::error( "Unknown option '{$arg}'. Valid: row=<id>, http, url=<url>, cleanup." );
	}
	$opts[ $k ] = null === $v ? true : $v;
}
$opts['row'] = (int) $opts['row'];
if ( $opts['url'] ) {
	$opts['http'] = true;
}

$signing_key = wpel_shared_credential( 'WPEL_MAILGUN_SIGNING_KEY', 'signing_key' );
if ( ! $signing_key ) {
	WP_CLI::error( 'No webhook signing key found. Define WPEL_MAILGUN_SIGNING_KEY in wp-config.php (any string works for local testing).' );
}

$table    = WPEL_Mailgun_Monitor::table();
$settings = get_option( WPEL_OPTION, array() );
$domain   = ! empty( $settings['domain'] ) ? strtolower( trim( $settings['domain'] ) ) : 'example.test';
$endpoint = $opts['url'] ? $opts['url'] : rest_url( 'wpel/v1/mailgun-webhook' );

/* Helpers ------------------------------------------------------------------ */

$get_row = function ( $id ) use ( $wpdb, $table ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
};

/**
 * Builds a Mailgun-shaped webhook payload. $sig_mode: 'valid', 'bad'
 * (wrong signature) or 'stale' (valid signature, 20-minute-old timestamp).
 */
$build_payload = function ( $event, $message_id, $recipient, $subject, $sig_mode = 'valid' ) use ( $signing_key, $domain ) {
	$timestamp = (string) ( 'stale' === $sig_mode ? time() - 20 * MINUTE_IN_SECONDS : time() );
	$token     = bin2hex( random_bytes( 25 ) );
	$signature = hash_hmac( 'sha256', $timestamp . $token, $signing_key );
	if ( 'bad' === $sig_mode ) {
		$signature = hash_hmac( 'sha256', $timestamp . $token, 'not-the-real-key' );
	}

	$data = array(
		'event'     => $event,
		'id'        => bin2hex( random_bytes( 11 ) ),
		'timestamp' => microtime( true ),
		'recipient' => $recipient,
		'envelope'  => array( 'sender' => 'bounce@' . $domain ),
		'message'   => array(
			'headers' => array(
				'message-id' => $message_id,
				'subject'    => $subject,
				'from'       => 'WordPress <wordpress@' . $domain . '>',
				'to'         => $recipient,
			),
		),
	);
	if ( 'delivered' === $event ) {
		$data['delivery-status'] = array( 'code' => 250, 'message' => 'OK' );
	}
	if ( 'opened' === $event ) {
		$data['ip']             = '203.0.113.10';
		$data['client-info']    = array( 'client-name' => 'Simulator', 'device-type' => 'desktop' );
	}

	return array(
		'signature'  => array(
			'timestamp' => $timestamp,
			'token'     => $token,
			'signature' => $signature,
		),
		'event-data' => $data,
	);
};

/** Sends a payload to the webhook; returns the HTTP status code (0 on transport error). */
$send = function ( $payload ) use ( $opts, $endpoint ) {
	$json = wp_json_encode( $payload );

	if ( $opts['http'] ) {
		$response = wp_remote_post(
			$endpoint,
			array(
				'headers'   => array( 'Content-Type' => 'application/json' ),
				'body'      => $json,
				'timeout'   => 20,
				'sslverify' => false, // Local's self-signed certs.
			)
		);
		if ( is_wp_error( $response ) ) {
			WP_CLI::warning( 'HTTP error: ' . $response->get_error_message() );
			return 0;
		}
		return (int) wp_remote_retrieve_response_code( $response );
	}

	$request = new WP_REST_Request( 'POST', '/wpel/v1/mailgun-webhook' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( $json );
	return (int) rest_do_request( $request )->get_status();
};

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = $ok;
	$line      = ( $ok ? '%G  PASS%n ' : '%R  FAIL%n ' ) . $label . ( $detail ? " %y({$detail})%n" : '' );
	WP_CLI::log( WP_CLI::colorize( $line ) );
};

/* Target row --------------------------------------------------------------- */

$created_row = false;
if ( $opts['row'] ) {
	$row = $get_row( $opts['row'] );
	if ( ! $row ) {
		WP_CLI::error( "Log row #{$opts['row']} doesn't exist." );
	}
	$row_id     = (int) $row->id;
	$message_id = (string) $row->mailgun_message_id;
	$recipient  = (string) $row->recipient;
	$subject    = (string) $row->subject;
	if ( '' === $message_id ) {
		WP_CLI::warning( "Row #{$row_id} has no Mailgun message id, so events will rely on the recipient+subject fallback match (only works for rows under 30 minutes old)." );
	}
} else {
	$now        = current_time( 'mysql' );
	$message_id = 'wpel-sim-' . wp_generate_password( 12, false ) . '@' . $domain;
	$recipient  = 'webhook-test@example.com';
	$subject    = '[Webhook simulator] ' . $now;
	$wpdb->insert(
		$table,
		array(
			'created_at'         => $now,
			'updated_at'         => $now,
			'recipient'          => $recipient,
			'subject'            => $subject,
			'status'             => 'sent',
			'mailgun_message_id' => $message_id,
			'event_log'          => wp_json_encode( array( array( 't' => $now, 'e' => 'simulator:created' ) ) ),
		)
	);
	$row_id      = (int) $wpdb->insert_id;
	$created_row = true;
	if ( ! $row_id ) {
		WP_CLI::error( 'Could not create a test row: ' . $wpdb->last_error );
	}
}

WP_CLI::log( '' );
WP_CLI::log( 'Target:   ' . ( $opts['http'] ? $endpoint : 'in-process REST dispatch (add "http" to go through the web server)' ) );
WP_CLI::log( "Log row:  #{$row_id}" . ( $created_row ? ' (fake row created for this run)' : '' ) );
WP_CLI::log( "Msg id:   {$message_id}" );
WP_CLI::log( '' );

/* 1. Signature checks ------------------------------------------------------ */

WP_CLI::log( 'Signature verification' );
$before = $get_row( $row_id );

$code = $send( $build_payload( 'delivered', $message_id, $recipient, $subject, 'bad' ) );
if ( 0 === $code ) {
	WP_CLI::error( "Couldn't reach {$endpoint}. Check the URL / tunnel is up." );
}
$check( 'Wrong signature is rejected with 403', 403 === $code, "got {$code}" );

$code = $send( $build_payload( 'delivered', $message_id, $recipient, $subject, 'stale' ) );
$check( 'Stale (20 min old) timestamp is rejected with 403', 403 === $code, "got {$code}" );

$after = $get_row( $row_id );
$check( 'Rejected events left the row untouched', $before->status === $after->status && $before->event_log === $after->event_log );

/* 2. Delivery events ------------------------------------------------------- */

WP_CLI::log( '' );
WP_CLI::log( 'Delivery events' );

$code = $send( $build_payload( 'accepted', $message_id, $recipient, $subject ) );
$row  = $get_row( $row_id );
$check( 'accepted → 200, status "sent"', 200 === $code && 'sent' === $row->status, "HTTP {$code}, status {$row->status}" );

$code = $send( $build_payload( 'delivered', $message_id, $recipient, $subject ) );
$row  = $get_row( $row_id );
$check( 'delivered → 200, status "delivered"', 200 === $code && 'delivered' === $row->status, "HTTP {$code}, status {$row->status}" );

/* 3. Open tracking --------------------------------------------------------- */

WP_CLI::log( '' );
WP_CLI::log( 'Open tracking' );

$opens_before = (int) $row->open_count;

$code = $send( $build_payload( 'opened', $message_id, $recipient, $subject ) );
$row  = $get_row( $row_id );
$check( 'First open → 200, open_count +1', 200 === $code && (int) $row->open_count === $opens_before + 1, "HTTP {$code}, open_count {$row->open_count}" );
$check( 'first_opened_at and last_opened_at are set', ! empty( $row->first_opened_at ) && ! empty( $row->last_opened_at ), "first {$row->first_opened_at}" );
$check( 'Open did not overwrite the delivery status', 'delivered' === $row->status, "status {$row->status}" );

$first_opened = $row->first_opened_at;
sleep( 1 ); // So a changed first_opened_at would be visible.

$code = $send( $build_payload( 'opened', $message_id, $recipient, $subject ) );
$row  = $get_row( $row_id );
$check( 'Second open → open_count +2', 200 === $code && (int) $row->open_count === $opens_before + 2, "open_count {$row->open_count}" );
$check( 'Second open kept the original first_opened_at', $row->first_opened_at === $first_opened, "first {$row->first_opened_at}, last {$row->last_opened_at}" );

$rows_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
$code        = $send( $build_payload( 'opened', 'unknown-' . wp_generate_password( 12, false ) . '@' . $domain, 'nobody@example.com', 'No such email' ) );
$rows_after  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
$check( 'Open for an unknown message → 200, no new row created', 200 === $code && $rows_before === $rows_after, "HTTP {$code}, rows {$rows_before} → {$rows_after}" );

/* Summary ------------------------------------------------------------------ */

WP_CLI::log( '' );
WP_CLI::log( 'Row event log: ' . $row->event_log );

if ( $created_row && $opts['cleanup'] ) {
	$wpdb->delete( $table, array( 'id' => $row_id ) );
	WP_CLI::log( "Deleted fake row #{$row_id}." );
} elseif ( $created_row ) {
	WP_CLI::log( "Fake row #{$row_id} kept so you can see it in Mailgun Watch → Email Log (add \"cleanup\" to delete it automatically)." );
}

$failed = count( array_filter( $results, function ( $ok ) { return ! $ok; } ) );
WP_CLI::log( '' );
if ( $failed ) {
	WP_CLI::error( "{$failed} of " . count( $results ) . ' checks failed.' );
}
WP_CLI::success( 'All ' . count( $results ) . ' checks passed.' );

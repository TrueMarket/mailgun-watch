<?php
/**
 * Core monitor: pre-send capture, send-time enrich, webhook reconcile,
 * failure handling/alerting, and log table access.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPEL_Mailgun_Monitor {

	/** @var WPEL_Mailgun_Monitor */
	private static $instance;

	/** Marker header we add to our own alert emails so we never log/alert on them (loop guard). */
	const SKIP_HEADER = 'X-WPEL-Skip: 1';

	/** Option key holding recent-failure timestamps used for outage detection. */
	const FAILWIN_OPTION = 'wpel_recent_failures';

	/**
	 * Row id created in the wp_mail filter, consumed by WPEL_Mailer's pre_wp_mail
	 * hook. The two fire in sequence within a single wp_mail() call ('wp_mail'
	 * filter, then 'pre_wp_mail' filter), so this hands the just-created row to
	 * the mailer that knows the real Mailgun send outcome and message id.
	 *
	 * @var int
	 */
	private $last_row_id = 0;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Capture + failure hooks. wp_mail_failed only fires natively for sends
		// that go through WordPress's default transport (i.e. when WPEL_Mailer
		// declines to handle a send because Mailgun isn't configured/enabled);
		// Mailgun API send failures are reported directly by WPEL_Mailer via
		// mark_failed_and_alert().
		add_filter( 'wp_mail', array( $this, 'capture_outgoing' ), 99 );
		add_action( 'wp_mail_failed', array( $this, 'on_local_failure' ), 10, 1 );

		// Webhook endpoint.
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Cron pruning + unopened-email sweep.
		add_action( 'wpel_prune_logs', array( $this, 'prune_logs' ) );
		add_action( 'wpel_check_unopened', array( $this, 'check_unopened' ) );
	}

	/* -------------------------------------------------------------------- */
	/* Schema helper                                                          */
	/* -------------------------------------------------------------------- */

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpel_email_log';
	}

	private function opt( $key, $fallback = '' ) {
		$o = get_option( WPEL_OPTION, array() );
		return isset( $o[ $key ] ) ? $o[ $key ] : $fallback;
	}

	/* -------------------------------------------------------------------- */
	/* Path 1: local pre-send capture                                        */
	/* -------------------------------------------------------------------- */

	/**
	 * Filters wp_mail args: logs a pending row and stashes its id for WPEL_Mailer's
	 * pre_wp_mail hook (which fires immediately after this) to enrich with the
	 * real send outcome and Mailgun's message id.
	 *
	 * We do NOT modify the message here — no header injection.
	 *
	 * @param array $args to, subject, message, headers, attachments
	 * @return array unchanged
	 */
	public function capture_outgoing( $args ) {
		// Reset first so a skipped (alert) email or a non-wp_mail send can't leave
		// a stale id for the next pre_wp_mail call (WPEL_Mailer::send()) to mis-attribute.
		$this->last_row_id = 0;

		// Normalize headers to an array of strings (for logging only).
		$headers = isset( $args['headers'] ) ? $args['headers'] : array();
		if ( is_string( $headers ) ) {
			$headers = array_filter( array_map( 'trim', explode( "\n", str_replace( "\r\n", "\n", $headers ) ) ) );
		} elseif ( ! is_array( $headers ) ) {
			$headers = array();
		}

		// Never log our own alert emails (loop guard). Leaves last_row_id at 0 so
		// WPEL_Mailer::send() skips logging/alerting for this message.
		foreach ( $headers as $h ) {
			if ( stripos( $h, 'X-WPEL-Skip' ) === 0 ) {
				return $args;
			}
		}

		$to = isset( $args['to'] ) ? $args['to'] : '';
		if ( is_array( $to ) ) {
			$to = implode( ', ', $to );
		}

		$store_body = (int) $this->opt( 'store_body', 0 );

		$this->last_row_id = $this->insert_row(
			array(
				'recipient' => $to,
				'subject'   => isset( $args['subject'] ) ? $args['subject'] : '',
				'headers'   => wp_json_encode( $headers ),
				'body'      => $store_body ? ( isset( $args['message'] ) ? $args['message'] : '' ) : null,
				'status'    => 'pending',
			)
		);

		return $args;
	}

	/**
	 * Consume (read and reset) the row id created a moment earlier in
	 * capture_outgoing(). Called once by WPEL_Mailer's pre_wp_mail hook per
	 * wp_mail() call. Returns 0 for sends that capture_outgoing chose not to
	 * log (our own alert emails, see SKIP_HEADER).
	 *
	 * @return int
	 */
	public function consume_last_row_id() {
		$row_id            = (int) $this->last_row_id;
		$this->last_row_id = 0;
		return $row_id;
	}

	/**
	 * Called by WPEL_Mailer after Mailgun's API accepts a message. Stamps the
	 * assigned message id onto the row so the async delivery webhook can
	 * correlate by message id with no ambiguity.
	 *
	 * Deliberately does NOT count as a "success" for outage detection — API
	 * acceptance isn't delivery confirmation; only the webhook's `delivered`
	 * event resets the outage window (see handle_webhook / record_success).
	 *
	 * @param int    $row_id
	 * @param string $message_id
	 */
	public function mark_sent( $row_id, $message_id ) {
		$fields = array( 'status' => 'sent' );
		if ( $message_id ) {
			$fields['mailgun_message_id'] = $this->normalize_message_id( $message_id );
		}
		$this->update_row( $row_id, $fields, 'mailgun_api: accepted' );
	}

	/**
	 * Called by WPEL_Mailer when the Mailgun API send itself fails. Marks the
	 * row failed and runs it through the same alerting path as every other
	 * failure source (dedupes with a webhook failure for the same row via the
	 * shared 'send:<row_id>' key).
	 *
	 * @param int    $row_id
	 * @param string $recipient
	 * @param string $subject
	 * @param string $error_message
	 * @param string $source
	 */
	public function mark_failed_and_alert( $row_id, $recipient, $subject, $error_message, $source = 'Mailgun API send' ) {
		$this->update_row(
			$row_id,
			array(
				'status'        => 'failed',
				'error_message' => $error_message,
			),
			$source
		);

		$this->handle_failure(
			array(
				'recipient'  => $recipient,
				'subject'    => $subject,
				'reason'     => $error_message,
				'source'     => $source,
				'row_id'     => $row_id,
				'dedupe_key' => 'send:' . $row_id, // dedupes with a later webhook failure for the same row
			)
		);
	}

	/** Mailgun's API returns the id wrapped in angle brackets; webhooks send it bare. */
	private function normalize_message_id( $id ) {
		return trim( (string) $id, " \t\n\r<>" );
	}

	/**
	 * Immediate, local (PHP-level) send failure. Only fires for sends that went
	 * through WordPress's default transport — i.e. WPEL_Mailer declined to
	 * handle this send because Mailgun isn't configured/enabled (Mailgun API
	 * failures are reported directly via WPEL_Mailer::send() -> mark_failed_and_alert(),
	 * which never reaches this handler). A strong signal the site may be
	 * unable to send at all.
	 *
	 * @param WP_Error $error
	 */
	public function on_local_failure( $error ) {
		$data       = $error->get_error_data();
		$to         = '';
		$subject    = '';
		if ( is_array( $data ) ) {
			$to      = isset( $data['to'] ) ? ( is_array( $data['to'] ) ? implode( ', ', $data['to'] ) : $data['to'] ) : '';
			$subject = isset( $data['subject'] ) ? $data['subject'] : '';
		}
		$message = $error->get_error_message();

		// Try to attach to the most recent matching pending row; otherwise log a new failed row.
		$row_id = $this->find_recent_pending( $to, $subject );
		if ( $row_id ) {
			$this->update_row(
				$row_id,
				array(
					'status'        => 'failed',
					'error_message' => $message,
				),
				'local_failure: ' . $message
			);
		} else {
			$row_id = $this->insert_row(
				array(
					'recipient'     => $to,
					'subject'       => $subject,
					'status'        => 'failed',
					'error_message' => $message,
				)
			);
		}

		$this->handle_failure(
			array(
				'recipient'  => $to,
				'subject'    => $subject,
				'reason'     => $message,
				'source'     => 'local wp_mail_failed',
				'row_id'     => $row_id,
				'dedupe_key' => 'send:' . $row_id, // shares the key space WPEL_Mailer uses for the same row
			)
		);
	}

	/* -------------------------------------------------------------------- */
	/* Path 2: Mailgun webhook reconcile                                     */
	/* -------------------------------------------------------------------- */

	public function register_routes() {
		register_rest_route(
			'wpel/v1',
			'/mailgun-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => '__return_true', // We verify Mailgun's signature ourselves.
			)
		);
	}

	public function handle_webhook( WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( empty( $payload ) || ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid payload' ), 400 );
		}

		if ( ! $this->verify_signature( isset( $payload['signature'] ) ? $payload['signature'] : array() ) ) {
			return new WP_REST_Response( array( 'error' => 'bad signature' ), 403 );
		}

		$event = isset( $payload['event-data'] ) ? $payload['event-data'] : array();
		$type  = isset( $event['event'] ) ? $event['event'] : '';

		$recipient  = isset( $event['recipient'] ) ? $event['recipient'] : '';
		$message_id = isset( $event['message']['headers']['message-id'] ) ? $this->normalize_message_id( $event['message']['headers']['message-id'] ) : '';
		$subject    = isset( $event['message']['headers']['subject'] ) ? $event['message']['headers']['subject'] : '';
		$severity   = isset( $event['severity'] ) ? $event['severity'] : '';
		$reason     = isset( $event['delivery-status']['message'] ) ? $event['delivery-status']['message'] : ( isset( $event['reason'] ) ? $event['reason'] : '' );

		// UPSERT resolution: message id (stamped at send time) -> recent unlinked
		// row by recipient+subject (covers the case where send_after didn't fire) ->
		// create a fresh row from the webhook payload. Shared by every event type,
		// including 'opened' below.
		$row_id = 0;
		if ( $message_id ) {
			$row_id = $this->find_by_message_id( $message_id );
		}
		if ( ! $row_id ) {
			$row_id = $this->find_recent_unlinked( $recipient, $subject );
		}

		// 'opened' can fire long after (and possibly many times after) 'delivered',
		// so it must not clobber the delivery status — record it on its own
		// counters instead and skip the status upsert below entirely. A row that
		// can't be matched (e.g. already pruned) is silently dropped: with no send
		// row to attach to, there's nothing useful to log.
		if ( 'opened' === $type ) {
			if ( $row_id && $this->row_exists( $row_id ) ) {
				$this->record_open( $row_id );
			}
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		// Map Mailgun event -> our status.
		$is_failure = false;
		switch ( $type ) {
			case 'delivered':
				$status = 'delivered';
				$this->record_success(); // resets outage window
				break;
			case 'accepted':
				$status = 'sent';
				break;
			case 'failed':
				if ( 'temporary' === $severity ) {
					$status     = 'temp-fail';
					$is_failure = (bool) $this->opt( 'alert_temp_fail', 0 );
				} else {
					$status     = 'failed';
					$is_failure = true;
				}
				break;
			case 'complained':
				$status = 'complained';
				break;
			default:
				$status = $type ? $type : 'unknown';
		}

		$fields = array(
			'status'             => $status,
			'mailgun_message_id' => $message_id,
		);
		if ( $reason ) {
			$fields['error_message'] = $reason;
		}

		if ( $row_id && $this->row_exists( $row_id ) ) {
			$this->update_row( $row_id, $fields, $type . ( $severity ? ':' . $severity : '' ) );
		} else {
			$fields['recipient'] = $recipient;
			$fields['subject']   = $subject;
			$row_id              = $this->insert_row( $fields, $type );
		}

		if ( $is_failure ) {
			$this->handle_failure(
				array(
					'recipient'  => $recipient,
					'subject'    => $subject,
					'reason'     => $reason ? $reason : ( $type . ' / ' . $severity ),
					'source'     => 'Mailgun webhook (' . $type . ')',
					'row_id'     => $row_id,
					// Distinct phase from the send-time failure: an accepted message
					// that bounces later is genuinely new information and should alert.
					'dedupe_key' => 'delivery:' . $row_id . ':' . $type,
				)
			);
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Verify Mailgun's HMAC signature: hexdigest = HMAC-SHA256(key, timestamp + token).
	 * Also rejects stale timestamps to blunt replay attacks.
	 */
	private function verify_signature( $sig ) {
		$key = wpel_shared_credential( 'WPEL_MAILGUN_SIGNING_KEY', 'signing_key' );
		if ( ! $key ) {
			// No key configured => refuse rather than trust unsigned input.
			return false;
		}
		if ( empty( $sig['timestamp'] ) || empty( $sig['token'] ) || empty( $sig['signature'] ) ) {
			return false;
		}
		if ( abs( time() - (int) $sig['timestamp'] ) > 15 * MINUTE_IN_SECONDS ) {
			return false;
		}
		$computed = hash_hmac( 'sha256', $sig['timestamp'] . $sig['token'], $key );
		return hash_equals( $computed, (string) $sig['signature'] );
	}

	/* -------------------------------------------------------------------- */
	/* Failure handling + notifications                                      */
	/* -------------------------------------------------------------------- */

	/**
	 * Central failure handler: always tells Slack, attempts an alert email,
	 * and escalates to a distinct outage alarm when sends are failing en masse.
	 */
	private function handle_failure( $ctx ) {
		$recipient = isset( $ctx['recipient'] ) ? $ctx['recipient'] : '(unknown)';
		$subject   = isset( $ctx['subject'] ) ? $ctx['subject'] : '(no subject)';
		$reason    = isset( $ctx['reason'] ) ? $ctx['reason'] : '(no reason given)';
		$source    = isset( $ctx['source'] ) ? $ctx['source'] : '';
		$row_id    = isset( $ctx['row_id'] ) ? (int) $ctx['row_id'] : 0;

		// Dedupe: a send failure and a later webhook failure for the same row
		// share the 'send:<row_id>' key. Alert only once per key within a short window.
		if ( ! empty( $ctx['dedupe_key'] ) ) {
			$lock = 'wpel_alerted_' . md5( $ctx['dedupe_key'] );
			if ( get_transient( $lock ) ) {
				return;
			}
			set_transient( $lock, 1, 10 * MINUTE_IN_SECONDS );
		}

		$outage = $this->record_failure(); // true when threshold crossed

		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		// 1) Slack — disabled for now (SMS via Twilio below covers this channel instead).
		// See notify_slack() further down if this needs to be re-enabled.
		// $this->notify_slack(
		// 	sprintf(
		// 		"*Email send failed on %s*\n• To: %s\n• Subject: %s\n• Reason: %s\n• Source: %s\n• Log row: %s",
		// 		$host,
		// 		$recipient,
		// 		$subject,
		// 		$reason,
		// 		$source,
		// 		$row_id ? '#' . $row_id : 'n/a'
		// 	)
		// );

		// 1b) SMS via Twilio — same trigger, independent channel, survives an email outage.
		$this->notify_twilio(
			sprintf(
				"%s - email send failed\nTo: %s\nSubject: %s\nReason: %s\nSource: %s\n%s",
				$host,
				$recipient,
				$subject,
				$reason,
				$source,
				admin_url( 'admin.php?page=wpel-log' )
			)
		);

		// 2) Escalate: whole-site email outage. The alert email cannot get out
		//    in this state, so this specifically leans on the Twilio SMS channel.
		if ( $outage ) {
			$this->notify_outage_alarm();
		}

		// 3) Best-effort alert email (may itself fail if email is down; SMS has it covered).
		$this->notify_email(
			'[Mailgun Watch] Send failure on ' . wp_parse_url( home_url(), PHP_URL_HOST ),
			sprintf(
				"A message failed to send.\n\nTo: %s\nSubject: %s\nReason: %s\nSource: %s\nLog row: %s\n\nReview: %s",
				$recipient,
				$subject,
				$reason,
				$source,
				$row_id ? '#' . $row_id : 'n/a',
				admin_url( 'admin.php?page=wpel-log' )
			)
		);
	}

	private function notify_email( $subject, $body ) {
		$to = $this->opt( 'alert_email', get_option( 'admin_email' ) );
		if ( ! $to ) {
			return;
		}
		// SKIP_HEADER prevents this email from being logged or re-triggering alerts.
		wp_mail( $to, $subject, $body, array( self::SKIP_HEADER ) );
	}

	private function notify_slack( $text ) {
		$url = $this->opt( 'slack_webhook', '' );
		if ( ! $url && defined( 'WPEL_SLACK_WEBHOOK' ) ) {
			$url = WPEL_SLACK_WEBHOOK;
		}
		if ( ! $url ) {
			return;
		}
		wp_remote_post(
			$url,
			array(
				'timeout'  => 8,
				'blocking' => false, // fire-and-forget so a slow Slack never stalls a request
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( array( 'text' => $text ) ),
			)
		);
	}

	private function notify_outage_alarm() {
		// Throttle: at most one outage alarm per window.
		if ( get_transient( 'wpel_outage_alarm_sent' ) ) {
			return;
		}
		$window = (int) $this->opt( 'outage_window', 15 );
		set_transient( 'wpel_outage_alarm_sent', 1, $window * MINUTE_IN_SECONDS );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		// Slack — disabled for now, see notify_slack() and the note in handle_failure().
		// $this->notify_slack(
		// 	":rotating_light: *POSSIBLE TOTAL EMAIL OUTAGE* on " . $host . "\n" .
		// 	"Multiple sends have failed with no successful delivery in the last {$window} minutes. " .
		// 	"The site may be unable to send *any* email right now, so email alerts likely aren't getting through — this Slack message is the fallback. " .
		// 	"Check Mailgun status/credentials and the plugin log: " . admin_url( 'admin.php?page=wpel-log' )
		// );

		$this->notify_twilio(
			sprintf(
				"%s - POSSIBLE TOTAL EMAIL OUTAGE\nMultiple sends have failed with no successful delivery in the last %d minutes. The site may be unable to send any email right now.\n%s",
				$host,
				$window,
				admin_url( 'admin.php?page=wpel-log' )
			)
		);
	}

	/**
	 * Resolves the four Twilio credentials from their wp-config.php
	 * constants (see wpel_shared_credential()) — shared across every site
	 * on one Twilio account, so they aren't shown in Settings.
	 *
	 *   account_sid  the real Account SID (starts with AC) — always goes in
	 *                the URL path, regardless of which credential pair
	 *                authenticates the request.
	 *   sid          Basic Auth username — either that same Account SID +
	 *                auth_token (the master auth token), OR an API Key SID
	 *                (starts with SK) + its Secret. Twilio accepts both pairs
	 *                equally for auth; only the URL path requires the real
	 *                Account SID.
	 *   from_number  the sending number, E.164 format (+1...).
	 *
	 * @return array{account_sid: string, sid: string, auth_token: string, from_number: string}
	 */
	private function get_twilio_credentials() {
		return array(
			'account_sid' => wpel_shared_credential( 'WPEL_TWILIO_ACCOUNT_SID', 'twilio_account_sid' ),
			'sid'         => wpel_shared_credential( 'WPEL_TWILIO_SID', 'twilio_sid' ),
			'auth_token'  => wpel_shared_credential( 'WPEL_TWILIO_AUTH_TOKEN', 'twilio_auth_token' ),
			'from_number' => wpel_shared_credential( 'WPEL_TWILIO_FROM_NUMBER', 'twilio_from_number' ),
		);
	}

	/**
	 * Sends a plain-text SMS via the Twilio Messages API to every configured
	 * recipient number. Unlike ntfy, SMS has no title/priority/click concept,
	 * so callers fold everything (including any deep link) into one body.
	 * Recipients need nothing beyond a phone that can receive texts — no app,
	 * no account, no subscribing to anything.
	 *
	 * @param string $body
	 */
	private function notify_twilio( $body ) {
		$creds = $this->get_twilio_credentials();
		if ( ! $creds['account_sid'] || ! $creds['sid'] || ! $creds['auth_token'] || ! $creds['from_number'] ) {
			return;
		}
		$to_numbers = array_filter( array_map( 'trim', explode( ',', $this->opt( 'twilio_to_numbers', '' ) ) ) );
		if ( ! $to_numbers ) {
			return;
		}

		foreach ( $to_numbers as $to ) {
			wp_remote_post(
				'https://api.twilio.com/2010-04-01/Accounts/' . $creds['account_sid'] . '/Messages.json',
				array(
					'timeout'  => 8,
					'blocking' => false, // fire-and-forget, same as Slack before it
					'headers'  => array(
						'Authorization' => 'Basic ' . base64_encode( $creds['sid'] . ':' . $creds['auth_token'] ),
					),
					'body'     => array(
						'To'   => $to,
						'From' => $creds['from_number'],
						'Body' => mb_substr( $body, 0, 1500 ), // Twilio auto-segments/concatenates up to ~1600 chars
					),
				)
			);
		}
	}

	/**
	 * Blocking Twilio send used only by the Settings page's "Send test SMS"
	 * button. notify_twilio() above is fire-and-forget (blocking => false)
	 * since real alerts fire from request-serving code paths that shouldn't
	 * wait on Twilio; a manual test click can afford to wait a couple of
	 * seconds, and doing so is the only way to hand back Twilio's actual
	 * error message (bad number, auth failure, etc.) instead of just "sent".
	 *
	 * @param string $body
	 * @return array<int, array{to: string, ok: bool, detail: string}> One
	 *         entry per configured number, or a single not-ok entry
	 *         explaining why nothing was attempted (not configured / no
	 *         numbers saved).
	 */
	public function send_test_sms( $body ) {
		$creds = $this->get_twilio_credentials();
		if ( ! $creds['account_sid'] || ! $creds['sid'] || ! $creds['auth_token'] || ! $creds['from_number'] ) {
			return array(
				array(
					'to'     => '',
					'ok'     => false,
					'detail' => 'Twilio isn\'t configured — define WPEL_TWILIO_ACCOUNT_SID, WPEL_TWILIO_SID, WPEL_TWILIO_AUTH_TOKEN and WPEL_TWILIO_FROM_NUMBER in wp-config.php.',
				),
			);
		}

		$to_numbers = array_filter( array_map( 'trim', explode( ',', $this->opt( 'twilio_to_numbers', '' ) ) ) );
		if ( ! $to_numbers ) {
			return array(
				array(
					'to'     => '',
					'ok'     => false,
					'detail' => 'No alert phone numbers are saved yet — add one on the Alerting & Logging tab, save, then try again.',
				),
			);
		}

		$results = array();

		foreach ( $to_numbers as $to ) {
			$response = wp_remote_post(
				'https://api.twilio.com/2010-04-01/Accounts/' . $creds['account_sid'] . '/Messages.json',
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Basic ' . base64_encode( $creds['sid'] . ':' . $creds['auth_token'] ),
					),
					'body'    => array(
						'To'   => $to,
						'From' => $creds['from_number'],
						'Body' => mb_substr( $body, 0, 1500 ),
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$results[] = array(
					'to'     => $to,
					'ok'     => false,
					'detail' => $response->get_error_message(),
				);
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				$results[] = array(
					'to'     => $to,
					'ok'     => true,
					'detail' => 'Sent.',
				);
			} else {
				$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
				$results[] = array(
					'to'     => $to,
					'ok'     => false,
					'detail' => isset( $decoded['message'] ) ? $decoded['message'] : ( 'Twilio returned HTTP ' . $code ),
				);
			}
		}

		return $results;
	}

	/* -------------------------------------------------------------------- */
	/* Unopened-email sweep                                                  */
	/* -------------------------------------------------------------------- */

	/**
	 * Hourly cron (wpel_check_unopened): flags 'delivered' emails that have
	 * sat with open_count = 0 past the configured threshold. Each row is
	 * alerted at most once (unopened_alerted flag), so this is safe to run
	 * as often as we like without spamming the same email repeatedly.
	 */
	public function check_unopened() {
		if ( ! (int) $this->opt( 'alert_unopened', 0 ) ) {
			return;
		}
		$hours = max( 1, (int) $this->opt( 'unopened_hours', 24 ) );

		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, recipient, subject, created_at FROM {$table}
				 WHERE status = 'delivered' AND open_count = 0 AND unopened_alerted = 0 AND created_at <= %s
				 ORDER BY id ASC LIMIT 200",
				$cutoff
			)
		);

		foreach ( $rows as $row ) {
			// Mark first so a slow/failed notify can't cause the next run to re-alert the same row.
			$this->update_row( $row->id, array( 'unopened_alerted' => 1 ), 'unopened_alert' );
			$this->notify_unopened( $row, $hours );
		}
	}

	private function notify_unopened( $row, $hours ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		// Slack — disabled for now, see notify_slack() and the note in handle_failure().
		// $this->notify_slack(
		// 	sprintf(
		// 		"*Email unopened %dh after delivery on %s*\n• To: %s\n• Subject: %s\n• Delivered: %s\n• Log row: #%d",
		// 		$hours,
		// 		$host,
		// 		$row->recipient,
		// 		$row->subject,
		// 		$row->created_at,
		// 		$row->id
		// 	)
		// );

		$this->notify_twilio(
			sprintf(
				"%s - unopened after %dh\nTo: %s\nSubject: %s\nDelivered: %s\n%s",
				$host,
				$hours,
				$row->recipient,
				$row->subject,
				$row->created_at,
				admin_url( 'admin.php?page=wpel-log&status=delivered' )
			)
		);
	}

	/**
	 * Record a failure timestamp; return true if the outage threshold is met
	 * within the configured window (and there were no recent successes).
	 */
	private function record_failure() {
		$window    = (int) $this->opt( 'outage_window', 15 ) * MINUTE_IN_SECONDS;
		$threshold = (int) $this->opt( 'outage_threshold', 5 );
		$now       = time();

		$fails = get_option( self::FAILWIN_OPTION, array() );
		if ( ! is_array( $fails ) ) {
			$fails = array();
		}
		$fails[] = $now;
		// Keep only failures inside the window.
		$fails = array_values( array_filter( $fails, function ( $t ) use ( $now, $window ) {
			return ( $now - (int) $t ) <= $window;
		} ) );
		update_option( self::FAILWIN_OPTION, $fails, false );

		$last_success = (int) get_option( 'wpel_last_success', 0 );
		$success_in_window = $last_success && ( $now - $last_success ) <= $window;

		return ( count( $fails ) >= $threshold ) && ! $success_in_window;
	}

	private function record_success() {
		update_option( 'wpel_last_success', time(), false );
		update_option( self::FAILWIN_OPTION, array(), false ); // reset failure window
		delete_transient( 'wpel_outage_alarm_sent' );
	}

	/* -------------------------------------------------------------------- */
	/* DB helpers                                                             */
	/* -------------------------------------------------------------------- */

	private function insert_row( $fields, $event = '' ) {
		global $wpdb;
		$now  = current_time( 'mysql' );
		$data = wp_parse_args(
			$fields,
			array(
				'created_at' => $now,
				'updated_at' => $now,
				'status'     => 'pending',
				'event_log'  => $event ? wp_json_encode( array( array( 't' => $now, 'e' => $event ) ) ) : null,
			)
		);
		$wpdb->insert( self::table(), $data );
		return (int) $wpdb->insert_id;
	}

	private function update_row( $id, $fields, $event = '' ) {
		global $wpdb;
		$now              = current_time( 'mysql' );
		$fields['updated_at'] = $now;

		if ( $event ) {
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT event_log FROM " . self::table() . " WHERE id = %d", $id ) );
			$log      = $existing ? json_decode( $existing, true ) : array();
			if ( ! is_array( $log ) ) {
				$log = array();
			}
			$log[]              = array( 't' => $now, 'e' => $event );
			$fields['event_log'] = wp_json_encode( $log );
		}

		$wpdb->update( self::table(), $fields, array( 'id' => $id ) );
	}

	/**
	 * Bumps open_count and stamps first/last_opened_at for an 'opened' webhook
	 * event. A raw UPDATE (rather than read-then-write via update_row()) so
	 * concurrent opens for the same row increment atomically instead of racing.
	 */
	private function record_open( $id ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . "
				 SET open_count = open_count + 1,
				     first_opened_at = COALESCE(first_opened_at, %s),
				     last_opened_at = %s,
				     updated_at = %s
				 WHERE id = %d",
				$now,
				$now,
				$now,
				$id
			)
		);
	}

	private function row_exists( $id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . self::table() . " WHERE id = %d", $id ) );
	}

	private function find_by_message_id( $message_id ) {
		global $wpdb;
		$message_id = $this->normalize_message_id( $message_id );
		if ( '' === $message_id ) {
			return 0;
		}
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM " . self::table() . " WHERE mailgun_message_id = %s ORDER BY id DESC LIMIT 1", $message_id )
		);
	}

	private function find_recent_pending( $to, $subject ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . self::table() . "
				 WHERE status = 'pending' AND recipient = %s AND subject = %s
				 ORDER BY id DESC LIMIT 1",
				$to,
				$subject
			)
		);
	}

	/**
	 * Fallback correlation for when send_after didn't fire and the row therefore
	 * has no message id yet: the most recent row for this recipient+subject that
	 * isn't already linked to a Mailgun id, within a short window.
	 */
	private function find_recent_unlinked( $recipient, $subject ) {
		global $wpdb;
		if ( '' === $recipient && '' === $subject ) {
			return 0;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . self::table() . "
				 WHERE ( mailgun_message_id IS NULL OR mailgun_message_id = '' )
				   AND recipient = %s AND subject = %s AND created_at >= %s
				 ORDER BY id DESC LIMIT 1",
				$recipient,
				$subject,
				$cutoff
			)
		);
	}

	private function row_recipient( $id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT recipient FROM " . self::table() . " WHERE id = %d", $id ) );
	}

	private function row_subject( $id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT subject FROM " . self::table() . " WHERE id = %d", $id ) );
	}

	public function prune_logs() {
		global $wpdb;
		$days = (int) $this->opt( 'retention_days', 30 );
		if ( $days <= 0 ) {
			return;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . self::table() . " WHERE created_at < %s", $cutoff ) );
	}
}

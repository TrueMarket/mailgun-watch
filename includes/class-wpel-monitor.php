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

	/**
	 * Mailgun user variable WPEL_Mailer attaches to our alert emails. Mailgun
	 * sends it back in every webhook event for that message, so handle_webhook()
	 * can tell our alerts apart (the SKIP_HEADER itself never reaches Mailgun).
	 */
	const ALERT_VARIABLE = 'wpel_alert';

	/** Subject prefix of our failure alert emails, see handle_failure(). */
	const ALERT_SUBJECT_PREFIX = '[Mailgun Watch] Send failure on ';

	/** Option key holding recent-failure timestamps used for outage detection. */
	const FAILWIN_OPTION = 'wpel_recent_failures';

	/** Option key tracking recent failure alerts by recipient + reason, see claim_failure_group(). */
	const GROUPS_OPTION = 'wpel_alert_groups';

	/** Seconds after a failure alert during which identical failures aren't alerted again. */
	const GROUP_WINDOW = 3600;

	/** Option key tracking this hour's alert texts, see apply_sms_limit(). */
	const SMS_LIMIT_OPTION = 'wpel_sms_limit';

	/** Most alert texts sent per hour (filterable: wpel_sms_max_per_hour). */
	const SMS_MAX_PER_HOUR = 10;

	/**
	 * Row id created in the wp_mail filter, consumed by WPEL_Mailer's pre_wp_mail
	 * hook. The two fire in sequence within a single wp_mail() call ('wp_mail'
	 * filter, then 'pre_wp_mail' filter), so this hands the just-created row to
	 * the mailer that knows the real Mailgun send outcome and message id. When
	 * the mailer declines (SMTP fallback / default transport), it's consumed
	 * later in the same wp_mail() call by on_local_success() / on_local_failure().
	 *
	 * @var int
	 */
	private $last_row_id = 0;

	/**
	 * True while the current wp_mail() call is one of our own alert emails
	 * (SKIP_HEADER), so the local success/failure handlers ignore it — a
	 * failed alert email must never raise another alert.
	 *
	 * @var bool
	 */
	private $last_is_skip = false;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Capture + result hooks. wp_mail_succeeded/wp_mail_failed only fire
		// natively for sends that go through PHPMailer (i.e. when WPEL_Mailer
		// declines a send and it goes out via the SMTP fallback or WordPress's
		// default transport); Mailgun API send results are reported directly by
		// WPEL_Mailer via mark_sent() / mark_failed_and_alert().
		add_filter( 'wp_mail', array( $this, 'capture_outgoing' ), 99 );
		add_action( 'wp_mail_succeeded', array( $this, 'on_local_success' ), 10, 1 );
		add_action( 'wp_mail_failed', array( $this, 'on_local_failure' ), 10, 1 );
		// Last, so it sees the From that WPEL_Mailer::configure_smtp() settled on.
		add_action( 'phpmailer_init', array( $this, 'capture_phpmailer_from' ), PHP_INT_MAX );

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

	/**
	 * The given settings array, or the saved one when null — lets the
	 * settings page ask the same questions of the values being saved and
	 * the ones they replace (see WPEL_Admin::sanitize_settings()).
	 */
	private function settings( $o ) {
		if ( null === $o ) {
			$o = get_option( WPEL_OPTION, array() );
		}
		return is_array( $o ) ? $o : array();
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
		$this->last_row_id  = 0;
		$this->last_is_skip = false;

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
				$this->last_is_skip = true;
				return $args;
			}
		}

		$to = isset( $args['to'] ) ? $args['to'] : '';
		if ( is_array( $to ) ) {
			$to = implode( ', ', $to );
		}

		$source = WPEL_Sources::instance()->current();

		$this->last_row_id = $this->insert_row(
			array(
				'recipient'   => $to,
				'subject'     => isset( $args['subject'] ) ? $args['subject'] : '',
				'headers'     => wp_json_encode( $headers ),
				'body'        => isset( $args['message'] ) ? $args['message'] : '',
				'status'      => 'pending',
				'source'      => $source['source'],
				'source_page' => $source['page'],
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
		$row_id             = (int) $this->last_row_id;
		$this->last_row_id  = 0;
		$this->last_is_skip = false;
		return $row_id;
	}

	/**
	 * Stamps the From address a message actually went out with, which can
	 * differ from the caller's From header when "Force from" is on. A query of
	 * its own, so a site whose table hasn't gained the column yet only loses
	 * this, not the status update alongside it.
	 *
	 * @param int    $row_id
	 * @param string $from "Name <email>" or a bare address.
	 */
	public function record_sender( $row_id, $from ) {
		global $wpdb;
		if ( ! $row_id || '' === (string) $from ) {
			return;
		}
		$wpdb->update( self::table(), array( 'from_address' => (string) $from ), array( 'id' => $row_id ) );
	}

	/**
	 * phpmailer_init callback: records the From of a send going out through
	 * PHPMailer (SMTP fallback or WordPress's default transport). The row id
	 * is only peeked at here; on_local_success / on_local_failure consume it.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public function capture_phpmailer_from( $phpmailer ) {
		if ( $this->last_is_skip || ! $this->last_row_id ) {
			return;
		}
		$this->record_sender(
			$this->last_row_id,
			$phpmailer->FromName ? "{$phpmailer->FromName} <{$phpmailer->From}>" : $phpmailer->From
		);
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
	 * PHPMailer accepted the message: the SMTP fallback (or WordPress's default
	 * transport) handed it off. That's as far as these sends can be followed —
	 * there's no webhook for the shared SMTP domain — so 'sent' is final.
	 *
	 * Unlike Mailgun API acceptance, an SMTP handoff does count as a success
	 * for outage detection: it's the only success signal SMTP sends ever get,
	 * and without it a few scattered failures would look like a total outage.
	 * PHP mail() returning true proves nothing, so the default transport doesn't.
	 *
	 * @param array $mail_data to, subject, message, headers, attachments.
	 */
	public function on_local_success( $mail_data ) {
		$is_skip = $this->last_is_skip;
		$row_id  = $this->consume_last_row_id();
		if ( $is_skip || ! $row_id ) {
			return;
		}

		if ( 'smtp' === WPEL_Mailer::instance()->transport() ) {
			$this->update_row( $row_id, array( 'status' => 'sent' ), 'smtp: accepted' );
			$this->record_success();
		} else {
			$this->update_row( $row_id, array( 'status' => 'sent' ), 'wp_mail: sent' );
		}
	}

	/**
	 * Immediate, local (PHP-level) send failure. Only fires for sends that went
	 * through PHPMailer — i.e. WPEL_Mailer declined to handle this send and it
	 * went out via the SMTP fallback or WordPress's default transport (Mailgun
	 * API failures are reported directly via WPEL_Mailer::send() ->
	 * mark_failed_and_alert(), which never reaches this handler). A strong
	 * signal the site may be unable to send at all.
	 *
	 * @param WP_Error $error
	 */
	public function on_local_failure( $error ) {
		// One of our own alert emails failing must not raise another alert
		// (which would send another alert email, which would fail, ...).
		$is_skip = $this->last_is_skip;
		$row_id  = $this->consume_last_row_id();
		if ( $is_skip ) {
			return;
		}

		$mailer = WPEL_Mailer::instance();
		$smtp   = 'smtp' === $mailer->transport();
		$source = $smtp ? 'SMTP send (' . $mailer->smtp_host() . ')' : 'local wp_mail_failed';

		$data       = $error->get_error_data();
		$to         = '';
		$subject    = '';
		if ( is_array( $data ) ) {
			$to      = isset( $data['to'] ) ? ( is_array( $data['to'] ) ? implode( ', ', $data['to'] ) : $data['to'] ) : '';
			$subject = isset( $data['subject'] ) ? $data['subject'] : '';
		}
		$message = $error->get_error_message();

		// Attach to the row captured for this send, else the most recent matching
		// pending row; otherwise log a new failed row.
		if ( ! $row_id || ! $this->row_exists( $row_id ) ) {
			$row_id = $this->find_recent_pending( $to, $subject );
		}
		if ( $row_id ) {
			$this->update_row(
				$row_id,
				array(
					'status'        => 'failed',
					'error_message' => $message,
				),
				( $smtp ? 'smtp: ' : 'local_failure: ' ) . $message
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
				'source'     => $source,
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

		// The SMTP fallback domain is shared by every site, and the signing key
		// is account-wide, so a webhook registered on that domain would deliver
		// (validly signed) events for every site's SMTP mail here — and the
		// "create a fresh row" fallback below would fill this site's log with
		// them. Acknowledge with a 200 so Mailgun doesn't retry, but drop them.
		$event_domain = $this->event_sending_domain( $event );
		$smtp_domain  = WPEL_Mailer::instance()->smtp_domain();
		$api_domain   = strtolower( trim( (string) $this->opt( 'domain', '' ) ) );
		if ( $event_domain && $event_domain === $smtp_domain && $event_domain !== $api_domain ) {
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => 'smtp fallback domain' ), 200 );
		}

		$recipient  = isset( $event['recipient'] ) ? $event['recipient'] : '';
		$message_id = isset( $event['message']['headers']['message-id'] ) ? $this->normalize_message_id( $event['message']['headers']['message-id'] ) : '';
		$subject    = isset( $event['message']['headers']['subject'] ) ? $event['message']['headers']['subject'] : '';
		$severity   = isset( $event['severity'] ) ? $event['severity'] : '';
		$reason     = isset( $event['delivery-status']['message'] ) ? $event['delivery-status']['message'] : ( isset( $event['reason'] ) ? $event['reason'] : '' );

		// Events for our own alert emails. These are never logged at send time,
		// so a failure here would match no row, get logged as a new one and
		// raise another alert email — which, if the alert address is bouncing,
		// fails too, endlessly. The subject check covers alerts sent before the
		// ALERT_VARIABLE tag existed.
		if ( ! empty( $event['user-variables'][ self::ALERT_VARIABLE ] ) || 0 === strpos( $subject, self::ALERT_SUBJECT_PREFIX ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => 'alert email' ), 200 );
		}

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
			if ( ! empty( $event['message']['headers']['from'] ) ) {
				$this->record_sender( $row_id, $event['message']['headers']['from'] );
			}
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
	 * Sending domain a webhook event belongs to, from the envelope sender
	 * (Mailgun's bounce address on the sending domain), else the From header.
	 * Lowercased; '' when neither is present.
	 */
	private function event_sending_domain( $event ) {
		$address = '';
		if ( ! empty( $event['envelope']['sender'] ) ) {
			$address = (string) $event['envelope']['sender'];
		} elseif ( ! empty( $event['message']['headers']['from'] ) ) {
			$address = (string) $event['message']['headers']['from'];
		}
		$at = strrpos( $address, '@' );
		return false === $at ? '' : strtolower( trim( substr( $address, $at + 1 ), " \t<>\"'" ) );
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

		// A failure just like one alerted on in the last hour (same recipient and
		// reason, e.g. every Wordfence email to an address Mailgun won't deliver
		// to) is only counted, and mentioned in the next alert for it. It still
		// counts toward outage detection above.
		$held = $this->claim_failure_group( $recipient, $reason );
		if ( null === $held ) {
			if ( $outage ) {
				$this->notify_outage_alarm();
			}
			return;
		}
		$repeats = $held ? sprintf( "%d more like this since the last alert.\n", $held ) : '';

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
				"%s - email send failed\nTo: %s\nSubject: %s\nReason: %s\nSource: %s\n%sRepeats in the next hour won't be texted.\n%s",
				$host,
				$recipient,
				$subject,
				$reason,
				$source,
				$repeats,
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
			self::ALERT_SUBJECT_PREFIX . $host,
			sprintf(
				"A message failed to send.\n\nTo: %s\nSubject: %s\nReason: %s\nSource: %s\nLog row: %s\n\n%sFailures with the same recipient and reason over the next hour won't be alerted separately; they're all in the Email Log.\n\nReview: %s",
				$recipient,
				$subject,
				$reason,
				$source,
				$row_id ? '#' . $row_id : 'n/a',
				$repeats ? $repeats . "\n" : '',
				admin_url( 'admin.php?page=wpel-log' )
			)
		);
	}

	/**
	 * Groups repeat failures by recipient + reason. The first one alerts as
	 * normal; identical ones within GROUP_WINDOW after it are only counted.
	 *
	 * @return int|null Null to stay quiet (already alerted within the window);
	 *                  otherwise how many identical failures were held back
	 *                  since this group's previous alert, for the new alert to mention.
	 */
	private function claim_failure_group( $recipient, $reason ) {
		$key    = md5( strtolower( trim( (string) $recipient ) ) . '|' . trim( (string) $reason ) );
		$now    = time();
		$groups = get_option( self::GROUPS_OPTION, array() );
		if ( ! is_array( $groups ) ) {
			$groups = array();
		}

		$held = 0;
		if ( isset( $groups[ $key ] ) ) {
			if ( $now - (int) $groups[ $key ]['t'] < self::GROUP_WINDOW ) {
				$groups[ $key ]['held'] = (int) $groups[ $key ]['held'] + 1;
				update_option( self::GROUPS_OPTION, $groups, false );
				return null;
			}
			$held = (int) $groups[ $key ]['held'];
		}

		// Forget groups that have been quiet for a day, so the option stays small.
		$groups = array_filter(
			$groups,
			function ( $g ) use ( $now ) {
				return is_array( $g ) && $now - (int) $g['t'] < DAY_IN_SECONDS;
			}
		);
		$groups[ $key ] = array( 't' => $now, 'held' => 0 );
		update_option( self::GROUPS_OPTION, $groups, false );

		return $held;
	}

	private function notify_email( $subject, $body ) {
		// A blank field (saved or never set) falls back to the admin email,
		// matching where "Send test email" goes.
		$to = $this->opt( 'alert_email', '' );
		if ( ! $to ) {
			$to = get_option( 'admin_email' );
		}
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
			),
			true // already throttled above, and it's the one text that must get through
		);
	}

	/**
	 * Whether SMS alerts are switched on in Settings. Installs saved before
	 * the toggle existed have no sms_enabled key; they count as enabled if
	 * they already had alert phone numbers, so upgrading doesn't silently
	 * stop their texts.
	 */
	public function sms_enabled( $o = null ) {
		$o = $this->settings( $o );
		if ( isset( $o['sms_enabled'] ) ) {
			return ! empty( $o['sms_enabled'] );
		}
		return ! empty( $o['twilio_to_numbers'] );
	}

	/**
	 * Resolves the four Twilio credentials, saved in Settings (Alerting &
	 * Logging tab, under "SMS alerts"). A blank field falls back to its
	 * WPEL_TWILIO_* constant, for sites set up while these lived only in
	 * wp-config.php.
	 *
	 *   account_sid  the real Account SID (starts with AC) — always goes in
	 *                the URL path, regardless of which credential pair
	 *                authenticates the request.
	 *   sid          Basic Auth username — either that same Account SID +
	 *                auth_token (the master auth token), OR an API Key SID
	 *                (starts with SK) + its Secret. Twilio accepts both pairs
	 *                equally for auth; only the URL path requires the real
	 *                Account SID. Blank = the Account SID.
	 *   from_number  the sending number, E.164 format (+1...).
	 *
	 * @return array{account_sid: string, sid: string, auth_token: string, from_number: string}
	 */
	public function get_twilio_credentials() {
		$creds = array(
			'account_sid' => $this->twilio_setting( 'twilio_account_sid', 'WPEL_TWILIO_ACCOUNT_SID' ),
			'sid'         => $this->twilio_setting( 'twilio_sid', 'WPEL_TWILIO_SID' ),
			'auth_token'  => $this->twilio_setting( 'twilio_auth_token', 'WPEL_TWILIO_AUTH_TOKEN' ),
			'from_number' => $this->twilio_setting( 'twilio_from_number', 'WPEL_TWILIO_FROM_NUMBER' ),
		);
		if ( '' === $creds['sid'] ) {
			$creds['sid'] = $creds['account_sid'];
		}
		return $creds;
	}

	private function twilio_setting( $key, $constant ) {
		$value = trim( (string) $this->opt( $key, '' ) );
		if ( '' === $value && defined( $constant ) ) {
			$value = trim( (string) constant( $constant ) );
		}
		return $value;
	}

	/**
	 * Sends a plain-text SMS via the Twilio Messages API to every configured
	 * recipient number. Unlike ntfy, SMS has no title/priority/click concept,
	 * so callers fold everything (including any deep link) into one body.
	 * Recipients need nothing beyond a phone that can receive texts — no app,
	 * no account, no subscribing to anything.
	 *
	 * @param string $body
	 * @param bool   $bypass_limit Send even past the hourly limit (still counted).
	 */
	private function notify_twilio( $body, $bypass_limit = false ) {
		if ( ! $this->sms_enabled() ) {
			return;
		}
		$creds = $this->get_twilio_credentials();
		if ( ! $creds['account_sid'] || ! $creds['sid'] || ! $creds['auth_token'] || ! $creds['from_number'] ) {
			return;
		}
		$to_numbers = array_filter( array_map( 'trim', explode( ',', $this->opt( 'twilio_to_numbers', '' ) ) ) );
		if ( ! $to_numbers ) {
			return;
		}

		// Trimmed before the limit notes are added, so they can't be cut off.
		$body = $this->apply_sms_limit( mb_substr( $body, 0, 1300 ), $bypass_limit );
		if ( null === $body ) {
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
						'Body' => $body, // under ~1600 chars (trimmed above), which Twilio auto-segments/concatenates
					),
				)
			);
		}
	}

	/**
	 * Hard cap on alert texts per hour, whatever is triggering them — the
	 * backstop if some new failure pattern slips past the grouping in
	 * handle_failure(). The text that reaches the cap says so; the first one
	 * after the hour is up says how many were held back. Counted once per
	 * alert, however many numbers it goes to.
	 *
	 * @param string $body
	 * @param bool   $bypass Send regardless of the cap (it still counts toward it).
	 * @return string|null The body to send, with any notes added, or null to stay quiet.
	 */
	private function apply_sms_limit( $body, $bypass ) {
		$max = (int) apply_filters( 'wpel_sms_max_per_hour', self::SMS_MAX_PER_HOUR );
		if ( $max <= 0 ) {
			return $body; // limit switched off via the filter
		}

		$now   = time();
		$state = get_option( self::SMS_LIMIT_OPTION, array() );
		if ( ! is_array( $state ) || empty( $state['start'] ) || $now - (int) $state['start'] >= HOUR_IN_SECONDS ) {
			if ( is_array( $state ) && ! empty( $state['held'] ) ) {
				$body = sprintf(
					"(%d earlier %s not texted: SMS limit reached. See the Email Log.)\n",
					(int) $state['held'],
					1 === (int) $state['held'] ? 'alert was' : 'alerts were'
				) . $body;
			}
			$state = array( 'start' => $now, 'sent' => 0, 'held' => 0 );
		}

		if ( ! $bypass && $state['sent'] >= $max ) {
			$state['held']++;
			update_option( self::SMS_LIMIT_OPTION, $state, false );
			return null;
		}

		$state['sent']++;
		if ( ! $bypass && $state['sent'] === $max ) {
			$body .= sprintf(
				"\nSMS limit reached (%d an hour): no more alert texts until %s.",
				$max,
				wp_date( get_option( 'time_format' ), $state['start'] + HOUR_IN_SECONDS )
			);
		}
		update_option( self::SMS_LIMIT_OPTION, $state, false );

		return $body;
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
		if ( ! $this->sms_enabled() ) {
			return array(
				array(
					'to'     => '',
					'ok'     => false,
					'detail' => 'SMS alerts are turned off — tick "Enable SMS alerts" on the General tab, save, then try again.',
				),
			);
		}

		$creds = $this->get_twilio_credentials();
		if ( ! $creds['account_sid'] || ! $creds['sid'] || ! $creds['auth_token'] || ! $creds['from_number'] ) {
			return array(
				array(
					'to'     => '',
					'ok'     => false,
					'detail' => 'Twilio isn\'t configured — fill in the Account SID, auth token and Twilio phone number on the Alerting & Logging tab, save, then try again.',
				),
			);
		}

		$to_numbers = array_filter( array_map( 'trim', explode( ',', $this->opt( 'twilio_to_numbers', '' ) ) ) );
		if ( ! $to_numbers ) {
			return array(
				array(
					'to'     => '',
					'ok'     => false,
					'detail' => 'No alert phone numbers are saved yet — add one on the General tab, save, then try again.',
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
	 * alerted at most once per stage (unopened_alerted), and each run sends
	 * a single text per stage however many rows it flags, so a busy site
	 * can't turn one run into a flood of texts.
	 *
	 * Only rows from the ticked sources (WPEL_Sources::watched()) count. The
	 * filter is applied here rather than at send time, so a change to the
	 * list also covers emails still waiting out the threshold. Rows that
	 * don't match are left unflagged.
	 *
	 * With the secondary notice on, a row still unopened past
	 * unopened_second_hours (counted from sending, like unopened_hours) is
	 * texted once more. unopened_alerted counts the alerts sent: 0 none, 1
	 * the first, 2 the secondary.
	 *
	 * Runs whenever open tracking is on and unopened_channel() has somewhere
	 * to send to (see unopened_alerts_enabled()): there's no separate
	 * switch, since without open tracking every delivered email looks
	 * unopened.
	 */
	public function check_unopened() {
		if ( ! $this->unopened_alerts_enabled() ) {
			return;
		}
		$scope = $this->unopened_scope();
		if ( '' === $scope ) {
			return; // no sources ticked
		}

		// Secondary first, so a row flagged by the first alert below waits
		// at least until the next run for its second.
		if ( $this->opt( 'unopened_second', 0 ) ) {
			$hours = $this->unopened_second_hours();
			$rows  = $this->flag_unopened( 1, $hours, $scope );
			if ( $rows ) {
				$this->notify_unopened( $rows, $hours, true );
			}
		}

		$hours = max( 1, (int) $this->opt( 'unopened_hours', 24 ) );
		$rows  = $this->flag_unopened( 0, $hours, $scope );
		if ( $rows ) {
			$this->notify_unopened( $rows, $hours );
		}
	}

	/**
	 * Hours after sending the secondary notice goes out: always later than
	 * the first alert.
	 */
	public function unopened_second_hours() {
		$first = max( 1, (int) $this->opt( 'unopened_hours', 24 ) );
		return max( $first + 1, (int) $this->opt( 'unopened_second_hours', 48 ) );
	}

	/**
	 * The ticked sources (WPEL_Sources::watched()) as a prepared SQL
	 * condition, or '' when none are ticked.
	 */
	private function unopened_scope() {
		global $wpdb;
		$watch = WPEL_Sources::watched();
		if ( ! $watch ) {
			return '';
		}
		$clauses = array();
		foreach ( $watch as $source => $page ) {
			$clauses[] = $page
				? $wpdb->prepare( '( source = %s AND source_page = %d )', $source, $page )
				: $wpdb->prepare( 'source = %s', $source );
		}
		return ' AND ( ' . implode( ' OR ', $clauses ) . ' )';
	}

	/**
	 * Finds the delivered, unopened rows at alert stage $stage that were sent
	 * at least $hours ago, moves them to the next stage and returns them.
	 *
	 * @param int    $stage unopened_alerted value to look for.
	 * @param int    $hours
	 * @param string $scope From unopened_scope().
	 * @return object[]
	 */
	private function flag_unopened( $stage, $hours, $scope ) {
		global $wpdb;
		$table  = self::table();
		$cutoff = $this->local_mysql_time( time() - $hours * HOUR_IN_SECONDS );

		// $scope is already prepared, so it's appended rather than run through prepare() again.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, recipient, subject, created_at, source, source_page FROM {$table}
				 WHERE status = 'delivered' AND open_count = 0 AND unopened_alerted = %d AND created_at <= %s",
				$stage,
				$cutoff
			) . $scope . ' ORDER BY id ASC LIMIT 200'
		);

		foreach ( (array) $rows as $row ) {
			// Mark first so a slow/failed notify can't cause the next run to re-alert the same row.
			$this->update_row( $row->id, array( 'unopened_alerted' => $stage + 1 ), $stage ? 'unopened_alert_second' : 'unopened_alert' );
		}
		return (array) $rows;
	}

	/**
	 * Whether check_unopened() runs: open tracking on, plus a channel that
	 * can deliver. Email always can (notify_email() falls back to the admin
	 * email); texts need SMS alerts on. With "both" and SMS off, only the
	 * email goes out.
	 *
	 * @param array|null $o Settings to check; null for the saved ones.
	 */
	public function unopened_alerts_enabled( $o = null ) {
		$o = $this->settings( $o );
		if ( empty( $o['track_opens'] ) ) {
			return false;
		}
		return 'sms' !== $this->unopened_channel( $o ) || $this->sms_enabled( $o );
	}

	/**
	 * How unopened alerts are sent: 'sms', 'email' or 'both'. Defaults to
	 * 'sms', which is all there was before the choice existed.
	 *
	 * @param array|null $o Settings to check; null for the saved ones.
	 */
	public function unopened_channel( $o = null ) {
		$o       = $this->settings( $o );
		$channel = isset( $o['unopened_channel'] ) ? $o['unopened_channel'] : 'sms';
		return in_array( $channel, array( 'sms', 'email', 'both' ), true ) ? $channel : 'sms';
	}

	/**
	 * Flags every delivered, unopened email already in the log as alerted,
	 * so the next check_unopened() run only covers emails sent from now on.
	 * Used when unopened alerts start running (older emails were either sent
	 * without the pixel, so they'd all look unopened, or went unchecked
	 * while alerts were off) and on the upgrade that turned unopened alerts
	 * on for every site with SMS + open tracking. Skips the secondary notice
	 * for them too.
	 */
	public function skip_unopened_backlog() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "UPDATE {$table} SET unopened_alerted = 2 WHERE status = 'delivered' AND open_count = 0 AND unopened_alerted < 2" );
	}

	/**
	 * Skips the secondary notice for emails that already had their first
	 * alert and are past the secondary threshold, so switching the notice
	 * on doesn't text about every older unopened email at once. Emails still
	 * short of the threshold get theirs as usual.
	 *
	 * @param int $hours The secondary threshold being saved.
	 */
	public function skip_second_unopened_backlog( $hours ) {
		global $wpdb;
		$table  = self::table();
		$cutoff = $this->local_mysql_time( time() - $hours * HOUR_IN_SECONDS );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET unopened_alerted = 2 WHERE status = 'delivered' AND open_count = 0 AND unopened_alerted = 1 AND created_at <= %s",
				$cutoff
			)
		);
	}

	/**
	 * One alert for everything a check_unopened() pass flagged: the full
	 * details for a single email, otherwise a short list. Sent as a text,
	 * an email or both, per unopened_channel().
	 *
	 * @param object[] $rows
	 * @param int      $hours
	 * @param bool     $second Whether these are secondary notices.
	 */
	private function notify_unopened( $rows, $hours, $second = false ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$link = admin_url( 'admin.php?page=wpel-log&status=delivered' );
		$tag  = $second ? ' (2nd notice)' : '';

		if ( 1 === count( $rows ) ) {
			$row  = $rows[0];
			$from = '';
			if ( $row->source ) {
				$from = 'From: ' . WPEL_Sources::instance()->label( $row->source );
				if ( $row->source_page ) {
					$from .= ' on ' . WPEL_Sources::page_title( $row->source_page );
				}
				$from .= "\n";
			}
			$text = sprintf(
				"%s - %sunopened after %dh%s\nTo: %s\nSubject: %s\n%sDelivered: %s\n%s",
				$host,
				$second ? 'still ' : '',
				$hours,
				$tag,
				$row->recipient,
				$row->subject,
				$from,
				$row->created_at,
				$link
			);
		} else {
			$shown = 5;
			$text  = sprintf( "%s - %d emails %sunopened after %dh%s\n", $host, count( $rows ), $second ? 'still ' : '', $hours, $tag );
			foreach ( array_slice( $rows, 0, $shown ) as $row ) {
				$text .= sprintf( "- %s: %s\n", $row->recipient, $row->subject );
			}
			if ( count( $rows ) > $shown ) {
				$text .= sprintf( "+%d more\n", count( $rows ) - $shown );
			}
			$text .= $link;
		}

		// Slack — disabled for now, see notify_slack() and the note in handle_failure().
		// $this->notify_slack( $text );

		$channel = $this->unopened_channel();
		if ( 'email' !== $channel ) {
			$this->notify_twilio( $text );
		}
		if ( 'sms' !== $channel ) {
			$this->notify_email(
				sprintf(
					'[Mailgun Watch] %s%s on %s',
					1 === count( $rows ) ? 'Unopened email' : count( $rows ) . ' unopened emails',
					$tag,
					$host
				),
				$text
			);
		}
	}

	/**
	 * A Unix timestamp in the same form and timezone as the log's created_at
	 * column, which is stored in site-local time (current_time( 'mysql' )).
	 * Cutoffs compared against created_at must use this, not gmdate(), or
	 * they're off by the site's UTC offset.
	 */
	private function local_mysql_time( $timestamp ) {
		return wp_date( 'Y-m-d H:i:s', $timestamp );
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

	/** Appends an entry to a row's timeline without changing anything else. */
	public function log_event( $id, $event ) {
		$this->update_row( $id, array(), $event );
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
		$cutoff = $this->local_mysql_time( time() - 30 * MINUTE_IN_SECONDS );
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
		$cutoff = $this->local_mysql_time( time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM " . self::table() . " WHERE created_at < %s", $cutoff ) );
	}
}

<?php
/**
 * Sends outgoing mail directly through the Mailgun HTTP API, replacing
 * WordPress's default PHPMailer/SMTP transport entirely (no SMTP plugin
 * required). Hooks WordPress core's 'pre_wp_mail' filter (5.7+), which lets
 * a single non-null return value short-circuit wp_mail() completely.
 *
 * Correlates with WPEL_Mailgun_Monitor: capture_outgoing() (on the 'wp_mail'
 * filter) always runs first within a single wp_mail() call and logs a
 * pending row; this class consumes that row id and updates it with the real
 * send outcome once Mailgun's API responds.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPEL_Mailer {

	/** @var WPEL_Mailer */
	private static $instance;

	/** Mailgun US API base. */
	const API_BASE = 'https://api.mailgun.net/v3';

	private $enabled;
	private $api_key;
	private $domain;
	private $from_email;
	private $from_name;
	private $force_from;
	private $track_opens;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$o = get_option( WPEL_OPTION, array() );

		// Missing key (e.g. an install that activated before this option existed)
		// defaults to enabled — the toggle is opt-out, not opt-in.
		$this->enabled    = ! isset( $o['sending_enabled'] ) || ! empty( $o['sending_enabled'] );
		$this->api_key    = isset( $o['api_key'] ) ? trim( $o['api_key'] ) : '';
		$this->domain     = isset( $o['domain'] ) ? trim( $o['domain'] ) : '';
		$this->from_email = isset( $o['from_email'] ) && $o['from_email'] ? $o['from_email'] : get_option( 'admin_email' );
		$this->from_name  = isset( $o['from_name'] ) && $o['from_name'] ? $o['from_name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$this->force_from = ! empty( $o['force_from'] );
		$this->track_opens = ! empty( $o['track_opens'] );

		add_filter( 'pre_wp_mail', array( $this, 'send' ), 10, 2 );
	}

	/**
	 * pre_wp_mail callback. Returning null lets wp_mail() proceed with
	 * WordPress's normal transport (used when Mailgun isn't configured or
	 * sending is switched off); returning true/false short-circuits wp_mail()
	 * with that value and skips PHPMailer entirely.
	 *
	 * @param null|bool $null Always null coming in.
	 * @param array     $atts to, subject, message, headers, attachments.
	 * @return null|bool
	 */
	public function send( $null, $atts ) {
		$monitor = WPEL_Mailgun_Monitor::instance();
		$row_id  = $monitor->consume_last_row_id();

		if ( ! $this->enabled || ! $this->api_key || ! $this->domain ) {
			return null; // not configured / disabled — fall through to WP's default transport
		}

		$to          = isset( $atts['to'] ) ? $atts['to'] : '';
		$subject     = isset( $atts['subject'] ) ? $atts['subject'] : '';
		$message     = isset( $atts['message'] ) ? $atts['message'] : '';
		$headers     = isset( $atts['headers'] ) ? $atts['headers'] : array();
		$attachments = isset( $atts['attachments'] ) ? $atts['attachments'] : array();

		$result = $this->send_via_api( $to, $subject, $message, $headers, $attachments );

		if ( ! $row_id ) {
			return $result['ok']; // our own alert email (SKIP_HEADER) — no logging/alerting
		}

		if ( $result['ok'] ) {
			$monitor->mark_sent( $row_id, $result['message_id'] );
		} else {
			$recipient = is_array( $to ) ? implode( ', ', $to ) : (string) $to;
			$monitor->mark_failed_and_alert( $row_id, $recipient, $subject, $result['error'] );
		}

		return $result['ok'];
	}

	/**
	 * Builds and sends the Mailgun API request.
	 *
	 * @return array { ok: bool, message_id: string, error: string }
	 */
	private function send_via_api( $to, $subject, $message, $headers, $attachments ) {
		$parsed = $this->parse_headers( $headers );

		$from_email = ( $this->force_from || empty( $parsed['from_email'] ) ) ? $this->from_email : $parsed['from_email'];
		$from_name  = ( $this->force_from || empty( $parsed['from_name'] ) ) ? $this->from_name : $parsed['from_name'];
		$from       = $from_name ? "{$from_name} <{$from_email}>" : $from_email;

		$fields = array(
			'from'    => $from,
			'to'      => is_array( $to ) ? implode( ',', $to ) : (string) $to,
			'subject' => (string) $subject,
		);

		if ( $parsed['is_html'] ) {
			$fields['html'] = (string) $message;
		} else {
			$fields['text'] = (string) $message;
		}

		if ( $parsed['cc'] ) {
			$fields['cc'] = $parsed['cc'];
		}
		if ( $parsed['bcc'] ) {
			$fields['bcc'] = $parsed['bcc'];
		}
		if ( $parsed['reply_to'] ) {
			$fields['h:Reply-To'] = $parsed['reply_to'];
		}
		if ( $this->track_opens ) {
			// Mailgun only embeds the pixel in the HTML part; harmless to send on
			// plain-text sends too since there's nothing for it to inject into.
			$fields['o:tracking-opens'] = 'yes';
		}

		$endpoint = self::API_BASE . '/' . rawurlencode( $this->domain ) . '/messages';
		$auth     = 'Basic ' . base64_encode( 'api:' . $this->api_key );
		$files    = $this->normalize_attachments( $attachments );

		if ( $files ) {
			list( $boundary, $body ) = $this->build_multipart_body( $fields, $files );
			$args = array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => $auth,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			);
		} else {
			$args = array(
				'timeout' => 30,
				'headers' => array( 'Authorization' => $auth ),
				'body'    => $fields,
			);
		}

		$response = wp_remote_post( $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message_id' => '', 'error' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			$id = ( is_array( $json ) && ! empty( $json['id'] ) ) ? (string) $json['id'] : '';
			return array( 'ok' => true, 'message_id' => $id, 'error' => '' );
		}

		$error = ( is_array( $json ) && ! empty( $json['message'] ) )
			? (string) $json['message']
			: 'Mailgun API error (HTTP ' . $code . ')';

		return array( 'ok' => false, 'message_id' => '', 'error' => $error );
	}

	/**
	 * Extracts the bits wp_mail()'s headers arg can carry that Mailgun's API
	 * needs as separate fields. Mirrors (a simplified version of) how
	 * WordPress core itself parses this same headers value.
	 */
	private function parse_headers( $headers ) {
		$out = array(
			'is_html'    => ( 'text/html' === apply_filters( 'wp_mail_content_type', 'text/plain' ) ),
			'from_name'  => '',
			'from_email' => '',
			'reply_to'   => '',
			'cc'         => '',
			'bcc'        => '',
		);

		if ( empty( $headers ) ) {
			return $out;
		}

		if ( ! is_array( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
		}

		foreach ( $headers as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $name, $value ) = explode( ':', $line, 2 );
			$name  = strtolower( trim( $name ) );
			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}

			switch ( $name ) {
				case 'content-type':
					$out['is_html'] = ( false !== stripos( $value, 'text/html' ) );
					break;
				case 'from':
					if ( preg_match( '/^(.*)<(.+)>$/', $value, $m ) ) {
						$out['from_name']  = trim( $m[1], " \t\"'" );
						$out['from_email'] = trim( $m[2] );
					} else {
						$out['from_email'] = $value;
					}
					break;
				case 'reply-to':
					$out['reply_to'] = $value;
					break;
				case 'cc':
					$out['cc'] = $out['cc'] ? $out['cc'] . ',' . $value : $value;
					break;
				case 'bcc':
					$out['bcc'] = $out['bcc'] ? $out['bcc'] . ',' . $value : $value;
					break;
			}
		}

		return $out;
	}

	/**
	 * Normalizes wp_mail()'s $attachments (string, newline-delimited string,
	 * or array of file paths) into a flat list of readable files.
	 */
	private function normalize_attachments( $attachments ) {
		if ( empty( $attachments ) ) {
			return array();
		}
		if ( is_string( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", $attachments ) );
		}

		$files = array();
		foreach ( (array) $attachments as $key => $path ) {
			$path = trim( (string) $path );
			if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$files[] = array(
				'path' => $path,
				'name' => is_string( $key ) ? $key : basename( $path ),
			);
		}
		return $files;
	}

	/** Hand-builds a multipart/form-data body — WP's HTTP API has no helper for file uploads. */
	private function build_multipart_body( $fields, $files ) {
		$boundary = 'wpel-' . wp_generate_password( 24, false, false );
		$body     = '';

		foreach ( $fields as $name => $value ) {
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n";
			$body .= $value . "\r\n";
		}

		foreach ( $files as $file ) {
			$content = file_get_contents( $file['path'] );
			if ( false === $content ) {
				continue;
			}
			$filename = str_replace( '"', '\\"', $file['name'] );
			$body    .= "--{$boundary}\r\n";
			$body    .= "Content-Disposition: form-data; name=\"attachment\"; filename=\"{$filename}\"\r\n";
			$body    .= "Content-Type: application/octet-stream\r\n\r\n";
			$body    .= $content . "\r\n";
		}

		$body .= "--{$boundary}--\r\n";

		return array( $boundary, $body );
	}
}

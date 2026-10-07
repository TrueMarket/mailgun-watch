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
 *
 * SMTP FALLBACK: when this site has no Mailgun API key/domain (or API
 * sending is switched off), send() declines and wp_mail() carries on with
 * core's PHPMailer, which configure_smtp() points at Mailgun's SMTP server
 * using the shared credentials in the Sending tab's SMTP fallback section.
 * Those sends get no delivery/open tracking (the SMTP domain is shared, and
 * webhooks are per domain); the monitor marks them sent/failed from core's
 * wp_mail_succeeded/wp_mail_failed instead. An API send that fails is NOT
 * retried over SMTP: a timeout doesn't prove Mailgun rejected the message,
 * so a retry could send it twice, and a broken API setup should stay visible.
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
	private $force_from_name;
	private $force_from_email;
	private $track_opens;

	private $smtp_host;
	private $smtp_port;
	private $smtp_encryption;
	private $smtp_username;
	private $smtp_password;

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
		$this->force_from_name  = wpel_force_from( $o, 'name' );
		$this->force_from_email = wpel_force_from( $o, 'email' );
		$this->track_opens      = ! empty( $o['track_opens'] );

		$this->smtp_host       = ! empty( $o['smtp_host'] ) ? trim( $o['smtp_host'] ) : 'smtp.mailgun.org';
		$this->smtp_port       = ! empty( $o['smtp_port'] ) ? (int) $o['smtp_port'] : 587;
		$this->smtp_encryption = ! empty( $o['smtp_encryption'] ) ? $o['smtp_encryption'] : 'tls';
		$this->smtp_username   = isset( $o['smtp_username'] ) ? trim( $o['smtp_username'] ) : '';
		$this->smtp_password   = isset( $o['smtp_password'] ) ? (string) $o['smtp_password'] : '';

		// One From for both the API and the SMTP fallback. Settings are usually
		// set once on the boilerplate and cloned with its database, so a blank
		// name falls back to *this* site's Site Title at send time, and a blank
		// email to the SMTP login (an address on the SMTP domain) while the site
		// is on the fallback.
		$this->from_name = isset( $o['from_name'] ) && $o['from_name'] ? $o['from_name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( isset( $o['from_email'] ) && $o['from_email'] ) {
			$this->from_email = $o['from_email'];
		} elseif ( 'smtp' === $this->transport() ) {
			$this->from_email = $this->smtp_username;
		} else {
			$this->from_email = get_option( 'admin_email' );
		}

		add_filter( 'pre_wp_mail', array( $this, 'send' ), 10, 2 );
		add_action( 'phpmailer_init', array( $this, 'configure_smtp' ) );
	}

	/**
	 * Which transport wp_mail() will use right now:
	 *   'api'     this site's own Mailgun domain + API key (full tracking)
	 *   'smtp'    the shared Mailgun SMTP fallback (sent/failed only)
	 *   'default' neither is set up — core's own transport, usually PHP mail()
	 *
	 * @return string
	 */
	public function transport() {
		if ( $this->enabled && $this->api_key && $this->domain ) {
			return 'api';
		}
		if ( $this->smtp_username && $this->smtp_password ) {
			return 'smtp';
		}
		return 'default';
	}

	/** Domain of the SMTP fallback's login (e.g. mg.truemarket.io), lowercased; '' if unset. */
	public function smtp_domain() {
		$at = strrpos( $this->smtp_username, '@' );
		return false === $at ? '' : strtolower( substr( $this->smtp_username, $at + 1 ) );
	}

	/** SMTP host, for admin notices and alert messages. */
	public function smtp_host() {
		return $this->smtp_host;
	}

	/**
	 * phpmailer_init callback: points core's PHPMailer at Mailgun's SMTP server
	 * whenever the API isn't handling the send. Picks the From exactly as the
	 * API path does (see send_via_api()); when "Force from email" overrides a
	 * From a plugin/theme set, that address is kept reachable as the Reply-To.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public function configure_smtp( $phpmailer ) {
		if ( 'smtp' !== $this->transport() ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host     = $this->smtp_host;
		$phpmailer->Port     = $this->smtp_port;
		$phpmailer->SMTPAuth = true;
		$phpmailer->Username = $this->smtp_username;
		$phpmailer->Password = $this->smtp_password;
		$phpmailer->Timeout  = 20; // PHPMailer's default is 300s, far too long to stall a page load

		if ( 'none' === $this->smtp_encryption ) {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		} else {
			$phpmailer->SMTPSecure = $this->smtp_encryption; // 'tls' (STARTTLS, 587/2525) or 'ssl' (465)
		}

		// Core has already resolved From: the caller's From header, or its own
		// wordpress@<host> default when nobody set one.
		$original_from = $phpmailer->From;
		$original_name = $phpmailer->FromName;
		$caller_set    = $original_from && strtolower( $original_from ) !== strtolower( $this->core_default_from() );

		if ( $this->force_from_name || ! $caller_set ) {
			$phpmailer->FromName = $this->from_name;
		}

		if ( $this->force_from_email || ! $caller_set ) {
			$phpmailer->From   = $this->from_email;
			$phpmailer->Sender = $this->from_email;

			if ( $caller_set
				&& strtolower( $original_from ) !== strtolower( $this->from_email )
				&& ! $phpmailer->getReplyToAddresses()
			) {
				$phpmailer->addReplyTo( $original_from, $original_name );
			}
		}

		// Shows up in Mailgun's logs as a user variable, so mail from the many
		// sites sharing this domain can be told apart.
		$phpmailer->addCustomHeader( 'X-Mailgun-Variables', wp_json_encode( array( 'wpel_site' => wp_parse_url( home_url(), PHP_URL_HOST ) ) ) );
	}

	/** wordpress@<site host>, the From address core falls back to when nobody sets one (mirrors wp_mail()). */
	private function core_default_from() {
		$host = strtolower( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		return 'wordpress@' . $host;
	}

	/**
	 * pre_wp_mail callback. Returning null lets wp_mail() proceed with
	 * PHPMailer (SMTP fallback, or WordPress's default transport when that
	 * isn't set up either); returning true/false short-circuits wp_mail()
	 * with that value and skips PHPMailer entirely.
	 *
	 * @param null|bool $null Always null coming in.
	 * @param array     $atts to, subject, message, headers, attachments.
	 * @return null|bool
	 */
	public function send( $null, $atts ) {
		if ( 'api' !== $this->transport() ) {
			// Leave the pending row for the monitor's wp_mail_succeeded /
			// wp_mail_failed handlers, which see how PHPMailer's send went.
			return null;
		}

		$monitor = WPEL_Mailgun_Monitor::instance();
		$row_id  = $monitor->consume_last_row_id();

		$to          = isset( $atts['to'] ) ? $atts['to'] : '';
		$subject     = isset( $atts['subject'] ) ? $atts['subject'] : '';
		$message     = isset( $atts['message'] ) ? $atts['message'] : '';
		$headers     = isset( $atts['headers'] ) ? $atts['headers'] : array();
		$attachments = isset( $atts['attachments'] ) ? $atts['attachments'] : array();

		$result = $this->send_via_api( $to, $subject, $message, $headers, $attachments );

		if ( ! $row_id ) {
			return $result['ok']; // our own alert email (SKIP_HEADER) — no logging/alerting
		}

		$monitor->record_sender( $row_id, $result['from'] );
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
	 * @return array { ok: bool, message_id: string, error: string, from: string }
	 */
	private function send_via_api( $to, $subject, $message, $headers, $attachments ) {
		$parsed = $this->parse_headers( $headers );

		$from_email = ( $this->force_from_email || empty( $parsed['from_email'] ) ) ? $this->from_email : $parsed['from_email'];
		$from_name  = ( $this->force_from_name || empty( $parsed['from_name'] ) ) ? $this->from_name : $parsed['from_name'];
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
		} elseif ( $this->force_from_email
			&& $parsed['from_email']
			&& strtolower( $parsed['from_email'] ) !== strtolower( $this->from_email )
			&& strtolower( $parsed['from_email'] ) !== strtolower( $this->core_default_from() )
		) {
			// Same as configure_smtp(): a From a plugin/theme set that "Force
			// from email" overrode stays reachable as the Reply-To.
			$fields['h:Reply-To'] = $parsed['from_name']
				? '"' . addcslashes( $parsed['from_name'], '"\\' ) . '" <' . $parsed['from_email'] . '>'
				: $parsed['from_email'];
		}
		if ( $this->track_opens ) {
			// Mailgun only embeds the pixel in the HTML part; harmless to send on
			// plain-text sends too since there's nothing for it to inject into.
			$fields['o:tracking-opens'] = 'yes';
		}
		if ( $parsed['is_alert'] ) {
			// Our own alert email: Mailgun echoes this back in every webhook
			// event for it ('user-variables'), so handle_webhook() can drop
			// them. Without it, a bouncing alert email would be logged as a new
			// failure and raise another alert email, which bounces, and so on.
			$fields[ 'v:' . WPEL_Mailgun_Monitor::ALERT_VARIABLE ] = '1';
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
			return array( 'ok' => false, 'message_id' => '', 'error' => $response->get_error_message(), 'from' => $from );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			$id = ( is_array( $json ) && ! empty( $json['id'] ) ) ? (string) $json['id'] : '';
			return array( 'ok' => true, 'message_id' => $id, 'error' => '', 'from' => $from );
		}

		$error = ( is_array( $json ) && ! empty( $json['message'] ) )
			? (string) $json['message']
			: 'Mailgun API error (HTTP ' . $code . ')';

		return array( 'ok' => false, 'message_id' => '', 'error' => $error, 'from' => $from );
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
			'is_alert'   => false,
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
				case 'x-wpel-skip':
					$out['is_alert'] = true;
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

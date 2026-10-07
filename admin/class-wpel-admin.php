<?php
/**
 * Admin UI: settings page and email log page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPEL_Admin {

	/** @var WPEL_Admin */
	private static $instance;

	/**
	 * Option name for the two setup-checklist steps that can only be proven
	 * true by actually doing them (webhook registration, a successful test
	 * send) rather than by checking a saved setting — see get_setup_status().
	 */
	const SETUP_STATUS_OPTION = 'wpel_setup_status';

	/**
	 * Stand-in value printed into a secret field (SMTP password, Twilio auth
	 * token) once one is saved, so it shows as a filled-in password without
	 * the real secret ever reaching the page source. Submitting it unchanged
	 * keeps the saved value — see sanitize_secret().
	 */
	const SAVED_SECRET_MASK = '••••••••••••';

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_wpel_send_test_email', array( $this, 'handle_send_test_email' ) );
		add_action( 'admin_notices', array( $this, 'test_email_notice' ) );
		add_action( 'admin_post_wpel_send_test_sms', array( $this, 'handle_send_test_sms' ) );
		add_action( 'admin_notices', array( $this, 'test_sms_notice' ) );
		add_action( 'admin_post_wpel_check_mailgun_config', array( $this, 'handle_check_mailgun_config' ) );
		add_action( 'admin_notices', array( $this, 'mailgun_config_check_notice' ) );
		add_action( 'wp_ajax_wpel_log_entry', array( $this, 'ajax_log_entry' ) );
		add_action( 'admin_post_wpel_log_bulk', array( $this, 'handle_log_bulk' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_query_args' ) );
	}

	public function register_settings() {
		register_setting( 'wpel_settings_group', WPEL_OPTION, array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( $input ) {
		$previous = get_option( WPEL_OPTION, array() );

		$out = array();
		// Mailgun API sending.
		$out['sending_enabled']  = ! empty( $input['sending_enabled'] ) ? 1 : 0;
		$out['api_key']          = sanitize_text_field( isset( $input['api_key'] ) ? $input['api_key'] : '' );
		$out['domain']          = sanitize_text_field( isset( $input['domain'] ) ? $input['domain'] : '' );
		$out['from_name']        = sanitize_text_field( isset( $input['from_name'] ) ? $input['from_name'] : '' );
		$out['from_email']       = sanitize_email( isset( $input['from_email'] ) ? $input['from_email'] : '' );
		$out['force_from_name']  = ! empty( $input['force_from_name'] ) ? 1 : 0;
		$out['force_from_email'] = ! empty( $input['force_from_email'] ) ? 1 : 0;
		// SMTP fallback.
		$out['smtp_host']        = sanitize_text_field( ! empty( $input['smtp_host'] ) ? $input['smtp_host'] : 'smtp.mailgun.org' );
		$smtp_port               = isset( $input['smtp_port'] ) ? (int) $input['smtp_port'] : 587;
		$out['smtp_port']        = ( $smtp_port >= 1 && $smtp_port <= 65535 ) ? $smtp_port : 587;
		$out['smtp_encryption']  = ( isset( $input['smtp_encryption'] ) && in_array( $input['smtp_encryption'], array( 'tls', 'ssl', 'none' ), true ) ) ? $input['smtp_encryption'] : 'tls';
		$out['smtp_username']    = sanitize_text_field( isset( $input['smtp_username'] ) ? $input['smtp_username'] : '' );
		$out['smtp_password']    = $this->sanitize_secret( $input, $previous, 'smtp_password' );
		// Logging + alerting.
		$out['track_opens']      = ! empty( $input['track_opens'] ) ? 1 : 0;
		$out['alert_email']      = $this->sanitize_email_list( isset( $input['alert_email'] ) ? $input['alert_email'] : '' );
		// Saved but currently unused — Slack alerting is disabled and the field is hidden, see class-wpel-monitor.php.
		$out['slack_webhook']    = esc_url_raw( isset( $input['slack_webhook'] ) ? $input['slack_webhook'] : '', array( 'https' ) );
		// SMS via Twilio. The fields stay saved while the toggle is off, so
		// switching it back on doesn't mean re-entering them.
		$out['sms_enabled']        = ! empty( $input['sms_enabled'] ) ? 1 : 0;
		$out['twilio_account_sid'] = sanitize_text_field( isset( $input['twilio_account_sid'] ) ? $input['twilio_account_sid'] : '' );
		$out['twilio_sid']         = sanitize_text_field( isset( $input['twilio_sid'] ) ? $input['twilio_sid'] : '' );
		$out['twilio_auth_token']  = $this->sanitize_secret( $input, $previous, 'twilio_auth_token' );
		$out['twilio_from_number'] = $this->sanitize_twilio_from_number( isset( $input['twilio_from_number'] ) ? $input['twilio_from_number'] : '' );
		$out['twilio_to_numbers']  = $this->sanitize_phone_list( isset( $input['twilio_to_numbers'] ) ? $input['twilio_to_numbers'] : '' );
		// The webhook signing key now comes from wp-config.php and has no field
		// here. Carry over any value an older version saved, so
		// wpel_shared_credential() can still fall back to it on sites that
		// haven't added the constant yet.
		if ( ! empty( $previous['signing_key'] ) ) {
			$out['signing_key'] = $previous['signing_key'];
		}
		$out['alert_temp_fail']  = 0;
		$out['retention_days']   = max( 0, (int) ( isset( $input['retention_days'] ) ? $input['retention_days'] : 30 ) );
		$out['outage_threshold'] = max( 1, (int) ( isset( $input['outage_threshold'] ) ? $input['outage_threshold'] : 5 ) );
		$out['outage_window']    = max( 1, (int) ( isset( $input['outage_window'] ) ? $input['outage_window'] : 15 ) );
		$out['alert_unopened']   = ! empty( $input['alert_unopened'] ) ? 1 : 0;
		$out['unopened_hours']   = max( 1, (int) ( isset( $input['unopened_hours'] ) ? $input['unopened_hours'] : 24 ) );
		$out['unopened_scope']   = ( isset( $input['unopened_scope'] ) && 'selected' === $input['unopened_scope'] ) ? 'selected' : 'all';
		$out['unopened_watch']   = $this->sanitize_unopened_watch( $input );

		// A previously-passing webhook check or test send no longer proves
		// anything once the API key or domain they were run against changes.
		$prev_api_key = isset( $previous['api_key'] ) ? $previous['api_key'] : '';
		$prev_domain  = isset( $previous['domain'] ) ? $previous['domain'] : '';
		if ( $prev_api_key !== $out['api_key'] || $prev_domain !== $out['domain'] ) {
			$this->update_setup_status(
				array(
					'webhook_verified' => false,
					'test_email_sent'  => false,
				)
			);
		}

		return $out;
	}

	/**
	 * The ticked sources for the unopened alert, as source => page id. Each
	 * source takes its page from its group's picker (one per form, shared by
	 * that form's emails); see WPEL_Sources::group_key().
	 * Kept while the scope is "all" (the fields still submit, just hidden),
	 * so switching back doesn't lose the list.
	 */
	private function sanitize_unopened_watch( $input ) {
		$sources = isset( $input['unopened_sources'] ) ? (array) $input['unopened_sources'] : array();
		$pages   = isset( $input['unopened_page'] ) && is_array( $input['unopened_page'] ) ? $input['unopened_page'] : array();

		$out = array();
		foreach ( $sources as $source ) {
			$source = WPEL_Sources::clean( wp_unslash( $source ) );
			if ( '' === $source ) {
				continue;
			}
			$group          = WPEL_Sources::group_key( $source );
			$out[ $source ] = isset( $pages[ $group ] ) ? absint( $pages[ $group ] ) : 0;
		}
		return $out;
	}

	/**
	 * A secret field's saved value is never printed back into the form —
	 * it shows SAVED_SECRET_MASK instead — so receiving the mask back (or
	 * no field at all) means "keep the saved value", and an emptied field
	 * clears it. Not run through sanitize_text_field(), which would mangle
	 * secrets containing < > or %xx sequences.
	 */
	private function sanitize_secret( $input, $previous, $key ) {
		$saved = isset( $previous[ $key ] ) ? $previous[ $key ] : '';
		if ( ! isset( $input[ $key ] ) ) {
			return $saved;
		}
		$value = trim( (string) $input[ $key ] );
		return self::SAVED_SECRET_MASK === $value ? $saved : $value;
	}

	/**
	 * The two setup-checklist steps that can't be inferred from a saved
	 * setting — whether Mailgun's webhook is actually confirmed subscribed,
	 * and whether a test send has ever gone through successfully. Persisted
	 * outside WPEL_OPTION since sanitize_settings() would otherwise strip
	 * unrecognized keys back out on every settings save.
	 */
	private function get_setup_status() {
		return wp_parse_args(
			get_option( self::SETUP_STATUS_OPTION, array() ),
			array(
				'webhook_verified' => false,
				'test_email_sent'  => false,
			)
		);
	}

	private function update_setup_status( $changes ) {
		update_option( self::SETUP_STATUS_OPTION, array_merge( $this->get_setup_status(), $changes ), false );
	}

	/**
	 * Keeps only entries that pass is_email() so a typo sits out of the
	 * option instead of silently failing every alert email later. Reports
	 * any entry it drops via add_settings_error() so it doesn't just vanish
	 * with no explanation.
	 */
	private function sanitize_email_list( $raw ) {
		$entries = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
		$valid   = array();
		$invalid = array();
		foreach ( $entries as $entry ) {
			$cleaned = sanitize_email( $entry );
			if ( is_email( $cleaned ) ) {
				$valid[] = $cleaned;
			} else {
				$invalid[] = $entry;
			}
		}
		if ( $invalid ) {
			add_settings_error(
				WPEL_OPTION,
				'wpel_invalid_email',
				sprintf(
					/* translators: %s: comma-separated list of rejected email addresses */
					__( 'These alert email addresses were removed because they\'re not valid: %s', 'wpel' ),
					esc_html( implode( ', ', $invalid ) )
				),
				'error'
			);
		}
		return implode( ', ', $valid );
	}

	/**
	 * Converts each entry to E.164 (+ followed by 7-15 digits) so users can
	 * type numbers the way they normally would instead of learning the
	 * format. A bare 10-digit number is assumed US/Canada (this plugin's
	 * Twilio setup targets US numbers) and gets "+1" prepended; an 11-digit
	 * number starting with 1 just gets the "+"; anything already carrying a
	 * "+" or the "00" international prefix is taken as already having its
	 * country code. Whatever still doesn't come out looking like a valid
	 * E.164 number is dropped, reported via add_settings_error() so it
	 * doesn't just vanish with no explanation.
	 */
	private function sanitize_phone_list( $raw ) {
		$numbers = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
		$valid   = array();
		$invalid = array();
		foreach ( $numbers as $n ) {
			$normalized = $this->normalize_phone_to_e164( $n );
			if ( preg_match( '/^\+[1-9]\d{6,14}$/', $normalized ) ) {
				$valid[] = $normalized;
			} else {
				$invalid[] = $n;
			}
		}
		if ( $invalid ) {
			add_settings_error(
				WPEL_OPTION,
				'wpel_invalid_phone',
				sprintf(
					/* translators: %s: comma-separated list of rejected phone numbers */
					__( 'These alert phone numbers were removed because they don\'t look like valid phone numbers: %s', 'wpel' ),
					esc_html( implode( ', ', $invalid ) )
				),
				'error'
			);
		}
		return implode( ', ', $valid );
	}

	/**
	 * Same E.164 normalization as sanitize_phone_list(), for the single
	 * Twilio sending number. An invalid entry is cleared and reported rather
	 * than saved, since Twilio would reject every send from it.
	 */
	private function sanitize_twilio_from_number( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		$normalized = $this->normalize_phone_to_e164( $raw );
		if ( preg_match( '/^\+[1-9]\d{6,14}$/', $normalized ) ) {
			return $normalized;
		}
		add_settings_error(
			WPEL_OPTION,
			'wpel_invalid_twilio_from',
			sprintf(
				/* translators: %s: the rejected phone number */
				__( 'The Twilio phone number was removed because it doesn\'t look like a valid phone number: %s', 'wpel' ),
				esc_html( $raw )
			),
			'error'
		);
		return '';
	}

	/**
	 * Best-effort normalization of a user-typed phone number to E.164.
	 * Can't reliably guess a country code for numbers that don't provide
	 * one and aren't 10/11-digit US/Canada numbers — those are returned
	 * as-is (digits only, "+" prefixed) and left for the caller to reject.
	 */
	private function normalize_phone_to_e164( $raw ) {
		$n = trim( (string) $raw );
		if ( '' === $n ) {
			return '';
		}
		$has_plus = ( 0 === strpos( $n, '+' ) );
		$digits   = preg_replace( '/\D/', '', $n );
		if ( $has_plus ) {
			return '+' . $digits;
		}
		if ( 0 === strpos( $digits, '00' ) ) {
			return '+' . substr( $digits, 2 );
		}
		if ( 10 === strlen( $digits ) ) {
			return '+1' . $digits;
		}
		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			return '+' . $digits;
		}
		return '+' . $digits;
	}

	/**
	 * Sends a real test email through the full pipeline (wp_mail -> WPEL_Mailer
	 * -> Mailgun API or SMTP fallback -> logged row) so the settings page can
	 * confirm sending actually works, not just that the form saved. Goes to the
	 * saved alert email recipient(s), falling back to admin_email like real
	 * alerts do.
	 */
	public function handle_send_test_email() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wpel' ) );
		}
		check_admin_referer( 'wpel_send_test_email' );

		$o   = get_option( WPEL_OPTION, array() );
		$to  = ! empty( $o['alert_email'] ) ? $o['alert_email'] : get_option( 'admin_email' );
		$via = WPEL_Mailer::instance()->transport();

		$sent = wp_mail(
			$to,
			'[Mailgun Watch] Test email from ' . wp_parse_url( home_url(), PHP_URL_HOST ),
			"This is a test email sent from the Mailgun Watch settings page at " . current_time( 'mysql' ) . " via " . $this->transport_label( $via ) . " to confirm sending is working.\n\nCheck the Email Log to see how it was recorded."
		);

		// Sticky: once a test send has succeeded, the checklist stays checked
		// even if a later attempt fails (e.g. someone temporarily breaks the
		// config testing something else). Only an API send proves the full
		// pipeline the checklist is about — the SMTP fallback has no webhooks.
		if ( $sent && 'api' === $via ) {
			$this->update_setup_status( array( 'test_email_sent' => true ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'wpel-settings',
					'wpel_test' => $sent ? 'ok' : 'fail',
					'wpel_via'  => $via,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** Human-readable name for a WPEL_Mailer::transport() value. */
	private function transport_label( $transport ) {
		switch ( $transport ) {
			case 'api':
				return 'the Mailgun API';
			case 'smtp':
				return 'the SMTP fallback (' . WPEL_Mailer::instance()->smtp_host() . ')';
			default:
				return 'WordPress\'s default transport (usually PHP mail())';
		}
	}

	public function test_email_notice() {
		if ( ! isset( $_GET['wpel_test'], $_GET['page'] ) || 'wpel-settings' !== $_GET['page'] ) {
			return;
		}
		$via = isset( $_GET['wpel_via'] ) ? sanitize_key( wp_unslash( $_GET['wpel_via'] ) ) : 'api';

		if ( 'ok' === $_GET['wpel_test'] ) {
			$message = 'Test email sent via ' . $this->transport_label( $via ) . '. Check the Email Log to see how it was recorded.';
			if ( 'default' === $via ) {
				$message .= ' Neither the Mailgun API nor the SMTP fallback is set up, so delivery is up to this server.';
			}
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		} else {
			$check = 'smtp' === $via ? 'verify the SMTP fallback settings on the Sending tab' : 'verify your Mailgun API key/domain';
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( 'Test email failed to send via ' . $this->transport_label( $via ) . '. Check the Email Log for the error, and ' . $check . '.' ) . '</p></div>';
		}
	}

	/**
	 * Sends a real test SMS through WPEL_Mailgun_Monitor::send_test_sms() —
	 * a blocking Twilio call (unlike the fire-and-forget one real alerts use)
	 * so this can report back exactly what Twilio said, per number.
	 */
	public function handle_send_test_sms() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wpel' ) );
		}
		check_admin_referer( 'wpel_send_test_sms' );

		$results = WPEL_Mailgun_Monitor::instance()->send_test_sms(
			sprintf(
				'[Mailgun Watch] Test SMS from %s at %s',
				wp_parse_url( home_url(), PHP_URL_HOST ),
				current_time( 'mysql' )
			)
		);

		$all_ok = true;
		foreach ( $results as $r ) {
			if ( empty( $r['ok'] ) ) {
				$all_ok = false;
				break;
			}
		}

		set_transient( 'wpel_test_sms_' . get_current_user_id(), $results, MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'wpel-settings',
					'wpel_sms_test' => $all_ok ? 'ok' : 'fail',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Displays the results of "Send test SMS" once, right after the redirect
	 * it triggers — stored transiently since per-number Twilio responses are
	 * too detailed to round-trip through the URL like test_email_notice() does.
	 */
	public function test_sms_notice() {
		if ( ! isset( $_GET['wpel_sms_test'], $_GET['page'] ) || 'wpel-settings' !== $_GET['page'] ) {
			return;
		}

		$key     = 'wpel_test_sms_' . get_current_user_id();
		$results = get_transient( $key );
		if ( false === $results || ! is_array( $results ) ) {
			return;
		}
		delete_transient( $key );

		$all_ok = 'ok' === $_GET['wpel_sms_test'];
		?>
		<div class="notice <?php echo $all_ok ? 'notice-success' : 'notice-error'; ?> is-dismissible">
			<p><strong><?php echo $all_ok ? 'Test SMS sent.' : 'Test SMS failed.'; ?></strong></p>
			<ul style="margin-left:1.5em;list-style:disc;">
				<?php foreach ( $results as $r ) : ?>
					<li><?php echo ! empty( $r['ok'] ) ? '&#9989;' : '&#10060;'; // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo $r['to'] ? esc_html( $r['to'] ) . ': ' : ''; ?><?php echo esc_html( $r['detail'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Asks Mailgun itself (not just our saved options) whether this site is
	 * wired up correctly: that the API key/domain pair is valid and verified,
	 * and that Mailgun's webhook for this domain is actually pointed at this
	 * site's REST endpoint for every event we need. This is the one setup
	 * step the sidebar checklist can't verify locally, since webhook
	 * registration lives entirely in Mailgun's dashboard.
	 */
	public function handle_check_mailgun_config() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wpel' ) );
		}
		check_admin_referer( 'wpel_check_mailgun_config' );

		$o       = get_option( WPEL_OPTION, array() );
		$results = $this->run_mailgun_config_check( $o );

		$webhook_ok = false;
		foreach ( $results as $r ) {
			if ( 'Webhook registration' === $r['label'] ) {
				$webhook_ok = ! empty( $r['ok'] );
				break;
			}
		}
		$this->update_setup_status( array( 'webhook_verified' => $webhook_ok ) );

		set_transient( 'wpel_mailgun_check_' . get_current_user_id(), $results, MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'wpel-settings',
					'wpel_checked' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Runs the actual Mailgun API calls behind "Check Mailgun config".
	 * Returns a list of { label, ok, detail } — stops early (returning just
	 * the failed step) once a check fails badly enough that later checks
	 * can't be trusted, e.g. no point checking webhooks if the API key
	 * itself was rejected.
	 */
	private function run_mailgun_config_check( $o ) {
		$api_key = isset( $o['api_key'] ) ? trim( $o['api_key'] ) : '';
		$domain  = isset( $o['domain'] ) ? trim( $o['domain'] ) : '';

		if ( ! $api_key || ! $domain ) {
			return array(
				array(
					'label'  => 'API key & domain',
					'ok'     => false,
					'detail' => 'Enter both a Mailgun API key and sending domain on the Sending tab first.',
				),
			);
		}

		$auth = 'Basic ' . base64_encode( 'api:' . $api_key );
		$base = WPEL_Mailer::API_BASE . '/domains/' . rawurlencode( $domain );

		$domain_response = wp_remote_get( $base, array( 'timeout' => 15, 'headers' => array( 'Authorization' => $auth ) ) );

		if ( is_wp_error( $domain_response ) ) {
			return array(
				array(
					'label'  => 'API key & domain',
					'ok'     => false,
					'detail' => 'Could not reach Mailgun: ' . $domain_response->get_error_message(),
				),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $domain_response );

		if ( 401 === $code || 403 === $code ) {
			return array(
				array(
					'label'  => 'API key & domain',
					'ok'     => false,
					'detail' => 'Mailgun rejected the API key (HTTP ' . $code . ').',
				),
			);
		}
		if ( 404 === $code ) {
			return array(
				array(
					'label'  => 'API key & domain',
					'ok'     => false,
					'detail' => 'The API key is valid, but no domain named "' . $domain . '" exists on this Mailgun account.',
				),
			);
		}
		if ( $code < 200 || $code >= 300 ) {
			return array(
				array(
					'label'  => 'API key & domain',
					'ok'     => false,
					'detail' => 'Unexpected response from Mailgun (HTTP ' . $code . ').',
				),
			);
		}

		$body    = json_decode( wp_remote_retrieve_body( $domain_response ), true );
		$state   = isset( $body['domain']['state'] ) ? $body['domain']['state'] : '';
		$checks  = array();

		if ( 'active' === $state ) {
			$checks[] = array(
				'label'  => 'API key & domain',
				'ok'     => true,
				'detail' => 'Domain "' . $domain . '" found and verified in Mailgun.',
			);
		} else {
			$checks[] = array(
				'label'  => 'API key & domain',
				'ok'     => false,
				'detail' => 'Domain "' . $domain . '" exists but its state is "' . ( $state ? $state : 'unknown' ) . '" — its DNS records probably aren\'t verified in Mailgun yet.',
			);
		}

		// Webhooks — only meaningful once the domain itself resolved above.
		$our_url        = untrailingslashit( rest_url( 'wpel/v1/mailgun-webhook' ) );
		$hooks_response = wp_remote_get( $base . '/webhooks', array( 'timeout' => 15, 'headers' => array( 'Authorization' => $auth ) ) );

		if ( is_wp_error( $hooks_response ) ) {
			$checks[] = array(
				'label'  => 'Webhook registration',
				'ok'     => false,
				'detail' => 'Could not reach Mailgun: ' . $hooks_response->get_error_message(),
			);
			return $checks;
		}

		$hcode = (int) wp_remote_retrieve_response_code( $hooks_response );
		$hbody = json_decode( wp_remote_retrieve_body( $hooks_response ), true );

		if ( $hcode < 200 || $hcode >= 300 || empty( $hbody['webhooks'] ) ) {
			$checks[] = array(
				'label'  => 'Webhook registration',
				'ok'     => false,
				'detail' => 'Could not read webhook settings from Mailgun (HTTP ' . $hcode . ').',
			);
			return $checks;
		}

		$required = array( 'accepted', 'delivered', 'permanent_fail' );
		if ( ! empty( $o['track_opens'] ) ) {
			$required[] = 'opened';
		}

		$missing = array();
		foreach ( $required as $event ) {
			$urls = array();
			if ( ! empty( $hbody['webhooks'][ $event ]['url'] ) ) {
				$urls = (array) $hbody['webhooks'][ $event ]['url'];
			} elseif ( ! empty( $hbody['webhooks'][ $event ]['urls'] ) ) {
				$urls = (array) $hbody['webhooks'][ $event ]['urls'];
			}
			$subscribed = false;
			foreach ( $urls as $u ) {
				if ( untrailingslashit( (string) $u ) === $our_url ) {
					$subscribed = true;
					break;
				}
			}
			if ( ! $subscribed ) {
				$missing[] = $event;
			}
		}

		if ( $missing ) {
			$checks[] = array(
				'label'  => 'Webhook registration',
				'ok'     => false,
				'detail' => 'This site\'s webhook URL isn\'t subscribed to: ' . implode( ', ', $missing ) . '. In Mailgun, go to Send → Webhooks → Add webhook → Domain-level, pick this domain, and point (or add) those events at: ' . $our_url,
			);
		} else {
			$checks[] = array(
				'label'  => 'Webhook registration',
				'ok'     => true,
				'detail' => 'This site\'s webhook URL is subscribed to all required events (' . implode( ', ', $required ) . ').',
			);
		}

		return $checks;
	}

	/**
	 * Displays the results of "Check Mailgun config" once, right after the
	 * redirect it triggers — stored transiently since the results are too
	 * detailed to round-trip through the URL like test_email_notice() does.
	 */
	public function mailgun_config_check_notice() {
		if ( ! isset( $_GET['wpel_checked'], $_GET['page'] ) || 'wpel-settings' !== $_GET['page'] ) {
			return;
		}

		$key     = 'wpel_mailgun_check_' . get_current_user_id();
		$results = get_transient( $key );
		if ( false === $results || ! is_array( $results ) ) {
			return;
		}
		delete_transient( $key );

		$all_ok = true;
		foreach ( $results as $r ) {
			if ( empty( $r['ok'] ) ) {
				$all_ok = false;
				break;
			}
		}
		?>
		<div class="notice <?php echo $all_ok ? 'notice-success' : 'notice-warning'; ?> is-dismissible">
			<p><strong><?php echo $all_ok ? 'Mailgun config check passed.' : 'Mailgun config check found issues:'; ?></strong></p>
			<ul style="margin-left:1.5em;list-style:disc;">
				<?php foreach ( $results as $r ) : ?>
					<li><?php echo ! empty( $r['ok'] ) ? '&#9989;' : '&#10060;'; // phpcs:ignore WordPress.Security.EscapeOutput ?> <strong><?php echo esc_html( $r['label'] ); ?>:</strong> <?php echo esc_html( $r['detail'] ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p class="description">Note: the webhook signing key can't be verified this way — Mailgun doesn't expose it via the API. Send a test email and check the Email Log to confirm signed webhook calls are being accepted.</p>
		</div>
		<?php
	}

	public function admin_menu() {
		add_menu_page(
			'Mailgun Watch',
			'Mailgun Watch',
			'manage_options',
			'wpel-log',
			array( $this, 'render_log_page' ),
			'dashicons-email-alt',
			80
		);
		add_submenu_page( 'wpel-log', 'Email Log', 'Log', 'manage_options', 'wpel-log', array( $this, 'render_log_page' ) );
		add_submenu_page( 'wpel-log', 'Settings', 'Settings', 'manage_options', 'wpel-settings', array( $this, 'render_settings_page' ) );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o = get_option( WPEL_OPTION, array() );
		// Missing key (an install that activated before this option existed)
		// defaults to enabled — matches WPEL_Mailer's own default resolution.
		$sending_enabled = ! isset( $o['sending_enabled'] ) || ! empty( $o['sending_enabled'] );
		$transport       = WPEL_Mailer::instance()->transport();
		$smtp_encryption = isset( $o['smtp_encryption'] ) ? $o['smtp_encryption'] : 'tls';
		$sms_enabled     = WPEL_Mailgun_Monitor::instance()->sms_enabled();
		?>
		<div class="wrap">
			<h1>Settings</h1>

			<?php settings_errors( WPEL_OPTION ); ?>

			<div class="wpel-settings-columns">
			<div class="wpel-settings-main">

			<h2 class="nav-tab-wrapper" id="wpel-tabs">
				<a href="#" class="nav-tab nav-tab-active" data-tab="wpel-tab-mailgun">Sending</a>
				<a href="#" class="nav-tab" data-tab="wpel-tab-alerting">Alerting &amp; Logging</a>
			</h2>

			<form method="post" action="options.php">
				<?php settings_fields( 'wpel_settings_group' ); ?>

				<div id="wpel-tab-mailgun" class="wpel-tab-panel">
					<p style="margin-top:16px;">
						<strong>Currently sending via:</strong> <?php echo esc_html( $this->transport_label( $transport ) ); ?>.
					</p>

					<h2 class="title">Mailgun API</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">Send via Mailgun API</th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( WPEL_OPTION ); ?>[sending_enabled]" value="1" <?php checked( $sending_enabled ); ?>> Enabled - this plugin is the mail transport (no SMTP plugin needed)</label>
							<p class="description">When off, mail is sent through the SMTP fallback below.</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_api_key">Mailgun API key</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[api_key]" id="wpel_api_key" type="password" class="regular-text" autocomplete="off" value="<?php echo esc_attr( isset( $o['api_key'] ) ? $o['api_key'] : '' ); ?>">
							<p class="description">Create one <a href="https://app.mailgun.com/settings/api_security" target="_blank">here</a>.</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_domain">Mailgun sending domain</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[domain]" id="wpel_domain" type="text" class="regular-text" placeholder="mg.example.com" value="<?php echo esc_attr( isset( $o['domain'] ) ? $o['domain'] : '' ); ?>">
							<p class="description">e.g. <code>mg.example.com</code>. Create one <a href="https://app.mailgun.com/mg/sending/new-domain" target="_blank">here</a>.</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_from_name">From name</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[from_name]" id="wpel_from_name" type="text" class="regular-text" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>" value="<?php echo esc_attr( isset( $o['from_name'] ) ? $o['from_name'] : '' ); ?>">
							<p class="description">Leave blank to use the Site Title.</p>
							<p><label><input type="checkbox" name="<?php echo esc_attr( WPEL_OPTION ); ?>[force_from_name]" value="1" <?php checked( wpel_force_from( $o, 'name' ) ); ?>> Force from name</label></p>
							<p class="description">Always use this name, even if a plugin/theme sets its own.</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_from_email">From email</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[from_email]" id="wpel_from_email" type="email" class="regular-text" placeholder="<?php echo esc_attr( ! empty( $o['smtp_username'] ) ? $o['smtp_username'] : 'wordpress@mg.example.com' ); ?>" value="<?php echo esc_attr( isset( $o['from_email'] ) ? $o['from_email'] : '' ); ?>">
							<p class="description">Must be on your sending domain.</p>
							<p><label><input type="checkbox" name="<?php echo esc_attr( WPEL_OPTION ); ?>[force_from_email]" value="1" <?php checked( wpel_force_from( $o, 'email' ) ); ?>> Force from email</label></p>
							<p class="description">Always use this address, even if a plugin/theme sets its own.</p></td>
						</tr>
					</table>

					<hr style="margin:24px 0;">

					<h2 class="title">SMTP fallback</h2>
					<p class="description">Used when the Mailgun API isn't set up. No delivery confirmation or open tracking.</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wpel_smtp_host">SMTP host</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[smtp_host]" id="wpel_smtp_host" type="text" class="regular-text" value="<?php echo esc_attr( ! empty( $o['smtp_host'] ) ? $o['smtp_host'] : 'smtp.mailgun.org' ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_smtp_port">Port</label></th>
							<td><div style="display:flex;align-items:center;gap:8px;">
								<input name="<?php echo esc_attr( WPEL_OPTION ); ?>[smtp_port]" id="wpel_smtp_port" type="number" min="1" max="65535" value="<?php echo esc_attr( ! empty( $o['smtp_port'] ) ? $o['smtp_port'] : 587 ); ?>" style="width:90px;margin:0;">
								<select name="<?php echo esc_attr( WPEL_OPTION ); ?>[smtp_encryption]" id="wpel_smtp_encryption" aria-label="Encryption" style="margin:0;">
									<option value="tls" <?php selected( $smtp_encryption, 'tls' ); ?>>TLS (STARTTLS)</option>
									<option value="ssl" <?php selected( $smtp_encryption, 'ssl' ); ?>>SSL</option>
									<option value="none" <?php selected( $smtp_encryption, 'none' ); ?>>None</option>
								</select>
							</div>
							<p class="description">Try <code>2525</code>, or <code>465</code> with SSL, if <code>587</code> is blocked.</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_smtp_username">Username</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[smtp_username]" id="wpel_smtp_username" type="text" class="regular-text" autocomplete="off" placeholder="postmaster@mg.example.com" value="<?php echo esc_attr( isset( $o['smtp_username'] ) ? $o['smtp_username'] : '' ); ?>">
							<p class="description">Clear to turn off the SMTP fallback.</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_smtp_password">Password</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[smtp_password]" id="wpel_smtp_password" type="password" class="regular-text" autocomplete="new-password" value="<?php echo ! empty( $o['smtp_password'] ) ? esc_attr( self::SAVED_SECRET_MASK ) : ''; ?>"></td>
						</tr>
					</table>
				</div>

				<div id="wpel-tab-alerting" class="wpel-tab-panel" style="display:none">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wpel_alert_email">Alert email recipient</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[alert_email]" id="wpel_alert_email" type="email" multiple class="regular-text" value="<?php echo esc_attr( isset( $o['alert_email'] ) ? $o['alert_email'] : '' ); ?>">
							<?php // Submits to the standalone form below via form="" — a real <form> can't nest inside this page's main settings form. ?>
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpel_send_test_email' ) ); ?>" form="wpel-test-email-form">
							<input type="hidden" name="action" value="wpel_send_test_email" form="wpel-test-email-form">
							<button type="submit" class="button" form="wpel-test-email-form" style="margin-left:8px;">Send test email</button>
							<p class="description">Separate multiple addresses with a comma. Save changes before sending a test email — it goes through whichever transport is active (currently <?php echo esc_html( $this->transport_label( $transport ) ); ?>) and is logged like any other send.</p></td>
						</tr>
						<tr>
							<th scope="row">SMS alerts</th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( WPEL_OPTION ); ?>[sms_enabled]" id="wpel_sms_enabled" value="1" <?php checked( $sms_enabled ); ?>> Enable SMS alerts</label>
							<p class="description">SMS alerts are sent when certain events occur, ensuring you are notified even if email delivery fails. Managed via Twilio.</p></td>
						</tr>
						<tr class="wpel-sms-row"<?php echo $sms_enabled ? '' : ' style="display:none"'; ?>>
							<th scope="row"><label for="wpel_twilio_account_sid">Twilio Account SID</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[twilio_account_sid]" id="wpel_twilio_account_sid" type="text" class="regular-text" autocomplete="off" value="<?php echo esc_attr( isset( $o['twilio_account_sid'] ) ? $o['twilio_account_sid'] : '' ); ?>" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
							<p class="description">Starts with <code>AC</code>. Copy it from <strong>Account Info</strong> on the <a href="https://console.twilio.com/" target="_blank">Twilio Console</a> home page.</p></td>
						</tr>
						<tr class="wpel-sms-row"<?php echo $sms_enabled ? '' : ' style="display:none"'; ?>>
							<th scope="row"><label for="wpel_twilio_sid">Twilio API Key SID</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[twilio_sid]" id="wpel_twilio_sid" type="text" class="regular-text" autocomplete="off" value="<?php echo esc_attr( isset( $o['twilio_sid'] ) ? $o['twilio_sid'] : '' ); ?>" placeholder="SKxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
							<p class="description">Optional. Starts with <code>SK</code>. Create one under <a href="https://console.twilio.com/us1/account/keys-credentials/api-keys" target="_blank">API keys &amp; tokens</a> — unlike the Auth Token, it can be revoked on its own. Leave blank to use the Account SID with your account's Auth Token.</p></td>
						</tr>
						<tr class="wpel-sms-row"<?php echo $sms_enabled ? '' : ' style="display:none"'; ?>>
							<th scope="row"><label for="wpel_twilio_auth_token">Twilio auth token</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[twilio_auth_token]" id="wpel_twilio_auth_token" type="password" class="regular-text" autocomplete="new-password" value="<?php echo ! empty( $o['twilio_auth_token'] ) ? esc_attr( self::SAVED_SECRET_MASK ) : ''; ?>">
							<p class="description">The API key's secret. Click "show" on the Twilio dashboard to see the token <a href="https://console.twilio.com/us1/account/keys-credentials/api-keys" target="_blank">here</a></td>
						</tr>
						<tr class="wpel-sms-row"<?php echo $sms_enabled ? '' : ' style="display:none"'; ?>>
							<th scope="row"><label for="wpel_twilio_from">Twilio phone number</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[twilio_from_number]" id="wpel_twilio_from" type="text" class="regular-text" value="<?php echo esc_attr( isset( $o['twilio_from_number'] ) ? $o['twilio_from_number'] : '' ); ?>" placeholder="+555-123-4567">
							<p class="description">The number alerts are sent from. Pick one of your <a href="https://console.twilio.com/us1/develop/phone-numbers/manage/incoming" target="_blank">active Twilio numbers</a>.</p></td>
						</tr>
						<tr class="wpel-sms-row"<?php echo $sms_enabled ? '' : ' style="display:none"'; ?>>
							<th scope="row"><label for="wpel_twilio_to">Alert phone numbers</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[twilio_to_numbers]" id="wpel_twilio_to" type="text" class="regular-text" value="<?php echo esc_attr( isset( $o['twilio_to_numbers'] ) ? $o['twilio_to_numbers'] : '' ); ?>" placeholder="555-123-4567, 555-987-6543">
							<?php // Submits to the standalone form below via form="" — a real <form> can't nest inside this page's main settings form. ?>
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpel_send_test_sms' ) ); ?>" form="wpel-test-sms-form">
							<input type="hidden" name="action" value="wpel_send_test_sms" form="wpel-test-sms-form">
							<button type="submit" class="button" form="wpel-test-sms-form" style="margin-left:8px;">Send test SMS</button>
							<p class="description">Separate multiple numbers with a comma. Save changes before sending a test SMS.</p></td>
						</tr>
						<?php /* Slack hidden for now — functionality still exists (see notify_slack() in class-wpel-monitor.php), just not rendered here.
						<tr>
							<th scope="row"><label for="wpel_slack">Slack Incoming Webhook URL</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[slack_webhook]" id="wpel_slack" type="url" class="regular-text" value="<?php echo esc_attr( isset( $o['slack_webhook'] ) ? $o['slack_webhook'] : '' ); ?>" placeholder="<?php echo defined( 'WPEL_SLACK_WEBHOOK' ) ? 'Using default from wp-config.php' : 'https://hooks.slack.com/services/...'; ?>">
							<p class="description">Create one <a href="https://api.slack.com/apps/A0C0ZJU343B/incoming-webhooks" target="_blank">here</a>: in Slack, enable the "Incoming Webhooks" app for your workspace, add a webhook for the channel that should get alerts, then paste the generated URL here.
							<?php if ( defined( 'WPEL_SLACK_WEBHOOK' ) ) : ?>
								A <code>WPEL_SLACK_WEBHOOK</code> constant is defined in <code>wp-config.php</code> and will be used automatically if this field is left blank.
							<?php endif; ?>
							</p></td>
						</tr>
						*/ ?>
						<tr>
							<th scope="row">Track opens</th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( WPEL_OPTION ); ?>[track_opens]" value="1" <?php checked( ! empty( $o['track_opens'] ) ); ?>> Ask Mailgun to track opens (embeds a tracking pixel in HTML emails)</label>
							<p class="description">Requires subscribing the webhook endpoint below to the <code>opened</code> event. Only works for HTML mail, and privacy features like Apple Mail Privacy Protection can auto-fetch the pixel on delivery regardless of whether anyone reads the email — treat opens as a soft signal, not a read receipt.</p></td>
						</tr>
						<tr>
							<th scope="row">Alert on unopened email</th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( WPEL_OPTION ); ?>[alert_unopened]" value="1" <?php checked( ! empty( $o['alert_unopened'] ) ); ?>> Alert by SMS when a delivered email still hasn't been opened after</label>
							<input name="<?php echo esc_attr( WPEL_OPTION ); ?>[unopened_hours]" type="number" min="1" value="<?php echo esc_attr( isset( $o['unopened_hours'] ) ? $o['unopened_hours'] : 24 ); ?>" style="width:70px"> hours
							<p class="description">Requires "Track opens" above to be enabled — without open tracking every delivered email looks unopened, and you'd get a false alert for all of them. Checked hourly by cron.</p></td>
						</tr>
						<tr>
							<th scope="row">Unopened alerts cover</th>
							<td><?php $this->render_unopened_watch( $o ); ?></td>
						</tr>
						<tr>
							<th scope="row">Webhook endpoint</th>
							<td><code id="wpel_webhook_url"><?php echo esc_html( rest_url( 'wpel/v1/mailgun-webhook' ) ); ?></code>
							<button type="button" class="button-link" data-wpel-copy="wpel_webhook_url" style="margin-left:8px;">Copy</button>
							<p class="description">Add this URL in Mailgun for events: accepted, delivered, permanent_fail (temporary_fail optional; opened required if "Track opens" above is enabled).</p></td>
						</tr>
						<tr>
							<th scope="row">Webhook description</th>
							<td><code id="wpel_webhook_desc"><?php echo esc_html( $this->webhook_description() ); ?></code>
							<button type="button" class="button-link" data-wpel-copy="wpel_webhook_desc" style="margin-left:8px;">Copy</button>
							<p class="description">Paste into the webhook's Description field in Mailgun so you can tell this site's webhook apart from the others.</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_ret">Log retention (days)</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[retention_days]" id="wpel_ret" type="number" min="0" value="<?php echo esc_attr( isset( $o['retention_days'] ) ? $o['retention_days'] : 30 ); ?>"> <span class="description">0 = keep forever</span></td>
						</tr>
						<tr>
							<th scope="row">Outage detection</th>
							<td>
								<input name="<?php echo esc_attr( WPEL_OPTION ); ?>[outage_threshold]" type="number" min="1" value="<?php echo esc_attr( isset( $o['outage_threshold'] ) ? $o['outage_threshold'] : 5 ); ?>" style="width:70px"> failures within
								<input name="<?php echo esc_attr( WPEL_OPTION ); ?>[outage_window]" type="number" min="1" value="<?php echo esc_attr( isset( $o['outage_window'] ) ? $o['outage_window'] : 15 ); ?>" style="width:70px"> minutes (with no success) triggers the "possible total email outage" SMS alarm.
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button(); ?>
			</form>

			<form id="wpel-test-email-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"></form>
			<form id="wpel-test-sms-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"></form>

			</div>
			<?php $this->render_setup_checklist( $o ); ?>
			</div>
		</div>
		<style>
			.wpel-settings-columns { display: flex; align-items: flex-start; gap: 24px; margin-top: 16px; }
			.wpel-settings-main { flex: 1 1 auto; min-width: 0; }
			.wpel-settings-sidebar { flex: 0 0 340px; width: 340px; }
			.wpel-settings-sidebar .postbox ol { margin: 0 0 12px; }
			.wpel-settings-sidebar .postbox li { margin-bottom: 12px; line-height: 1.5; }
			#wpel-unopened-watch { margin-top: 12px; padding-left: 24px; }
			#wpel-unopened-watch h4 { margin: 12px 0 6px; }
			.wpel-watch-group { margin: 0 0 10px; padding: 8px 10px; background: #f6f7f7; border: 1px solid #dcdcde; }
			.wpel-watch-group .wpel-watch-source, .wpel-watch-group .wpel-watch-page { display: block; margin-top: 6px; }
			@media (max-width: 960px) {
				.wpel-settings-columns { display: block; }
				.wpel-settings-sidebar { width: auto; margin-top: 24px; }
			}
		</style>
		<script>
		( function () {
			var tabs   = document.querySelectorAll( '#wpel-tabs .nav-tab' );
			var panels = document.querySelectorAll( '.wpel-tab-panel' );
			var storageKey = 'wpel_active_settings_tab';

			function activate( id ) {
				tabs.forEach( function ( t ) {
					t.classList.toggle( 'nav-tab-active', t.dataset.tab === id );
				} );
				panels.forEach( function ( p ) {
					p.style.display = ( p.id === id ) ? '' : 'none';
				} );
				try {
					window.localStorage.setItem( storageKey, id );
				} catch ( e ) {}
			}

			tabs.forEach( function ( t ) {
				t.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					activate( t.dataset.tab );
				} );
			} );

			var stored = null;
			try {
				stored = window.localStorage.getItem( storageKey );
			} catch ( e ) {}
			if ( stored && document.getElementById( stored ) ) {
				activate( stored );
			}
		} )();

		( function () {
			// "Enable SMS alerts" shows/hides the Twilio fields. Hidden fields
			// still submit, so their saved values survive toggling it off.
			var toggle = document.getElementById( 'wpel_sms_enabled' );
			if ( ! toggle ) {
				return;
			}
			var rows = document.querySelectorAll( '.wpel-sms-row' );
			toggle.addEventListener( 'change', function () {
				rows.forEach( function ( r ) {
					r.style.display = toggle.checked ? '' : 'none';
				} );
			} );
		} )();

		( function () {
			// "Unopened alerts cover" shows the source list only when limited to
			// selected sources. Like the SMS rows, hidden fields still submit.
			var list = document.getElementById( 'wpel-unopened-watch' );
			if ( ! list ) {
				return;
			}
			document.querySelectorAll( 'input[name$="[unopened_scope]"]' ).forEach( function ( radio ) {
				radio.addEventListener( 'change', function () {
					list.style.display = 'selected' === radio.value && radio.checked ? '' : 'none';
				} );
			} );
		} )();

		( function () {
			// Pre-fill "From email" from the sending domain as it's typed, without
			// clobbering a value the admin has since edited by hand. An address on
			// the SMTP fallback's domain (inherited from the boilerplate) counts as
			// not hand-edited, so moving a site onto its own domain switches it over.
			// From name is deliberately never auto-filled: a blank name follows
			// each site's own Site Title, which matters for cloned sites.
			var domainInput = document.getElementById( 'wpel_domain' );
			var emailInput  = document.getElementById( 'wpel_from_email' );
			if ( ! domainInput || ! emailInput ) {
				return;
			}

			var smtpDomain = <?php echo wp_json_encode( WPEL_Mailer::instance()->smtp_domain() ); ?>;

			function deriveEmail( domain ) {
				return domain ? 'wordpress@' + domain : '';
			}

			function onSmtpDomain( email ) {
				return smtpDomain && email.toLowerCase().slice( -( smtpDomain.length + 1 ) ) === '@' + smtpDomain;
			}

			var lastAutoEmail = emailInput.value === deriveEmail( domainInput.value.trim() ) ? emailInput.value : null;

			function sync() {
				var domain = domainInput.value.trim();

				if ( domain && ( ! emailInput.value || emailInput.value === lastAutoEmail || onSmtpDomain( emailInput.value ) ) ) {
					lastAutoEmail = deriveEmail( domain );
					emailInput.value = lastAutoEmail;
				}
			}

			domainInput.addEventListener( 'input', sync );
			sync(); // also fill in on load if the domain is already saved but the email isn't
		} )();

		// Any button with data-wpel-copy="<element id>" copies that element's text.
		( function () {
			function showCopied( btn ) {
				if ( ! btn.dataset.wpelLabel ) {
					btn.dataset.wpelLabel = btn.textContent;
				}
				btn.textContent = 'Copied!';
				setTimeout( function () {
					btn.textContent = btn.dataset.wpelLabel;
				}, 1500 );
			}

			function fallbackCopy( text, btn ) {
				var temp = document.createElement( 'textarea' );
				temp.value = text;
				temp.style.position = 'fixed';
				temp.style.opacity  = '0';
				document.body.appendChild( temp );
				temp.select();
				try {
					document.execCommand( 'copy' );
					showCopied( btn );
				} catch ( e ) {}
				document.body.removeChild( temp );
			}

			document.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '[data-wpel-copy]' );
				if ( ! btn ) {
					return;
				}
				var source = document.getElementById( btn.getAttribute( 'data-wpel-copy' ) );
				if ( ! source ) {
					return;
				}
				var text = source.textContent;
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text ).then( function () {
						showCopied( btn );
					}, function () {
						fallbackCopy( text, btn );
					} );
				} else {
					fallbackCopy( text, btn );
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Suggested Description for this site's Mailgun webhook, so several
	 * sites' webhooks are easy to tell apart in the Mailgun dashboard.
	 * Falls back to the host when the site has no name set.
	 */
	private function webhook_description() {
		$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		if ( '' === trim( $name ) ) {
			$name = wp_parse_url( home_url(), PHP_URL_HOST );
		}
		return $name . ' - Mailgun Watch';
	}

	/**
	 * "Unopened alerts cover" setting: every delivered email, or only the
	 * ticked sources. Lists each form plugin's forms with the emails each
	 * sends (Forminator notifications, HTML Forms "Send Email" actions), so
	 * the email to the business can be watched without the visitor's
	 * auto-reply, then any other source seen in the log. Each form, or other
	 * source, gets an optional page picker.
	 */
	private function render_unopened_watch( $o ) {
		$sources  = WPEL_Sources::instance();
		$scope    = isset( $o['unopened_scope'] ) && 'selected' === $o['unopened_scope'] ? 'selected' : 'all';
		$watch    = ! empty( $o['unopened_watch'] ) && is_array( $o['unopened_watch'] ) ? $o['unopened_watch'] : array();
		$name     = esc_attr( WPEL_OPTION );
		$rendered = array();

		// Saved page per picker group.
		$group_pages = array();
		foreach ( $watch as $source => $page ) {
			if ( $page ) {
				$group_pages[ WPEL_Sources::group_key( $source ) ] = (int) $page;
			}
		}

		$page_picker = function ( $group, $pages, $label ) use ( $name, $group_pages ) {
			$current = isset( $group_pages[ $group ] ) ? $group_pages[ $group ] : 0;
			if ( $current && ! isset( $pages[ $current ] ) ) {
				$pages[ $current ] = WPEL_Sources::page_title( $current );
			}
			if ( ! $pages ) {
				return;
			}
			?>
			<label class="wpel-watch-page"><?php echo esc_html( $label ); ?>
				<select name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput -- esc_attr()'d above ?>[unopened_page][<?php echo esc_attr( $group ); ?>]">
					<option value="0">any page</option>
					<?php foreach ( $pages as $id => $title ) : ?>
						<option value="<?php echo (int) $id; ?>" <?php selected( $current, $id ); ?>><?php echo esc_html( $title ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php
		};

		$checkbox = function ( $source, $label, $hint = '' ) use ( $name, $watch, &$rendered ) {
			$rendered[ $source ] = true;
			?>
			<label class="wpel-watch-source">
				<input type="checkbox" name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput -- esc_attr()'d above ?>[unopened_sources][]" value="<?php echo esc_attr( $source ); ?>" <?php checked( isset( $watch[ $source ] ) ); ?>>
				<?php echo esc_html( $label ); ?>
				<?php if ( '' !== $hint ) : ?>
					<span class="description">&rarr; <?php echo esc_html( $hint ); ?></span>
				<?php endif; ?>
			</label>
			<?php
		};
		?>
		<fieldset>
			<label><input type="radio" name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput -- esc_attr()'d above ?>[unopened_scope]" value="all" <?php checked( 'all', $scope ); ?>> All delivered emails</label><br>
			<label><input type="radio" name="<?php echo $name; // phpcs:ignore WordPress.Security.EscapeOutput -- esc_attr()'d above ?>[unopened_scope]" value="selected" <?php checked( 'selected', $scope ); ?>> Only emails from the sources ticked below</label>
		</fieldset>

		<div id="wpel-unopened-watch"<?php echo 'selected' === $scope ? '' : ' style="display:none"'; ?>>
			<?php foreach ( $sources->form_plugins() as $plugin ) : ?>
				<h4><?php echo esc_html( $plugin['name'] ); ?> forms</h4>
				<?php if ( ! $plugin['forms'] ) : ?>
					<p class="description">No <?php echo esc_html( $plugin['name'] ); ?> forms yet.</p>
				<?php endif; ?>
				<?php foreach ( $plugin['forms'] as $form ) : ?>
					<div class="wpel-watch-group">
						<strong><?php echo esc_html( $form['name'] ); ?></strong> <span class="description">#<?php echo (int) $form['id']; ?></span>
						<?php if ( ! $form['emails'] ) : ?>
							<p class="description">This form doesn't send any emails.</p>
						<?php endif; ?>
						<?php
						foreach ( $form['emails'] as $email ) {
							$checkbox( $email['source'], $email['label'], $email['recipients'] );
						}
						if ( $form['emails'] ) {
							$page_picker( $form['group'], $sources->form_pages( $form['group'] ), 'Only when submitted on' );
						}
						?>
					</div>
				<?php endforeach; ?>
			<?php endforeach; ?>

			<?php
			// Everything else the log has seen, plus anything still ticked that
			// isn't listed above (a deleted form, or its plugin deactivated), so
			// saving the page doesn't silently drop it.
			$others = $sources->logged_sources();
			foreach ( array_keys( $watch ) as $source ) {
				if ( ! isset( $rendered[ $source ] ) && ! isset( $others[ $source ] ) ) {
					$others[ $source ] = array();
				}
			}
			?>
			<?php if ( $others ) : ?>
				<h4>Other sources seen in the log</h4>
				<?php foreach ( $others as $source => $pages ) : ?>
					<div class="wpel-watch-group">
						<?php
						$checkbox( $source, $sources->label( $source ) );
						$page_picker( WPEL_Sources::group_key( $source ), $pages, 'Only from' );
						?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<p class="description">An email's source is recorded when it's sent, so a source only shows up under "Other sources" once it has sent something. Ticking nothing means no unopened alerts at all. To watch a form's emails to your team without the visitor's auto-reply, tick only the email that goes to your team. HTML Forms emails are numbered by their order in the form's actions, so reordering or removing a "Send Email" action changes which one is ticked.</p>
		</div>
		<?php
	}

	/**
	 * Sidebar checklist for the Settings page: only the steps to redo every
	 * time this plugin lands on a new site, with live checkmarks for whatever
	 * can be verified from saved settings, plus two steps (webhook
	 * registration, a successful test send) confirmed via get_setup_status()
	 * since they can only be proven by actually doing them. Shared
	 * wp-config.php credentials only show up here when one is missing.
	 */
	private function render_setup_checklist( $o ) {
		$status          = $this->get_setup_status();
		$has_domain      = ! empty( $o['domain'] );
		$has_api_key     = ! empty( $o['api_key'] );
		$has_from_email  = ! empty( $o['from_email'] );
		$has_webhook     = ! empty( $status['webhook_verified'] );
		$has_test_email  = ! empty( $status['test_email_sent'] );

		// SMS only counts toward the alerts step once it can actually send.
		$monitor         = WPEL_Mailgun_Monitor::instance();
		$sms_enabled     = $monitor->sms_enabled();
		$sms_incomplete  = $sms_enabled && ( empty( $o['twilio_to_numbers'] ) || in_array( '', $monitor->get_twilio_credentials(), true ) );
		$has_alerts      = ! empty( $o['alert_email'] ) || ( $sms_enabled && ! $sms_incomplete );

		// Shared credentials (see wpel_shared_credential()).
		$required = array(
			'WPEL_MAILGUN_SIGNING_KEY' => array( 'signing_key', 'Mailgun HTTP webhook signing key' ),
		);
		$missing = array();
		foreach ( $required as $constant => $info ) {
			if ( ! wpel_shared_credential( $constant, $info[0] ) ) {
				$missing[ $constant ] = $info[1];
			}
		}

		$all_done        = ! $missing && $has_domain && $has_api_key && $has_from_email
			&& $has_webhook && $has_alerts && $has_test_email;
		$done            = '&#9989;';
		$todo            = '&#11036;';

		$transport_notes = array(
			'api'     => array( '#1a7f37', '#edfaef', 'Sending via the Mailgun API', 'Delivery confirmation and open tracking are available.' ),
			'smtp'    => array( '#dba617', '#fcf9e8', 'Sending via the SMTP fallback', 'Mail goes out, but there\'s no delivery confirmation or open tracking until this site has its own domain, API key and webhook (steps below).' ),
			'default' => array( '#d63638', '#fcf0f1', 'Not sending through Mailgun', 'Mail goes through WordPress\'s default transport (usually PHP mail()). Fill in the SMTP fallback on the Sending tab, or complete the steps below.' ),
		);
		$transport_note  = $transport_notes[ WPEL_Mailer::instance()->transport() ];
		?>
		<div class="wpel-settings-sidebar">
			<div class="postbox">
				<h2 class="hndle" style="padding:10px 12px;margin:0;font-size:14px;">New site setup checklist</h2>
				<div style="padding:4px 12px 12px;">
					<div style="border-left:3px solid <?php echo esc_attr( $transport_note[0] ); ?>;background:<?php echo esc_attr( $transport_note[1] ); ?>;padding:8px;margin:8px 0 12px;">
						<strong><?php echo esc_html( $transport_note[2] ); ?></strong>
						<p class="description" style="margin:4px 0 0;"><?php echo esc_html( $transport_note[3] ); ?></p>
					</div>
					<?php if ( $missing ) : ?>
						<div style="border-left:3px solid #d63638;background:#fcf0f1;padding:8px;margin:8px 0 12px;">
							<strong>Missing from wp-config.php</strong>
							<p class="description" style="margin:4px 0;">These are shared by every site, so they aren't in Settings. Add them to <code>wp-config.php</code>:</p>
							<code style="display:block;white-space:pre-wrap;word-break:break-all;font-size:11px;"><?php
							foreach ( $missing as $constant => $hint ) {
								echo esc_html( "define( '{$constant}', '{$hint}' );" ) . "\n";
							}
							?></code>
						</div>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 12px;">
						<?php wp_nonce_field( 'wpel_check_mailgun_config' ); ?>
						<input type="hidden" name="action" value="wpel_check_mailgun_config">
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Check Mailgun config', 'wpel' ); ?></button>
						<p class="description" style="margin-top:4px;">Checks with Mailgun that the domain and API key are valid and the webhook is set up (steps 1, 2 and 4).</p>
					</form>
					<ol style="padding-left:18px;">
						<li><?php echo $has_domain ? $done : $todo; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							Create a <strong>dedicated</strong> Mailgun sending domain for this site and enter it on the <strong>Sending</strong> tab. Never reuse another site's domain (see below). Until steps 1 and 2 are done, mail goes out via the SMTP fallback if it's set up.
							<br><a href="https://app.mailgun.com/mg/sending/new-domain" target="_blank">Add domain &rarr;</a>
						</li>
						<li><?php echo $has_api_key ? $done : $todo; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							Create a Mailgun API key just for this site and paste it on the <strong>Sending</strong> tab, so it can be revoked on its own.
							<br><a href="https://app.mailgun.com/settings/api_security" target="_blank">Create API key &rarr;</a>
						</li>
						<li><?php echo $has_from_email ? $done : $todo; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							Set the From email on the <strong>Sending</strong> tab to an address on that domain, and turn on <strong>Force from email</strong>.
						</li>
						<li><?php echo $has_webhook ? $done : $todo; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							In Mailgun, go to <strong>Send &rarr; Webhooks</strong> &rarr; <strong>Add webhook</strong> &rarr; <strong>Domain-level</strong> (not Account-level), pick this site's domain, and subscribe it to <code>accepted</code>, <code>delivered</code>, <code>permanent_fail</code> and <code>opened</code>, pointing at:
							<br><code id="wpel_checklist_webhook_url" style="word-break:break-all;display:inline-block;margin:4px 0;"><?php echo esc_html( rest_url( 'wpel/v1/mailgun-webhook' ) ); ?></code>
							<button type="button" class="button-link" data-wpel-copy="wpel_checklist_webhook_url">Copy</button>
							<br>with the description:
							<br><code id="wpel_checklist_webhook_desc" style="word-break:break-all;display:inline-block;margin:4px 0;"><?php echo esc_html( $this->webhook_description() ); ?></code>
							<button type="button" class="button-link" data-wpel-copy="wpel_checklist_webhook_desc">Copy</button>
							<br><a href="https://app.mailgun.com/mg/sending/webhooks" target="_blank">Open webhooks &rarr;</a>
							<br><em>Confirmed automatically by <strong>Check Mailgun config</strong> above once it's set up correctly.</em>
						</li>
						<li><?php echo $has_alerts ? $done : $todo; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							Set an alert email and/or enable SMS alerts on the <strong>Alerting &amp; Logging</strong> tab.
							<?php if ( $sms_incomplete ) : ?>
								<br><em>SMS alerts are enabled, but the Twilio settings or alert phone numbers aren't filled in yet, so no texts will be sent.</em>
							<?php endif; ?>
						</li>
						<li><?php echo $has_test_email ? $done : $todo; // phpcs:ignore WordPress.Security.EscapeOutput ?> Click <strong>Send test email</strong> on the Alerting &amp; Logging tab to confirm the whole pipeline end to end.</li>
					</ol>
					<p class="description" style="border-left:3px solid #d63638;padding-left:8px;">
						<strong>Why the dedicated domain matters:</strong> Mailgun webhooks are registered per sending domain, not per site. If two WordPress installs share one domain, only whichever site's URL is registered in Mailgun gets real delivery data back — the other site's sends still go out fine, they just silently stop reconciling to delivered/failed and lose open tracking.
					</p>
					<?php if ( $all_done ) : ?>
						<p style="background:#edfaef;border-left:3px solid #1a7f37;padding:8px;margin-bottom:0;">
							<?php echo $done; // phpcs:ignore WordPress.Security.EscapeOutput ?> <strong>Everything looks good!</strong> This site is fully set up.
						</p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Returns one log row for the Email Log's "View" modal. Fetched on demand
	 * rather than embedded in the page, since a stored HTML body can easily
	 * run to tens of KB and the log shows 50 rows at a time.
	 */
	public function ajax_log_entry() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'You do not have permission to do this.', 403 );
		}
		check_ajax_referer( 'wpel_log_entry' );

		global $wpdb;
		$id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WPEL_Mailgun_Monitor::table() . ' WHERE id = %d', $id ) );
		if ( ! $row ) {
			wp_send_json_error( 'Log entry not found — it may have been pruned.', 404 );
		}

		$headers = json_decode( (string) $row->headers, true );
		$headers = is_array( $headers ) ? array_values( $headers ) : array();
		$events  = json_decode( (string) $row->event_log, true );

		wp_send_json_success(
			array(
				'id'              => (int) $row->id,
				'created_at'      => $row->created_at,
				'from'            => isset( $row->from_address ) ? (string) $row->from_address : '', // older rows predate the column
				'recipient'       => (string) $row->recipient,
				'subject'         => (string) $row->subject,
				'status'          => $row->status,
				'source'          => $row->source ? WPEL_Sources::instance()->label( $row->source ) : '',
				'source_page'     => $row->source_page ? WPEL_Sources::page_title( $row->source_page ) : '',
				'message_id'      => (string) $row->mailgun_message_id,
				'error'           => (string) $row->error_message,
				'open_count'      => (int) $row->open_count,
				'first_opened_at' => $row->first_opened_at,
				'last_opened_at'  => $row->last_opened_at,
				'headers'         => $headers,
				'events'          => is_array( $events ) ? $events : array(),
				'body'            => $row->body, // null when body storage was off at send time
				'is_html'         => $this->body_is_html( $row->body, $headers ),
			)
		);
	}

	/**
	 * Email Log bulk actions: delete the selected rows, or resend them. A
	 * resend goes back through wp_mail(), so each one is logged (and tracked)
	 * as a new row; the original row just gets a "resent" timeline entry.
	 * Rows with no saved message (created straight from a webhook event) are
	 * skipped, and attachments aren't stored so they can't be resent.
	 */
	public function handle_log_bulk() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wpel' ) );
		}
		check_admin_referer( 'wpel_log_bulk' );

		global $wpdb;
		$table  = WPEL_Mailgun_Monitor::table();
		if ( ! empty( $_POST['row_action'] ) ) {
			// A row's own Resend/Delete button ("resend:123"), which acts on
			// just that row whatever else is ticked.
			list( $action, $id ) = array_pad( explode( ':', sanitize_text_field( wp_unslash( $_POST['row_action'] ) ), 2 ), 2, 0 );
			$action = sanitize_key( $action );
			$ids    = array_filter( array( absint( $id ) ) );
		} else {
			$action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
			$ids    = isset( $_POST['ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', (array) $_POST['ids'] ) ) ) ) : array();
		}
		$back   = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wpel-log' );
		$back   = remove_query_arg( $this->removable_query_args( array() ), $back );

		$done    = 0;
		$skipped = 0;
		$failed  = 0;

		if ( $ids && 'delete' === $action ) {
			// absint()'d above, so safe to inline.
			$done = (int) $wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', $ids ) . ')' );
		} elseif ( $ids && 'resend' === $action ) {
			sort( $ids ); // oldest first, so the new rows keep the originals' order
			$monitor = WPEL_Mailgun_Monitor::instance();
			foreach ( $ids as $id ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT recipient, subject, headers, body FROM {$table} WHERE id = %d", $id ) );
				if ( ! $row || null === $row->body || '' === trim( (string) $row->recipient ) ) {
					$skipped++;
					continue;
				}

				$headers = json_decode( (string) $row->headers, true );
				$headers = is_array( $headers ) ? array_values( $headers ) : array();
				// Mail switched to HTML via the wp_mail_content_type filter never
				// had a Content-Type header to log; without one it'd resend as
				// plain text and show raw markup.
				$has_type = false;
				foreach ( $headers as $h ) {
					if ( preg_match( '/^content-type:/i', (string) $h ) ) {
						$has_type = true;
						break;
					}
				}
				if ( ! $has_type && $this->body_is_html( $row->body, $headers ) ) {
					$headers[] = 'Content-Type: text/html; charset=UTF-8';
				}

				if ( wp_mail( $row->recipient, (string) $row->subject, (string) $row->body, $headers ) ) {
					$done++;
					$monitor->log_event( $id, 'resent' );
				} else {
					$failed++;
					$monitor->log_event( $id, 'resend failed' );
				}
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'wpel_bulk'    => $action,
					'wpel_done'    => $done,
					'wpel_skipped' => $skipped,
					'wpel_failed'  => $failed,
				),
				$back
			)
		);
		exit;
	}

	/** Strips the bulk-action result args from the URL once the notice has shown. */
	public function removable_query_args( $args ) {
		return array_merge( $args, array( 'wpel_bulk', 'wpel_done', 'wpel_skipped', 'wpel_failed' ) );
	}

	private function log_bulk_notice() {
		if ( empty( $_GET['wpel_bulk'] ) ) {
			return;
		}
		$action  = sanitize_key( wp_unslash( $_GET['wpel_bulk'] ) );
		$done    = isset( $_GET['wpel_done'] ) ? absint( $_GET['wpel_done'] ) : 0;
		$skipped = isset( $_GET['wpel_skipped'] ) ? absint( $_GET['wpel_skipped'] ) : 0;
		$failed  = isset( $_GET['wpel_failed'] ) ? absint( $_GET['wpel_failed'] ) : 0;
		$emails  = function ( $n ) {
			return $n . ' ' . _n( 'email', 'emails', $n, 'wpel' );
		};

		if ( 'delete' === $action ) {
			$message = 'Deleted ' . $emails( $done ) . '.';
		} elseif ( 'resend' === $action ) {
			$message = 'Resent ' . $emails( $done ) . ' — each appears as a new entry below.';
			if ( $failed ) {
				$message .= ' ' . $emails( $failed ) . ' failed to send; see the new entries for the error.';
			}
			if ( $skipped ) {
				$message .= ' Skipped ' . $emails( $skipped ) . ' with no saved message to resend (entries created from Mailgun events only).';
			}
		} else {
			return;
		}

		$class = ( $failed || ( ! $done && $skipped ) ) ? 'notice-warning' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Trusts an explicit Content-Type header when the sender passed one.
	 * Otherwise sniffs for common tags, since plugins often switch to HTML
	 * via the wp_mail_content_type filter instead, which never shows up in
	 * the headers we log.
	 */
	private function body_is_html( $body, $headers ) {
		if ( null === $body || '' === $body ) {
			return false;
		}
		foreach ( $headers as $h ) {
			if ( preg_match( '/^content-type:\s*([^;]+)/i', (string) $h, $m ) ) {
				return false !== stripos( $m[1], 'text/html' );
			}
		}
		return (bool) preg_match( '/<(html|body|div|p|table|br|a|span|h[1-6])[\s>\/]/i', $body );
	}

	public function render_log_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$table = WPEL_Mailgun_Monitor::table();

		$allowed = array( 'pending', 'sent', 'delivered', 'failed', 'temp-fail', 'complained' );
		$filter  = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$source  = isset( $_GET['source'] ) ? WPEL_Sources::clean( wp_unslash( $_GET['source'] ) ) : '';
		$paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per     = 50;
		$offset  = ( $paged - 1 ) * $per;
		$sources = WPEL_Sources::instance();

		// The source filter narrows every status tab (and their counts); the
		// status tabs themselves stay exclusive. 'opened' is a pseudo-status:
		// a single exclusive tab like the others (not a combinable toggle), so
		// every link in the row replaces the status view wholesale.
		$conditions = array();
		if ( '' !== $source ) {
			$conditions[] = $wpdb->prepare( 'source = %s', $source );
		}
		$by_source = $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '';

		if ( 'opened' === $filter ) {
			$conditions[] = 'open_count > 0';
		} elseif ( in_array( $filter, $allowed, true ) ) {
			$conditions[] = $wpdb->prepare( 'status = %s', $filter );
		}
		$where = $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '';

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}{$where}" );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table}{$where} ORDER BY id DESC LIMIT {$per} OFFSET {$offset}" );

		$counts       = $wpdb->get_results( "SELECT status, COUNT(*) c FROM {$table}{$by_source} GROUP BY status", OBJECT_K );
		$opened_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" . ( $by_source ? $by_source . ' AND' : ' WHERE' ) . ' open_count > 0' );

		$log_url = admin_url( 'admin.php?page=wpel-log' );
		if ( '' !== $source ) {
			$log_url = add_query_arg( 'source', rawurlencode( $source ), $log_url );
		}
		$logged_sources = $sources->all_logged_sources();
		?>
		<div class="wrap">
			<h1>Email Log</h1>
			<?php $this->log_bulk_notice(); ?>
			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( $log_url ); ?>" <?php echo '' === $filter ? 'class="current"' : ''; ?>>All</a> |</li>
				<?php foreach ( $allowed as $s ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( 'status', $s, $log_url ) ); ?>" <?php echo $filter === $s ? 'class="current"' : ''; ?>>
							<?php echo esc_html( ucfirst( $s ) ); ?> (<?php echo isset( $counts[ $s ] ) ? (int) $counts[ $s ]->c : 0; ?>)
						</a> |
					</li>
				<?php endforeach; ?>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'status', 'opened', $log_url ) ); ?>" <?php echo 'opened' === $filter ? 'class="current"' : ''; ?>>
						Opened (<?php echo (int) $opened_count; ?>)
					</a>
				</li>
			</ul>
			<?php // Its fields sit in the tablenav below via form="" — a real <form> can't nest inside the bulk-action form. ?>
			<form id="wpel-log-filter" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="wpel-log">
				<?php if ( '' !== $filter ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $filter ); ?>">
				<?php endif; ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wpel-log-form">
			<input type="hidden" name="action" value="wpel_log_bulk">
			<?php wp_nonce_field( 'wpel_log_bulk' ); ?>
			<div class="tablenav top">
				<div class="alignleft actions bulkactions">
					<label for="wpel-bulk-action" class="screen-reader-text">Select bulk action</label>
					<select name="bulk_action" id="wpel-bulk-action">
						<option value="">Bulk actions</option>
						<option value="resend">Resend</option>
						<option value="delete">Delete</option>
					</select>
					<input type="submit" class="button action" value="Apply">
				</div>
				<div class="alignleft actions">
					<label for="wpel-source-filter" class="screen-reader-text">Filter by source</label>
					<select name="source" id="wpel-source-filter" form="wpel-log-filter">
						<option value="">All sources</option>
						<?php if ( '' !== $source && ! in_array( $source, $logged_sources, true ) ) : ?>
							<option value="<?php echo esc_attr( $source ); ?>" selected><?php echo esc_html( $sources->label( $source ) ); ?></option>
						<?php endif; ?>
						<?php foreach ( $logged_sources as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $source, $s ); ?>><?php echo esc_html( $sources->label( $s ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button" form="wpel-log-filter">Filter</button>
				</div>
				<br class="clear">
			</div>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<td id="cb" class="manage-column column-cb check-column">
							<label class="screen-reader-text" for="cb-select-all-1">Select all</label>
							<input id="cb-select-all-1" type="checkbox">
						</td>
						<th style="width:150px">When</th>
						<th>Recipient</th>
						<th>Subject</th>
						<th style="width:100px">Status</th>
						<th style="width:60px">Opens</th>
						<th>Source</th>
						<th>Detail</th>
						<th style="width:190px">Actions</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="9">No entries.</td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $r ) :
						$badge = array(
							'delivered' => '#1a7f37',
							'failed'    => '#b32d2e',
							'temp-fail' => '#bd8600',
							'complained'=> '#b32d2e',
							'pending'   => '#646970',
							'sent'      => '#2271b1',
						);
						$color = isset( $badge[ $r->status ] ) ? $badge[ $r->status ] : '#646970';
						?>
						<tr>
							<th scope="row" class="check-column">
								<label class="screen-reader-text" for="cb-select-<?php echo (int) $r->id; ?>">Select email #<?php echo (int) $r->id; ?></label>
								<input id="cb-select-<?php echo (int) $r->id; ?>" type="checkbox" name="ids[]" value="<?php echo (int) $r->id; ?>">
							</th>
							<td><?php echo esc_html( $r->created_at ); ?></td>
							<td><?php echo esc_html( $r->recipient ); ?></td>
							<td><?php echo esc_html( $r->subject ); ?></td>
							<td><span style="color:#fff;background:<?php echo esc_attr( $color ); ?>;padding:2px 8px;border-radius:3px;font-size:11px"><?php echo esc_html( $r->status ); ?></span></td>
							<td><?php
							if ( $r->open_count > 0 ) {
								printf(
									'<span title="%1$s">%2$d</span>',
									esc_attr( 'First opened: ' . $r->first_opened_at . "\nLast opened: " . $r->last_opened_at ),
									(int) $r->open_count
								);
							} else {
								echo '&mdash;';
							}
							?></td>
							<td><?php
							if ( $r->source ) {
								printf(
									'<a href="%1$s" title="Show only emails from this source">%2$s</a>',
									esc_url( add_query_arg( 'source', rawurlencode( $r->source ), admin_url( 'admin.php?page=wpel-log' ) ) ),
									esc_html( $sources->label( $r->source ) )
								);
								if ( $r->source_page ) {
									printf(
										'<br><span class="description">on <a href="%1$s" target="_blank">%2$s</a></span>',
										esc_url( get_permalink( $r->source_page ) ),
										esc_html( WPEL_Sources::page_title( $r->source_page ) )
									);
								}
							} else {
								echo '&mdash;';
							}
							?></td>
							<td><?php echo esc_html( $r->error_message ? $r->error_message : '' ); ?></td>
							<td class="wpel-row-actions">
								<button type="button" class="button button-small" data-wpel-view="<?php echo (int) $r->id; ?>">View</button>
								<?php if ( null !== $r->body ) : // nothing to resend for rows created straight from a webhook event ?>
									<button type="submit" class="button button-small" name="row_action" value="resend:<?php echo (int) $r->id; ?>">Resend</button>
								<?php endif; ?>
								<button type="submit" class="button button-small wpel-delete" name="row_action" value="delete:<?php echo (int) $r->id; ?>">Delete</button>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			</form>
			<?php
			$pages = (int) ceil( $total / $per );
			if ( $pages > 1 ) {
				$base = add_query_arg( 'paged', '%#%', $filter ? add_query_arg( 'status', $filter, $log_url ) : $log_url );
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => $base,
							'format'    => '',
							'current'   => $paged,
							'total'     => $pages,
							'prev_text' => '‹',
							'next_text' => '›',
						)
					)
				);
				echo '</div></div>';
			}
			?>

			<dialog id="wpel-entry-modal" class="wpel-modal" aria-labelledby="wpel-entry-title">
				<div class="wpel-modal-inner">
					<div class="wpel-modal-header">
						<h2 id="wpel-entry-title">Email</h2>
						<button type="button" class="wpel-modal-close" data-wpel-close aria-label="Close">&times;</button>
					</div>
					<div class="wpel-modal-body">
						<nav class="nav-tab-wrapper">
							<a href="#" class="nav-tab" data-tab="info">Info</a>
							<a href="#" class="nav-tab" data-tab="message">Message</a>
							<a href="#" class="nav-tab" data-tab="source">Source</a>
						</nav>
						<div class="wpel-modal-panel" data-panel="info"></div>
						<div class="wpel-modal-panel" data-panel="message"></div>
						<div class="wpel-modal-panel" data-panel="source"></div>
					</div>
					<div class="wpel-modal-footer">
						<button type="button" class="button" data-wpel-close>Close</button>
					</div>
				</div>
			</dialog>
		</div>
		<style>
			.wpel-modal { width: min(1000px, calc(100vw - 32px)); max-height: calc(100vh - 64px); padding: 0; border: 0; border-radius: 4px; box-shadow: 0 5px 30px rgba(0,0,0,.3); }
			.wpel-modal::backdrop { background: rgba(0,0,0,.6); }
			.wpel-modal-inner { display: flex; flex-direction: column; max-height: calc(100vh - 64px); }
			.wpel-modal-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid #dcdcde; }
			.wpel-modal-header h2 { margin: 0; font-size: 18px; }
			.wpel-modal-close { background: none; border: 0; padding: 4px 8px; font-size: 24px; line-height: 1; color: #646970; cursor: pointer; }
			.wpel-modal-body { flex: 1 1 auto; overflow: auto; padding: 16px; }
			.wpel-modal .nav-tab-wrapper { margin-bottom: 12px; padding-top: 0; }
			.wpel-modal-panel iframe, .wpel-plain, .wpel-source { display: block; box-sizing: border-box; width: 100%; height: 60vh; margin: 0; border: 1px solid #dcdcde; background: #fff; }
			.wpel-plain, .wpel-source { overflow: auto; padding: 12px; white-space: pre-wrap; overflow-wrap: anywhere; }
			.wpel-source { font-size: 12px; }
			.wpel-info-table th { width: 160px; font-weight: 600; }
			.wpel-info-table pre { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
			.wpel-info-table ol { margin: 0 0 0 18px; }
			.wpel-modal-footer { padding: 12px 16px; border-top: 1px solid #dcdcde; text-align: right; }
			.wpel-row-actions .button { margin: 0 2px 2px 0; }
			.wp-core-ui .wpel-row-actions .wpel-delete { color: #b32d2e; border-color: #b32d2e; }
			.wp-core-ui .wpel-row-actions .wpel-delete:hover, .wp-core-ui .wpel-row-actions .wpel-delete:focus { color: #fff; background: #b32d2e; border-color: #b32d2e; }
		</style>
		<script>
		( function () {
			var form = document.getElementById( 'wpel-log-form' );
			if ( ! form ) {
				return;
			}
			form.addEventListener( 'submit', function ( e ) {
				var rowButton = e.submitter && 'row_action' === e.submitter.name ? e.submitter : null;
				var action, noun;

				if ( rowButton ) {
					// A row's own Resend/Delete button: acts on that row only.
					action = rowButton.value.split( ':' )[0];
					noun   = 'this email';
				} else {
					action = form.querySelector( '#wpel-bulk-action' ).value;
					var checked = form.querySelectorAll( 'input[name="ids[]"]:checked' ).length;
					var message = '';
					if ( ! action ) {
						message = 'Choose a bulk action first.';
					} else if ( ! checked ) {
						message = 'Select at least one email first.';
					}
					if ( message ) {
						e.preventDefault();
						window.alert( message );
						return;
					}
					noun = checked + ' ' + ( 1 === checked ? 'email' : 'emails' );
				}

				var one      = !! rowButton;
				var question = 'delete' === action
					? 'Permanently delete ' + noun + ' from the log?'
					: 'Resend ' + noun + ' to the original ' + ( one ? 'recipient? It goes' : 'recipients? Each goes' ) + ' out again as a new log entry. Attachments aren\'t saved, so they won\'t be included.';
				if ( ! window.confirm( question ) ) {
					e.preventDefault();
				}
			} );
		} )();

		( function () {
			var modal = document.getElementById( 'wpel-entry-modal' );
			if ( ! modal || 'function' !== typeof modal.showModal ) {
				return;
			}

			var ajaxUrl     = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce       = <?php echo wp_json_encode( wp_create_nonce( 'wpel_log_entry' ) ); ?>;
			var title       = document.getElementById( 'wpel-entry-title' );
			var tabs        = modal.querySelectorAll( '.nav-tab' );
			var sourceTab   = modal.querySelector( '[data-tab="source"]' );
			var panels      = {};
			var request     = 0; // ignores a slow response that lands after another entry was opened

			modal.querySelectorAll( '.wpel-modal-panel' ).forEach( function ( p ) {
				panels[ p.dataset.panel ] = p;
			} );

			function el( tag, text, className ) {
				var node = document.createElement( tag );
				if ( text ) {
					node.textContent = text;
				}
				if ( className ) {
					node.className = className;
				}
				return node;
			}

			function activate( name ) {
				tabs.forEach( function ( t ) {
					t.classList.toggle( 'nav-tab-active', t.dataset.tab === name );
				} );
				Object.keys( panels ).forEach( function ( key ) {
					panels[ key ].style.display = key === name ? '' : 'none';
				} );
			}

			function clear() {
				Object.keys( panels ).forEach( function ( key ) {
					panels[ key ].textContent = '';
				} );
			}

			function addRow( tbody, label, value ) {
				if ( null === value || undefined === value || '' === value ) {
					return;
				}
				var tr = tbody.insertRow();
				tr.appendChild( el( 'th', label ) );
				var td = tr.insertCell();
				if ( value instanceof Node ) {
					td.appendChild( value );
				} else {
					td.textContent = value;
				}
			}

			function renderInfo( d ) {
				var table = el( 'table', '', 'widefat striped wpel-info-table' );
				var tbody = table.createTBody();

				addRow( tbody, 'Logged', d.created_at );
				addRow( tbody, 'From', d.from );
				addRow( tbody, 'To', d.recipient );
				addRow( tbody, 'Subject', d.subject );
				addRow( tbody, 'Status', d.status );
				addRow( tbody, 'Source', d.source + ( d.source_page ? ' (on ' + d.source_page + ')' : '' ) );
				addRow( tbody, 'Error / detail', d.error );
				addRow( tbody, 'Mailgun message ID', d.message_id );
				addRow( tbody, 'Opens', d.open_count > 0
					? d.open_count + '× (first ' + d.first_opened_at + ', last ' + d.last_opened_at + ')'
					: 'None recorded' );
				if ( d.headers.length ) {
					addRow( tbody, 'Headers', el( 'pre', d.headers.join( '\n' ) ) );
				}
				if ( d.events.length ) {
					var list = el( 'ol' );
					d.events.forEach( function ( ev ) {
						list.appendChild( el( 'li', ( ev.t || '' ) + ' — ' + ( ev.e || '' ) ) );
					} );
					addRow( tbody, 'Timeline', list );
				}

				panels.info.appendChild( table );
			}

			function renderMessage( d ) {
				if ( null === d.body ) {
					// Logged before messages were saved, or created straight from
					// a webhook/failure event with no send captured on this site.
					panels.message.appendChild( el( 'p', 'No message was saved for this email.' ) );
					return;
				}
				if ( '' === d.body ) {
					panels.message.appendChild( el( 'p', 'This email had an empty message.' ) );
					return;
				}
				if ( ! d.is_html ) {
					panels.message.appendChild( el( 'div', d.body, 'wpel-plain' ) );
					return;
				}

				// Sandboxed with no allow-scripts/allow-same-origin, so markup
				// from the email (which can include whatever a visitor typed
				// into a form) can't run script or touch the admin page. The
				// injected <base> opens links in a new tab instead of inside
				// the frame.
				var base  = '<base target="_blank">';
				var html  = /<head[^>]*>/i.test( d.body )
					? d.body.replace( /<head[^>]*>/i, function ( m ) { return m + base; } )
					: base + d.body;
				var frame = el( 'iframe' );
				frame.setAttribute( 'sandbox', 'allow-popups allow-popups-to-escape-sandbox' );
				frame.setAttribute( 'title', 'Email message' );
				frame.srcdoc = html;
				panels.message.appendChild( frame );

				panels.source.appendChild( el( 'pre', d.body, 'wpel-source' ) );
				sourceTab.style.display = '';
			}

			function open( id ) {
				var mine = ++request;
				clear();
				title.textContent       = 'Email #' + id;
				sourceTab.style.display = 'none'; // only meaningful for HTML mail
				panels.info.appendChild( el( 'p', 'Loading…' ) );
				activate( 'info' );
				modal.showModal();

				fetch( ajaxUrl + '?action=wpel_log_entry&id=' + encodeURIComponent( id ) + '&_ajax_nonce=' + encodeURIComponent( nonce ), { credentials: 'same-origin' } )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( res ) {
						if ( mine !== request ) {
							return;
						}
						clear();
						if ( ! res || ! res.success ) {
							panels.info.appendChild( el( 'p', res && 'string' === typeof res.data ? res.data : 'Could not load this log entry. Reload the page and try again.' ) );
							return;
						}
						renderInfo( res.data );
						renderMessage( res.data );
						activate( null !== res.data.body ? 'message' : 'info' );
					} )
					.catch( function () {
						if ( mine !== request ) {
							return;
						}
						clear();
						panels.info.appendChild( el( 'p', 'Could not load this log entry. Reload the page and try again.' ) );
					} );
			}

			tabs.forEach( function ( t ) {
				t.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					activate( t.dataset.tab );
				} );
			} );

			document.addEventListener( 'click', function ( e ) {
				var view = e.target.closest( '[data-wpel-view]' );
				if ( view ) {
					open( view.getAttribute( 'data-wpel-view' ) );
					return;
				}
				// The dialog itself has no padding, so a click landing on it
				// directly (not on .wpel-modal-inner) is a click on the backdrop.
				if ( e.target.closest( '[data-wpel-close]' ) || e.target === modal ) {
					modal.close();
				}
			} );

			modal.addEventListener( 'close', function () {
				request++;
				clear(); // drops the iframe so nothing in it keeps loading
			} );
		} )();
		</script>
		<?php
	}
}

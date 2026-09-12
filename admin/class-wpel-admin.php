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
		$out['domain']           = sanitize_text_field( isset( $input['domain'] ) ? $input['domain'] : '' );
		$out['from_name']        = sanitize_text_field( isset( $input['from_name'] ) ? $input['from_name'] : '' );
		$out['from_email']       = sanitize_email( isset( $input['from_email'] ) ? $input['from_email'] : '' );
		$out['force_from']       = ! empty( $input['force_from'] ) ? 1 : 0;
		// Logging + alerting.
		$out['track_opens']      = ! empty( $input['track_opens'] ) ? 1 : 0;
		$out['alert_email']      = $this->sanitize_email_list( isset( $input['alert_email'] ) ? $input['alert_email'] : '' );
		// Saved but currently unused — Slack alerting is disabled and the field is hidden, see class-wpel-monitor.php.
		$out['slack_webhook']    = esc_url_raw( isset( $input['slack_webhook'] ) ? $input['slack_webhook'] : '', array( 'https' ) );
		// Twilio credentials: left blank here, the matching wp-config.php constant
		// still applies (see get_twilio_credentials() in class-wpel-monitor.php) —
		// same override precedence as the Mailgun signing key below.
		$out['twilio_account_sid'] = sanitize_text_field( isset( $input['twilio_account_sid'] ) ? $input['twilio_account_sid'] : '' );
		$out['twilio_sid']         = sanitize_text_field( isset( $input['twilio_sid'] ) ? $input['twilio_sid'] : '' );
		$out['twilio_auth_token']  = sanitize_text_field( isset( $input['twilio_auth_token'] ) ? $input['twilio_auth_token'] : '' );
		$out['twilio_from_number'] = sanitize_text_field( isset( $input['twilio_from_number'] ) ? $input['twilio_from_number'] : '' );
		$out['twilio_to_numbers']  = $this->sanitize_phone_list( isset( $input['twilio_to_numbers'] ) ? $input['twilio_to_numbers'] : '' );
		$out['signing_key']      = sanitize_text_field( isset( $input['signing_key'] ) ? $input['signing_key'] : '' );
		$out['store_body']       = 0;
		$out['alert_temp_fail']  = 0;
		$out['retention_days']   = max( 0, (int) ( isset( $input['retention_days'] ) ? $input['retention_days'] : 30 ) );
		$out['outage_threshold'] = max( 1, (int) ( isset( $input['outage_threshold'] ) ? $input['outage_threshold'] : 5 ) );
		$out['outage_window']    = max( 1, (int) ( isset( $input['outage_window'] ) ? $input['outage_window'] : 15 ) );
		$out['alert_unopened']   = ! empty( $input['alert_unopened'] ) ? 1 : 0;
		$out['unopened_hours']   = max( 1, (int) ( isset( $input['unopened_hours'] ) ? $input['unopened_hours'] : 24 ) );

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
	 * -> Mailgun API -> logged row) so the settings page can confirm sending
	 * actually works, not just that the form saved.
	 */
	public function handle_send_test_email() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wpel' ) );
		}
		check_admin_referer( 'wpel_send_test_email' );

		$user    = wp_get_current_user();
		$posted  = isset( $_POST['wpel_test_to'] ) ? sanitize_email( wp_unslash( $_POST['wpel_test_to'] ) ) : '';
		$to      = is_email( $posted ) ? $posted : ( $user->user_email ? $user->user_email : get_option( 'admin_email' ) );

		$sent = wp_mail(
			$to,
			'[Mailgun Watch] Test email from ' . wp_parse_url( home_url(), PHP_URL_HOST ),
			"This is a test email sent from the Mailgun Watch settings page at " . current_time( 'mysql' ) . " to confirm Mailgun API sending is working.\n\nCheck the Email Log to see how it was recorded."
		);

		if ( $sent ) {
			// Sticky: once a test send has succeeded, the checklist stays
			// checked even if a later attempt fails (e.g. someone temporarily
			// breaks the config testing something else).
			$this->update_setup_status( array( 'test_email_sent' => true ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'wpel-settings',
					'wpel_test' => $sent ? 'ok' : 'fail',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function test_email_notice() {
		if ( ! isset( $_GET['wpel_test'], $_GET['page'] ) || 'wpel-settings' !== $_GET['page'] ) {
			return;
		}
		if ( 'ok' === $_GET['wpel_test'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Test email sent. Check the Email Log to see how it was recorded.', 'wpel' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Test email failed to send. Check the Email Log for the error, and verify your Mailgun API key/domain.', 'wpel' ) . '</p></div>';
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
					'detail' => 'Enter both a Mailgun API key and sending domain on the Mailgun Sending tab first.',
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
				'detail' => 'This site\'s webhook URL isn\'t subscribed to: ' . implode( ', ', $missing ) . '. In Mailgun, open this domain → Webhooks and point (or add) those events at: ' . $our_url,
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
		?>
		<div class="wrap">
			<h1>Settings</h1>

			<?php settings_errors( WPEL_OPTION ); ?>

			<div class="wpel-settings-columns">
			<div class="wpel-settings-main">

			<h2 class="nav-tab-wrapper" id="wpel-tabs">
				<a href="#" class="nav-tab nav-tab-active" data-tab="wpel-tab-mailgun">Mailgun Sending</a>
				<a href="#" class="nav-tab" data-tab="wpel-tab-twilio">Twilio / SMS</a>
				<a href="#" class="nav-tab" data-tab="wpel-tab-alerting">Alerting &amp; Logging</a>
			</h2>

			<form method="post" action="options.php">
				<?php settings_fields( 'wpel_settings_group' ); ?>

				<div id="wpel-tab-mailgun" class="wpel-tab-panel">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">Send via Mailgun API</th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( WPEL_OPTION ); ?>[sending_enabled]" value="1" <?php checked( $sending_enabled ); ?>> Enabled — this plugin is the mail transport (no SMTP plugin needed)</label>
							<p class="description">When off, WordPress falls back to its default transport (usually PHP <code>mail()</code>), which most hosts don't deliver reliably.</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_api_key">Mailgun API key</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[api_key]" id="wpel_api_key" type="password" class="regular-text" autocomplete="off" value="<?php echo esc_attr( isset( $o['api_key'] ) ? $o['api_key'] : '' ); ?>">
							<p class="description">Get it <a href="https://app.mailgun.com/settings/api_security" target="_blank">here</a></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_domain">Mailgun sending domain</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[domain]" id="wpel_domain" type="text" class="regular-text" placeholder="mg.example.com" value="<?php echo esc_attr( isset( $o['domain'] ) ? $o['domain'] : '' ); ?>">
							<p class="description">Your Mailgun sending domain, e.g., <code>mg.example.com</code>. Create one <a href="https://app.mailgun.com/mg/sending/new-domain" target="_blank">here</a></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_from_name">Default from name</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[from_name]" id="wpel_from_name" type="text" class="regular-text" value="<?php echo esc_attr( isset( $o['from_name'] ) ? $o['from_name'] : '' ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_from_email">Default from email</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[from_email]" id="wpel_from_email" type="email" class="regular-text" value="<?php echo esc_attr( isset( $o['from_email'] ) ? $o['from_email'] : '' ); ?>">
							<p class="description">Must be on a domain verified in Mailgun, or the send will be rejected.</p></td>
						</tr>
						<tr>
							<th scope="row">Force from address</th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( WPEL_OPTION ); ?>[force_from]" value="1" <?php checked( ! empty( $o['force_from'] ) ); ?>> Always use the default from name/email above, even if a plugin/theme sets its own</label>
							<p class="description">Recommended: many plugins set an unverified From address (e.g. WordPress's own default, <code>wordpress@<?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></code>), which Mailgun will reject unless that exact domain is verified.</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_key">Mailgun webhook signing key</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[signing_key]" id="wpel_key" type="text" class="regular-text" value="<?php echo esc_attr( isset( $o['signing_key'] ) ? $o['signing_key'] : '' ); ?>" placeholder="<?php echo defined( 'WPEL_MAILGUN_SIGNING_KEY' ) ? 'Using default from wp-config.php' : ''; ?>">
							<p class="description">Click <a href="https://app.mailgun.com/mg/sending/mg.abpdaily.com/webhooks/account-level?tab=account-level" target="_blank">here</a> to create one. Required; unsigned webhook calls are rejected.
							<?php if ( defined( 'WPEL_MAILGUN_SIGNING_KEY' ) ) : ?>
								A <code>WPEL_MAILGUN_SIGNING_KEY</code> constant is defined in <code>wp-config.php</code> and will be used automatically if this field is left blank.
							<?php endif; ?>
							</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_test_to">Send test email</label></th>
							<td>
								<?php // These fields submit to the standalone form below via the form="" attribute — a real <form> can't nest inside this page's main settings form. ?>
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpel_send_test_email' ) ); ?>" form="wpel-test-email-form">
								<input type="hidden" name="action" value="wpel_send_test_email" form="wpel-test-email-form">
								<input type="email" name="wpel_test_to" id="wpel_test_to" class="regular-text" placeholder="you@example.com" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" form="wpel-test-email-form" required>
								<button type="submit" class="button" form="wpel-test-email-form">Send test email</button>
								<p class="description">Sends a real email through this settings page's current saved configuration and logs it like any other send.</p>
							</td>
						</tr>
					</table>
				</div>

				<div id="wpel-tab-twilio" class="wpel-tab-panel" style="display:none">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wpel_twilio_account_sid">Twilio Account SID</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[twilio_account_sid]" id="wpel_twilio_account_sid" type="text" class="regular-text" autocomplete="off" value="<?php echo esc_attr( isset( $o['twilio_account_sid'] ) ? $o['twilio_account_sid'] : '' ); ?>" placeholder="<?php echo defined( 'WPEL_TWILIO_ACCOUNT_SID' ) ? 'Using default from wp-config.php' : 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx'; ?>">
							<p class="description">The real Account SID from the <a href="https://console.twilio.com/" target="_blank">Twilio Console</a> dashboard (starts with <code>AC</code>) — always used in the API URL, regardless of which credential pair authenticates below.
							<?php if ( defined( 'WPEL_TWILIO_ACCOUNT_SID' ) ) : ?>
								A <code>WPEL_TWILIO_ACCOUNT_SID</code> constant is defined in <code>wp-config.php</code> and will be used automatically if this field is left blank.
							<?php endif; ?>
							</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_twilio_sid">Twilio SID</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[twilio_sid]" id="wpel_twilio_sid" type="text" class="regular-text" autocomplete="off" value="<?php echo esc_attr( isset( $o['twilio_sid'] ) ? $o['twilio_sid'] : '' ); ?>" placeholder="<?php echo defined( 'WPEL_TWILIO_SID' ) ? 'Using default from wp-config.php' : 'ACxxxx... or SKxxxx...'; ?>">
							<p class="description">Basic Auth username: either the Account SID above + the Auth Token below (the master credentials), or an API Key SID (starts with <code>SK</code>, recommended since it's independently revocable) + its Secret from <em>Console &rarr; Account &rarr; API keys &amp; tokens</em>.
							<?php if ( defined( 'WPEL_TWILIO_SID' ) ) : ?>
								A <code>WPEL_TWILIO_SID</code> constant is defined in <code>wp-config.php</code> and will be used automatically if this field is left blank.
							<?php endif; ?>
							</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_twilio_auth_token">Twilio Auth Token</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[twilio_auth_token]" id="wpel_twilio_auth_token" type="password" class="regular-text" autocomplete="off" value="<?php echo esc_attr( isset( $o['twilio_auth_token'] ) ? $o['twilio_auth_token'] : '' ); ?>" placeholder="<?php echo defined( 'WPEL_TWILIO_AUTH_TOKEN' ) ? 'Using default from wp-config.php' : ''; ?>">
							<p class="description">Pairs with whichever Twilio SID you used above — the master Auth Token, or the API Key's Secret.
							<?php if ( defined( 'WPEL_TWILIO_AUTH_TOKEN' ) ) : ?>
								A <code>WPEL_TWILIO_AUTH_TOKEN</code> constant is defined in <code>wp-config.php</code> and will be used automatically if this field is left blank.
							<?php endif; ?>
							</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_twilio_from_number">Twilio from number</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[twilio_from_number]" id="wpel_twilio_from_number" type="text" class="regular-text" value="<?php echo esc_attr( isset( $o['twilio_from_number'] ) ? $o['twilio_from_number'] : '' ); ?>" placeholder="<?php echo defined( 'WPEL_TWILIO_FROM_NUMBER' ) ? 'Using default from wp-config.php' : '+15551234567'; ?>">
							<p class="description">The Twilio sending number, in E.164 format (leading <code>+</code>, then country code).
							<?php if ( defined( 'WPEL_TWILIO_FROM_NUMBER' ) ) : ?>
								A <code>WPEL_TWILIO_FROM_NUMBER</code> constant is defined in <code>wp-config.php</code> and will be used automatically if this field is left blank.
							<?php endif; ?>
							</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wpel_twilio_to">Alert phone numbers</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[twilio_to_numbers]" id="wpel_twilio_to" type="text" class="regular-text" value="<?php echo esc_attr( isset( $o['twilio_to_numbers'] ) ? $o['twilio_to_numbers'] : '' ); ?>" placeholder="555-123-4567, 555-987-6543">
							<?php // Submits to the standalone form below via form="" — a real <form> can't nest inside this page's main settings form. ?>
							<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'wpel_send_test_sms' ) ); ?>" form="wpel-test-sms-form">
							<input type="hidden" name="action" value="wpel_send_test_sms" form="wpel-test-sms-form">
							<button type="submit" class="button" form="wpel-test-sms-form" style="margin-left:8px;">Send test SMS</button>
							<p class="description">
								Failure, outage, and unopened-email alerts are texted to every number here (comma-separated) — this is per-site, unlike the Twilio credentials above which are commonly shared account-wide via wp-config.php. "Send test SMS" texts all of them now, using the saved numbers and Twilio config — save changes above first if you just edited this field.
							</p></td>
						</tr>
					</table>
				</div>

				<div id="wpel-tab-alerting" class="wpel-tab-panel" style="display:none">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wpel_alert_email">Alert email recipient</label></th>
							<td><input name="<?php echo esc_attr( WPEL_OPTION ); ?>[alert_email]" id="wpel_alert_email" type="email" multiple class="regular-text" value="<?php echo esc_attr( isset( $o['alert_email'] ) ? $o['alert_email'] : '' ); ?>">
							<p class="description">Separate multiple addresses with a comma.</p></td>
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
							<p class="description">Requires "Track opens" above to be enabled — without open tracking every delivered email looks unopened, and you'd get a false alert for all of them. Checked hourly by cron; each email is only alerted once.</p></td>
						</tr>
						<tr>
							<th scope="row">Webhook endpoint</th>
							<td><code id="wpel_webhook_url"><?php echo esc_html( rest_url( 'wpel/v1/mailgun-webhook' ) ); ?></code>
							<button type="button" class="button-link" id="wpel_copy_webhook" style="margin-left:8px;">Copy</button>
							<p class="description">Add this URL in Mailgun for events: accepted, delivered, permanent_fail (temporary_fail optional; opened required if "Track opens" above is enabled).</p></td>
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
			// Pre-fill "Default from name/email" from the sending domain as it's
			// typed, without clobbering a value the admin has since edited by hand.
			var domainInput = document.getElementById( 'wpel_domain' );
			var nameInput   = document.getElementById( 'wpel_from_name' );
			var emailInput  = document.getElementById( 'wpel_from_email' );
			if ( ! domainInput || ! emailInput ) {
				return;
			}

			var siteName = <?php echo wp_json_encode( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>;

			function deriveEmail( domain ) {
				return domain ? 'wordpress@' + domain : '';
			}

			var lastAutoEmail = emailInput.value === deriveEmail( domainInput.value.trim() ) ? emailInput.value : null;

			function sync() {
				var domain = domainInput.value.trim();

				if ( nameInput && ! nameInput.value ) {
					nameInput.value = siteName;
				}

				if ( domain && ( ! emailInput.value || emailInput.value === lastAutoEmail ) ) {
					lastAutoEmail = deriveEmail( domain );
					emailInput.value = lastAutoEmail;
				}
			}

			domainInput.addEventListener( 'input', sync );
			sync(); // also fill in on load if the domain is already saved but the email isn't
		} )();

		( function () {
			var btn  = document.getElementById( 'wpel_copy_webhook' );
			var code = document.getElementById( 'wpel_webhook_url' );
			if ( ! btn || ! code ) {
				return;
			}

			var defaultLabel = btn.textContent;

			function showCopied() {
				btn.textContent = 'Copied!';
				setTimeout( function () {
					btn.textContent = defaultLabel;
				}, 1500 );
			}

			function fallbackCopy( text ) {
				var temp = document.createElement( 'textarea' );
				temp.value = text;
				temp.style.position = 'fixed';
				temp.style.opacity  = '0';
				document.body.appendChild( temp );
				temp.select();
				try {
					document.execCommand( 'copy' );
					showCopied();
				} catch ( e ) {}
				document.body.removeChild( temp );
			}

			btn.addEventListener( 'click', function () {
				var text = code.textContent;
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text ).then( showCopied, function () {
						fallbackCopy( text );
					} );
				} else {
					fallbackCopy( text );
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Sidebar checklist for the Settings page: the steps to redo every time
	 * this plugin lands on a new site, with live checkmarks for whatever can
	 * be verified from saved settings/wp-config constants, plus two steps
	 * (webhook registration, a successful test send) confirmed via
	 * get_setup_status() since they can only be proven by actually doing
	 * them — see the domain-sharing note in README.md and the "Sharing
	 * defaults across sites" section for the reasoning behind the domain step.
	 */
	private function render_setup_checklist( $o ) {
		$status          = $this->get_setup_status();
		$has_api_key     = ! empty( $o['api_key'] );
		$has_domain      = ! empty( $o['domain'] );
		$has_from_email  = ! empty( $o['from_email'] );
		$has_signing_key = ! empty( $o['signing_key'] ) || defined( 'WPEL_MAILGUN_SIGNING_KEY' );
		$has_webhook     = ! empty( $status['webhook_verified'] );
		$has_alerts      = ! empty( $o['alert_email'] ) || ! empty( $o['twilio_to_numbers'] );
		$has_test_email  = ! empty( $status['test_email_sent'] );
		// Each Twilio credential can come from the Twilio / SMS tab or its
		// matching wp-config.php constant — see get_twilio_credentials() in
		// class-wpel-monitor.php for the same override precedence applied there.
		$twilio_ready    = ( ! empty( $o['twilio_account_sid'] ) || defined( 'WPEL_TWILIO_ACCOUNT_SID' ) )
			&& ( ! empty( $o['twilio_sid'] ) || defined( 'WPEL_TWILIO_SID' ) )
			&& ( ! empty( $o['twilio_auth_token'] ) || defined( 'WPEL_TWILIO_AUTH_TOKEN' ) )
			&& ( ! empty( $o['twilio_from_number'] ) || defined( 'WPEL_TWILIO_FROM_NUMBER' ) );
		$all_done        = $has_domain && $has_api_key && $has_from_email && $has_signing_key
			&& $has_webhook && $has_alerts && $has_test_email;
		$done            = '&#9989;';
		$todo            = '&#11036;';
		?>
		<div class="wpel-settings-sidebar">
			<div class="postbox">
				<h2 class="hndle" style="padding:10px 12px;margin:0;font-size:14px;">New site setup checklist</h2>
				<div style="padding:4px 12px 12px;">
					<p class="description">Run through this every time the plugin is installed on a new site.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 12px;">
						<?php wp_nonce_field( 'wpel_check_mailgun_config' ); ?>
						<input type="hidden" name="action" value="wpel_check_mailgun_config">
						<button type="submit" class="button button-secondary"><?php esc_html_e( 'Check Mailgun config', 'wpel' ); ?></button>
						<p class="description" style="margin-top:4px;">Asks Mailgun directly whether the API key/domain are valid and the webhook below is actually subscribed — covers steps 1, 2 and 5.</p>
					</form>
					<ol style="padding-left:18px;">
						<li><?php echo $has_domain ? $done : $todo; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							Create a <strong>dedicated</strong> Mailgun sending domain for this site — never reuse a domain already wired to another site's webhook (see warning below).
							<br><a href="https://app.mailgun.com/mg/sending/new-domain" target="_blank">Add domain &rarr;</a>
						</li>
						<li><?php echo ( $has_api_key && $has_domain ) ? $done : $todo; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							Paste the Mailgun API key and that domain into the <strong>Mailgun Sending</strong> tab.
							<br><a href="https://app.mailgun.com/settings/api_security" target="_blank">Get API key &rarr;</a>
						</li>
						<li><?php echo $has_from_email ? $done : $todo; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							Set a default From name/email on a domain verified in Mailgun, and turn on <strong>Force from address</strong>.
						</li>
						<li><?php echo $has_signing_key ? $done : $todo; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							Set the Mailgun webhook signing key. Unlike the domain, this one is safe to share account-wide via <code>WPEL_MAILGUN_SIGNING_KEY</code> in <code>wp-config.php</code>.
							<br><a href="https://app.mailgun.com/mg/sending/mg.abpdaily.com/webhooks/account-level?tab=account-level" target="_blank">Get signing key &rarr;</a>
						</li>
						<li><?php echo $has_webhook ? $done : $todo; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							In Mailgun, open this domain &rarr; <strong>Webhooks</strong> &rarr; add an HTTP webhook subscribed to <code>accepted</code>, <code>delivered</code>, <code>permanent_fail</code> and <code>opened</code>, pointing at:
							<br><code style="word-break:break-all;display:inline-block;margin:4px 0;"><?php echo esc_html( rest_url( 'wpel/v1/mailgun-webhook' ) ); ?></code>
							<br><a href="https://app.mailgun.com/mg/sending/domains" target="_blank">Open domains &rarr;</a>
							<br><em>Confirmed automatically by <strong>Check Mailgun config</strong> above once it's set up correctly.</em>
						</li>
						<li><?php echo $has_alerts ? $done : $todo; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							Set an alert email on the <strong>Alerting &amp; Logging</strong> tab and/or alert phone number(s) on the <strong>Twilio / SMS</strong> tab.
							<?php if ( ! empty( $o['twilio_to_numbers'] ) && ! $twilio_ready ) : ?>
								<br><strong style="color:#b32d2e;">Phone numbers are set but Twilio isn't configured</strong> — fill in the Account SID, SID, Auth Token and From number on the <strong>Twilio / SMS</strong> tab (or define <code>WPEL_TWILIO_ACCOUNT_SID</code>, <code>WPEL_TWILIO_SID</code>, <code>WPEL_TWILIO_AUTH_TOKEN</code> and <code>WPEL_TWILIO_FROM_NUMBER</code> in <code>wp-config.php</code>, shared account-wide like the signing key) or texts won't send.
							<?php endif; ?>
						</li>
						<li><?php echo $has_test_email ? $done : $todo; // phpcs:ignore WordPress.Security.EscapeOutput ?> Click <strong>Send test email</strong> on the Mailgun Sending tab to confirm the whole pipeline end to end.</li>
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

	public function render_log_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$table = WPEL_Mailgun_Monitor::table();

		$allowed = array( 'pending', 'sent', 'delivered', 'failed', 'temp-fail', 'complained' );
		$filter  = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		$paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per     = 50;
		$offset  = ( $paged - 1 ) * $per;

		// 'opened' is a pseudo-status: a single exclusive tab like the others
		// (not a combinable toggle), so every link in the row replaces the view
		// wholesale instead of ANDing filters together.
		if ( 'opened' === $filter ) {
			$where = ' WHERE open_count > 0';
		} elseif ( in_array( $filter, $allowed, true ) ) {
			$where = $wpdb->prepare( ' WHERE status = %s', $filter );
		} else {
			$where = '';
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}{$where}" );
		$rows  = $wpdb->get_results( "SELECT * FROM {$table}{$where} ORDER BY id DESC LIMIT {$per} OFFSET {$offset}" );

		$counts       = $wpdb->get_results( "SELECT status, COUNT(*) c FROM {$table} GROUP BY status", OBJECT_K );
		$opened_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE open_count > 0" );
		?>
		<div class="wrap">
			<h1>Email Log</h1>
			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpel-log' ) ); ?>" <?php echo '' === $filter ? 'class="current"' : ''; ?>>All</a> |</li>
				<?php foreach ( $allowed as $s ) : ?>
					<li>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpel-log&status=' . $s ) ); ?>" <?php echo $filter === $s ? 'class="current"' : ''; ?>>
							<?php echo esc_html( ucfirst( $s ) ); ?> (<?php echo isset( $counts[ $s ] ) ? (int) $counts[ $s ]->c : 0; ?>)
						</a> |
					</li>
				<?php endforeach; ?>
				<li>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpel-log&status=opened' ) ); ?>" <?php echo 'opened' === $filter ? 'class="current"' : ''; ?>>
						Opened (<?php echo (int) $opened_count; ?>)
					</a>
				</li>
			</ul>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:60px">ID</th>
						<th style="width:150px">When</th>
						<th>Recipient</th>
						<th>Subject</th>
						<th style="width:100px">Status</th>
						<th style="width:130px">Opens</th>
						<th>Detail</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7">No entries.</td></tr>
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
							<td>#<?php echo (int) $r->id; ?></td>
							<td><?php echo esc_html( $r->created_at ); ?></td>
							<td><?php echo esc_html( $r->recipient ); ?></td>
							<td><?php echo esc_html( $r->subject ); ?></td>
							<td><span style="color:#fff;background:<?php echo esc_attr( $color ); ?>;padding:2px 8px;border-radius:3px;font-size:11px"><?php echo esc_html( $r->status ); ?></span></td>
							<td><?php
							if ( $r->open_count > 0 ) {
								printf(
									'<span title="%1$s">%2$s&times; (first %3$s)</span>',
									esc_attr( 'Last opened: ' . $r->last_opened_at ),
									(int) $r->open_count,
									esc_html( $r->first_opened_at )
								);
							} else {
								echo '&mdash;';
							}
							?></td>
							<td><?php echo esc_html( $r->error_message ? $r->error_message : '' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $total / $per );
			if ( $pages > 1 ) {
				$base = admin_url( 'admin.php?page=wpel-log' . ( $filter ? '&status=' . $filter : '' ) . '&paged=%#%' );
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
		</div>
		<?php
	}
}

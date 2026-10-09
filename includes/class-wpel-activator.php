<?php
/**
 * Fired during plugin activation / deactivation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPEL_Activator {

	/**
	 * dbDelta() is safe to re-run: it diffs the given schema against what
	 * exists and ALTERs in any missing columns/keys without touching data.
	 * Both activate() and the plugins_loaded version-check upgrade path
	 * (see maybe_upgrade()) share this so existing installs pick up schema
	 * changes without needing to deactivate/reactivate.
	 */
	public static function create_or_upgrade_table() {
		global $wpdb;
		$table   = WPEL_Mailgun_Monitor::table();
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				from_address VARCHAR(255) NULL,
				recipient TEXT NULL,
				subject TEXT NULL,
				headers LONGTEXT NULL,
				body LONGTEXT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				mailgun_message_id VARCHAR(255) NULL,
				error_message TEXT NULL,
				event_log LONGTEXT NULL,
				open_count INT UNSIGNED NOT NULL DEFAULT 0,
				first_opened_at DATETIME NULL,
				last_opened_at DATETIME NULL,
				unopened_alerted TINYINT UNSIGNED NOT NULL DEFAULT 0,
				source VARCHAR(191) NULL,
				source_page BIGINT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				KEY status (status),
				KEY created_at (created_at),
				KEY mailgun_message_id (mailgun_message_id),
				KEY unopened_check (status, unopened_alerted, created_at),
				KEY source (source)
			) {$charset};"
		);
	}

	/**
	 * Runs on every plugins_loaded, before wpel_boot(). Cheap no-op after the
	 * first request following an update (single option read + string compare).
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'wpel_db_version' ) === WPEL_VERSION ) {
			return;
		}
		self::create_or_upgrade_table();
		update_option( 'wpel_db_version', WPEL_VERSION, false );
		self::ensure_cron_scheduled();
		self::drop_alert_unopened();
	}

	/**
	 * Unopened alerts used to have their own checkbox (alert_unopened); they
	 * now just follow SMS alerts + open tracking. On a site that had the
	 * checkbox off, skip the emails already in the log, so the first run
	 * after updating doesn't text about every old unopened email at once.
	 */
	private static function drop_alert_unopened() {
		$o = get_option( WPEL_OPTION, array() );
		if ( ! is_array( $o ) || ! array_key_exists( 'alert_unopened', $o ) ) {
			return;
		}
		if ( empty( $o['alert_unopened'] ) ) {
			WPEL_Mailgun_Monitor::instance()->skip_unopened_backlog();
		}
		unset( $o['alert_unopened'] );
		update_option( WPEL_OPTION, $o );
	}

	private static function ensure_cron_scheduled() {
		if ( ! wp_next_scheduled( 'wpel_prune_logs' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpel_prune_logs' );
		}
		if ( ! wp_next_scheduled( 'wpel_check_unopened' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'wpel_check_unopened' );
		}
	}

	public static function activate() {
		self::create_or_upgrade_table();
		update_option( 'wpel_db_version', WPEL_VERSION, false );
		self::ensure_cron_scheduled();

		// Seed default settings if empty.
		$defaults = array(
			// Mailgun API sending (replaces WP Mail SMTP / any other SMTP plugin).
			// The webhook signing key comes from wp-config.php instead — see
			// wpel_shared_credential().
			'sending_enabled'  => 1,
			'api_key'          => '',
			'domain'           => '',
			// Blank = resolved at send time (Site Title; SMTP login or admin
			// email), so values don't carry over from the boilerplate to clones.
			'from_email'       => '',
			'from_name'        => '',
			'force_from_name'  => 1,
			'force_from_email' => 1,
			'track_opens'      => 0,
			// SMTP fallback, used when the API above isn't set up. Shares the
			// From name/email above.
			'smtp_host'        => 'smtp.mailgun.org',
			'smtp_port'        => 587,
			'smtp_encryption'  => 'tls',
			'smtp_username'    => '',
			'smtp_password'    => '',
			// Logging + alerting.
			'alert_email'      => get_option( 'admin_email' ),
			'slack_webhook'    => '', // saved but currently unused -- Slack alerting is disabled and hidden
			// SMS via Twilio. sms_enabled is deliberately not seeded: a missing
			// key means "on if numbers are set" (see WPEL_Mailgun_Monitor::sms_enabled()),
			// which keeps texts going on sites upgraded from before the toggle.
			'twilio_account_sid' => '',
			'twilio_sid'         => '',
			'twilio_auth_token'  => '',
			'twilio_from_number' => '',
			'twilio_to_numbers'  => '',
			'retention_days'   => 30,
			'alert_temp_fail'  => 0,
			'outage_threshold' => 5,
			'outage_window'    => 15, // minutes
			// Unopened alerts run whenever track_opens is on and the channel
			// ('sms', 'email' or 'both') can deliver; texts need SMS alerts on.
			'unopened_hours'   => 24,
			'unopened_channel' => 'sms',
			// Second text for emails still unopened unopened_second_hours
			// after sending (always later than unopened_hours).
			'unopened_second'       => 0,
			'unopened_second_hours' => 48,
			// Sources unopened alerts are limited to (source => page id, 0 =
			// any page); see WPEL_Sources. Empty = no unopened alerts.
			'unopened_watch'   => array(),
		);
		$existing = get_option( WPEL_OPTION, array() );
		if ( is_array( $existing ) && isset( $existing['force_from'] ) ) {
			// Carry the old combined "Force from address" over to both halves,
			// rather than letting the defaults above switch it back on.
			foreach ( array( 'name', 'email' ) as $which ) {
				$existing[ 'force_from_' . $which ] = wpel_force_from( $existing, $which ) ? 1 : 0;
			}
			unset( $existing['force_from'] );
		}
		update_option( WPEL_OPTION, array_merge( $defaults, is_array( $existing ) ? $existing : array() ) );
	}

	public static function deactivate() {
		$ts = wp_next_scheduled( 'wpel_prune_logs' );
		if ( $ts ) {
			wp_unschedule_event( $ts, 'wpel_prune_logs' );
		}
		$ts = wp_next_scheduled( 'wpel_check_unopened' );
		if ( $ts ) {
			wp_unschedule_event( $ts, 'wpel_check_unopened' );
		}
	}
}

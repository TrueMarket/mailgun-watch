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
				PRIMARY KEY (id),
				KEY status (status),
				KEY created_at (created_at),
				KEY mailgun_message_id (mailgun_message_id),
				KEY unopened_check (status, unopened_alerted, created_at)
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
			'sending_enabled'  => 1,
			'api_key'          => '',
			'domain'           => '',
			'from_email'       => get_option( 'admin_email' ),
			'from_name'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'force_from'       => 1,
			'track_opens'      => 0,
			// Logging + alerting.
			'alert_email'      => get_option( 'admin_email' ),
			'slack_webhook'    => '', // saved but currently unused -- Slack alerting is disabled and hidden
			// Twilio Account SID / API key / auth token / from number are wp-config.php
			// constants only (see notify_twilio() in class-wpel-monitor.php).
			'twilio_to_numbers' => '',
			'signing_key'      => '',
			'store_body'       => 0,
			'retention_days'   => 30,
			'alert_temp_fail'  => 0,
			'outage_threshold' => 5,
			'outage_window'    => 15, // minutes
			'alert_unopened'   => 0,
			'unopened_hours'   => 24,
		);
		$existing = get_option( WPEL_OPTION, array() );
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

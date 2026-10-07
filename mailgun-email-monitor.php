<?php
/**
 * Plugin Name: Mailgun Watch
 * Description: Sends all outgoing email directly through the Mailgun HTTP API (no SMTP plugin required), logs every send, reconciles real delivery status via Mailgun webhooks, flags failures, and alerts by email + SMS (Twilio).
 * Version:     2.2.11
 * Author:      True Market
 * Author URI:  https://truemarket.ca
 * License:     GPL-2.0-or-later
 * Text Domain: wpel
 *
 * ------------------------------------------------------------------------
 * NAMING NOTE: this plugin was originally called "Mailgun Email Monitor"
 * (before that, just a "WP Email Log" tool — hence "WPEL"), and was renamed
 * to "Mailgun Watch" for a punchier name. The "wpel" prefix lives on
 * throughout the code (class names, the wpel_settings option, the
 * wpel_email_log DB table, hooks, the wp-json/wpel/v1 REST route, file
 * names) purely for internal/historical reasons — changing it would risk
 * orphaning already-saved settings, logged data, and the webhook URL
 * configured in Mailgun's dashboard, for no user-facing benefit. Only the
 * display name and visible admin/email text were updated.
 * ------------------------------------------------------------------------
 * HOW IT WORKS (read this before relying on it):
 *
 * Self-contained: this plugin IS the Mailgun transport. No WP Mail SMTP or
 * other SMTP plugin is required (or used). Three layers reconcile into a
 * single log table:
 *
 *  1) PRE-SEND CAPTURE  (add_filter 'wp_mail')
 *     Records every wp_mail() call the instant it happens as status=pending.
 *
 *  2) SEND  (add_filter 'pre_wp_mail', see includes/class-wpel-mailer.php)
 *     WPEL_Mailer posts the message straight to Mailgun's HTTP API and
 *     short-circuits wp_mail() with the real result — WordPress's default
 *     PHPMailer/SMTP transport is never invoked. The row from (1) is
 *     immediately updated with Mailgun's assigned message id, or marked
 *     failed (and alerted) on an API-level send failure.
 *
 *     SMTP FALLBACK: when this site has no Mailgun API key/domain yet (or API
 *     sending is switched off), WPEL_Mailer lets wp_mail() carry on through
 *     core's PHPMailer and points it at Mailgun SMTP with the shared
 *     credentials from the Sending tab's SMTP fallback section (set once on the boilerplate,
 *     inherited by cloned sites). The row from (1) is marked sent/failed from
 *     wp_mail_succeeded/wp_mail_failed; no delivery/open tracking, since the
 *     SMTP domain is shared and webhooks are per domain. A failed API send is
 *     never retried over SMTP.
 *
 *  3) WEBHOOK RECONCILE  (Mailgun -> /wp-json/wpel/v1/mailgun-webhook)
 *     The source of truth for the FINAL outcome (delivered vs bounced), which
 *     step 2 can't know because it only sees the API handoff. Mailgun POSTs
 *     signed events; we verify the signature, then UPSERT:
 *       - match by mailgun message-id (stamped in step 2), else
 *       - match a recent unlinked row by recipient+subject (if step 2 didn't
 *         fire, e.g. Mailgun sending was off at send time), else
 *       - create a fresh row from the webhook payload.
 *     Permanent failures (and, optionally, temporary failures) trigger alerts.
 *
 *     OPEN TRACKING (optional, opt-in via Settings): when enabled, Mailgun
 *     embeds a tracking pixel in outgoing HTML mail and fires an `opened`
 *     event (possibly several times) per recipient. This does NOT overwrite
 *     `status` — delivered stays the terminal delivery outcome — it just
 *     bumps open_count/first_opened_at/last_opened_at on the row. Treat it as
 *     a soft signal: plain-text mail has no pixel to track, and privacy
 *     features like Apple Mail Privacy Protection can auto-fetch the pixel on
 *     delivery regardless of whether anyone actually read the email.
 *
 * ALERTING
 *   - When SMS alerts are enabled in Settings, every failure is texted (via
 *     Twilio) to every configured phone number, independent of email — this is what survives a total email outage.
 *   - Flood protection: failures with the same recipient + reason as one
 *     alerted in the last hour are only counted; alert texts are capped at
 *     10 an hour (filter: wpel_sms_max_per_hour); and webhook events for our
 *     own alert emails are ignored, so a bouncing alert address can't feed
 *     back into new alerts. See handle_failure() and apply_sms_limit().
 *     Recipients need nothing but a phone that can receive SMS: no app, no
 *     account, no signup.
 *   - Every failure also attempts an alert email to the configured address.
 *   - If failures pile up with no successful sends in the window, a distinct
 *     "ALL EMAIL MAY BE DOWN" alarm is texted (throttled), because in that
 *     scenario the alert email itself cannot get out.
 *   - UNOPENED SWEEP (optional, opt-in via Settings, requires open tracking
 *     above): an hourly cron (wpel_check_unopened) flags any 'delivered' row
 *     that still has open_count = 0 past a configurable number of hours, and
 *     flags each row once, in one summary text per run (see check_unopened() in
 *     includes/class-wpel-monitor.php). It can be limited to chosen sources,
 *     e.g. one Forminator form's admin notification, optionally only from one
 *     page: every row records what sent it (see includes/class-wpel-sources.php).
 *   - Slack support still exists in the code (notify_slack() in
 *     class-wpel-monitor.php) but every call site is currently commented out
 *     in favor of Twilio SMS. Uncomment them to re-enable it alongside SMS.
 *
 * SETUP CHECKLIST (see the Settings page):
 *   - Define the shared credentials in wp-config.php (same on every site,
 *     not shown in Settings — see wpel_shared_credential()):
 *     WPEL_MAILGUN_SIGNING_KEY.
 *   - Enter this site's Mailgun sending domain (US region only) and a
 *     Mailgun API key created just for this site (so it can be revoked
 *     without affecting other sites).
 *   - Set the default From name/email (must be on a domain verified in Mailgun).
 *   - On the Alerting & Logging tab, set the alert email recipient and/or
 *     tick "Enable SMS alerts" and fill in the Twilio credentials and the
 *     phone number(s) that should receive SMS alerts.
 *   - In the Mailgun dashboard (Send -> Webhooks -> Add webhook ->
 *     Domain-level, pick this site's domain), add a webhook pointing at:
 *       https://YOURSITE/wp-json/wpel/v1/mailgun-webhook
 *     subscribed to at least: accepted, delivered, permanent_fail
 *     (temporary_fail optional; opened required if you enable open tracking
 *     and/or the unopened-email alert).
 *   - Use the "Send test email" button on the Settings page to confirm sending works.
 *
 * FILE LAYOUT
 *   includes/class-wpel-activator.php  Activation/deactivation + schema.
 *   includes/class-wpel-mailer.php     Mailgun API transport, plus the shared Mailgun SMTP fallback.
 *   includes/class-wpel-monitor.php    Capture, webhook reconcile, alerting, log table access.
 *   includes/class-wpel-sources.php    Which form/page/request sent each email.
 *   admin/class-wpel-admin.php         Settings page + email log page.
 * ------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPEL_VERSION', '2.2.11' );
define( 'WPEL_OPTION', 'wpel_settings' );
define( 'WPEL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPEL_FILE', __FILE__ );

/**
 * Credentials that are the same on every site (the Mailgun webhook signing
 * key) live only in wp-config.php and aren't shown in Settings. Falls back to a value saved in Settings by an older version of
 * this plugin, so existing sites keep working until the constant is added.
 * The Mailgun API key is deliberately not one of these: each site gets its
 * own, saved in Settings, so it can be revoked on its own.
 *
 * @param string $constant   e.g. 'WPEL_MAILGUN_SIGNING_KEY'.
 * @param string $legacy_key Matching key in the WPEL_OPTION array.
 * @return string
 */
function wpel_shared_credential( $constant, $legacy_key ) {
	if ( defined( $constant ) && constant( $constant ) ) {
		return trim( (string) constant( $constant ) );
	}
	$o = get_option( WPEL_OPTION, array() );
	return isset( $o[ $legacy_key ] ) ? trim( (string) $o[ $legacy_key ] ) : '';
}

/**
 * Whether "Force from name" / "Force from email" is on. Both used to be one
 * "Force from address" setting (force_from); sites that haven't saved
 * Settings since the split keep whatever that was set to.
 *
 * @param array  $o     WPEL_OPTION array.
 * @param string $which 'name' or 'email'.
 * @return bool
 */
function wpel_force_from( $o, $which ) {
	$key = 'force_from_' . $which;
	return isset( $o[ $key ] ) ? ! empty( $o[ $key ] ) : ! empty( $o['force_from'] );
}

// GitHub repo the update checker reads releases/tags from.
define( 'WPEL_GITHUB_REPO', 'https://github.com/TrueMarket/mailgun-watch/' );

require_once WPEL_PLUGIN_DIR . 'includes/class-wpel-activator.php';
require_once WPEL_PLUGIN_DIR . 'includes/class-wpel-monitor.php';
require_once WPEL_PLUGIN_DIR . 'includes/class-wpel-mailer.php';
require_once WPEL_PLUGIN_DIR . 'includes/class-wpel-sources.php';

register_activation_hook( __FILE__, array( 'WPEL_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPEL_Activator', 'deactivate' ) );

/**
 * Set up automatic updates from the GitHub repository.
 *
 * Uses the Plugin Update Checker library (Yahnis Elsts) to compare the
 * installed version against the latest GitHub release/tag and surface
 * updates on the WordPress Plugins screen.
 */
function wpel_init_updater() {
	$library = WPEL_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

	if ( ! file_exists( $library ) ) {
		return;
	}

	require_once $library;

	$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		WPEL_GITHUB_REPO,
		WPEL_FILE,
		'mailgun-mail-monitor'
	);

	// The branch that holds the stable, production-ready code. On the default
	// branch, PUC prefers GitHub releases/tags over raw branch commits.
	$update_checker->setBranch( 'main' );

	// The GitHub repo is private, so a token is required to read releases/tags.
	// Define this in wp-config.php (fine-grained PAT, "Contents: Read-only",
	// scoped to just this repo) — same pattern as WPEL_MAILGUN_SIGNING_KEY etc.
	if ( defined( 'WPEL_GITHUB_TOKEN' ) && WPEL_GITHUB_TOKEN ) {
		$update_checker->setAuthentication( WPEL_GITHUB_TOKEN );
	}

	// If you attach a built .zip to each GitHub release (rather than letting
	// PUC use the auto-generated source archive), uncomment the next line so
	// updates download that asset instead:
	// $update_checker->getVcsApi()->enableReleaseAssets( '/\.zip($|[?&#])/i' );
}
wpel_init_updater();

// Runs before wpel_boot() (default priority 10) so an updated plugin's schema
// changes land even on sites that never deactivate/reactivate.
add_action( 'plugins_loaded', array( 'WPEL_Activator', 'maybe_upgrade' ), 5 );
add_action( 'plugins_loaded', 'wpel_boot' );

function wpel_boot() {
	WPEL_Sources::instance();
	WPEL_Mailgun_Monitor::instance();
	WPEL_Mailer::instance();

	if ( is_admin() ) {
		require_once WPEL_PLUGIN_DIR . 'admin/class-wpel-admin.php';
		WPEL_Admin::instance();
	}
}

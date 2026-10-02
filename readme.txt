=== Mailgun Watch ===
Contributors: jimlaroche
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Stable tag: 2.2.6

Sends outgoing WordPress email through the Mailgun HTTP API, logs every send, reconciles delivery via Mailgun webhooks, and alerts on failure.

== Description ==

Mailgun Watch replaces WP Mail SMTP (or any other SMTP plugin) as the mail transport, sending directly through Mailgun's HTTP API. Every send is logged and reconciled against Mailgun's webhook events (delivered vs. bounced vs. complained), and failures trigger alerts by email and SMS (Twilio). See README.md in the plugin folder for full setup and configuration details.

== Changelog ==

= 2.2.6 =
* Mailgun API key, webhook signing key and Twilio credentials now come from wp-config.php constants (WPEL_MAILGUN_API_KEY, WPEL_MAILGUN_SIGNING_KEY, WPEL_TWILIO_*) and are no longer shown in Settings
* Existing sites fall back to previously saved Settings values until the constants are defined
* Remove the Twilio / SMS tab; alert phone numbers and the Send test email button move to the Alerting & Logging tab
* Update setup checklist and README for shared credentials and dedicated per-site Mailgun domains

= 2.2.5 =
* Add Twilio/SMS settings tab
* Add SMS test button

= 2.2.4 =
* Add check Mailgun config button

= 2.2.3 =
* Fix alert phone numbers disappearing on save

= 2.2.2 =
* Fix Save Changes redirecting to options.php on the settings page

= 2.2.1 =
* Update plugin description

= 2.2.0 =
* Added automatic update checking against a private GitHub repository, via the Plugin Update Checker library.

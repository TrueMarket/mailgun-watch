=== Mailgun Watch ===
Contributors: jimlaroche
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Stable tag: 2.2.12

Sends outgoing WordPress email through the Mailgun HTTP API, logs every send, reconciles delivery via Mailgun webhooks, and alerts on failure.

== Description ==

Mailgun Watch replaces WP Mail SMTP (or any other SMTP plugin) as the mail transport, sending directly through Mailgun's HTTP API. Every send is logged and reconciled against Mailgun's webhook events (delivered vs. bounced vs. complained), and failures trigger alerts by email and SMS (Twilio). Sites without their own Mailgun domain yet send through a shared Mailgun SMTP fallback, with no delivery or open tracking. See README.md in the plugin folder for full setup and configuration details.

== Changelog ==

= 2.2.12 =
* Added HTML Forms support

= 2.2.11 =
* Added an "Unopened alerts cover" setting that could limit unopened-email alerts to selected sources, such as one Forminator notification, each optionally limited to one page; it was applied when the check ran, so changes also covered emails still waiting out the threshold
* Recorded the From address each email actually went out with (API, SMTP fallback or Mailgun event) and showed it, along with the source, in the Email Log's View modal

= 2.2.10 =
* Split Force from address into separate Force from name and Force from email settings, each shown under its own field, with existing sites keeping their previous value for both
* Grouped repeat failure alerts: failures with the same recipient and reason within an hour of an alert were logged but not alerted again, and the next alert said how many were held back
* Capped alert texts at 10 an hour (filterable via wpel_sms_max_per_hour), with notes when the limit was reached and how many texts were skipped; outage alarms still went through
* Tagged the plugin's own alert emails and ignored their webhook events, so a bouncing alert address could no longer cause an endless alert loop
* Changed the unopened-email check to send one summary text per run instead of one text per email
* Fixed time cutoffs that were off by the site's UTC offset in the unopened check, the matching of webhook events to log entries and log retention
* Added Delete and Resend bulk actions plus per-row Resend/Delete buttons to the Email Log, with confirmation prompts; resent emails were logged as new entries

= 2.2.9 =
* Added SMTP fallback sending through a shared Mailgun SMTP login for sites without their own Mailgun API key/domain (sent/failed logging only, masked password, no retry of failed API sends, webhook events from the shared domain ignored), and showed the active transport on the Settings page and in test email results
* Updated the Sending tab (renamed from Mailgun Sending): merged the From name/email into one setting for both transports, with a blank name using the Site Title, and made Force from address keep the overridden From as the Reply-To
* Added Twilio settings to the Alerting & Logging tab behind an Enable SMS alerts toggle, replacing the wp-config.php constants (still used as a fallback), with a masked auth token, E.164 validation of the phone number and a checklist warning when SMS setup is incomplete
* Saved every logged email's message and removed the store message body setting
* Added a View button to the Email Log that opened the message, headers and event timeline in a modal
* Updated the README and inline docs

= 2.2.8 =
* Updated Mailgun setup instructions

= 2.2.7 =
* Re-added the Mailgun API key field, so each site's key can be revoked on its own
* Added "Create a Mailgun API key" step to the setup checklist; Check Mailgun config now validates the per-site key

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

=== Form Alert ===
Contributors: jimlaroche
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Stable tag: 2.2.18

Sends outgoing WordPress email through the Mailgun HTTP API, logs every send, reconciles delivery via Mailgun webhooks, and alerts on failure.

== Description ==

Form Alert replaces WP Mail SMTP (or any other SMTP plugin) as the mail transport, sending directly through Mailgun's HTTP API. Every send is logged and reconciled against Mailgun's webhook events (delivered vs. bounced vs. complained), and failures trigger alerts by email and SMS (Twilio). Sites without their own Mailgun domain yet send through a shared Mailgun SMTP fallback, with no delivery or open tracking. See README.md in the plugin folder for full setup and configuration details.

== Changelog ==

= 2.2.18 =
* Added a Copy link button to the email viewer in the Email Log that copied a shareable link to the open email
* Added the open email's ID to the Email Log URL (?entry=), so shared or bookmarked links reopened that email
* Fixed bulk actions reopening a shared email after the page redirected

= 2.2.17 =
* Rewrote the SMS alerts in plain, client-facing wording
* Added the submitter's email (from the notification's Reply-To) to submission and unopened texts, and dropped the subject and log link from them
* Changed unopened alerts to send one text per form, listing every recipient and submitter, instead of one combined list
* Changed the send-failure text to name the form and page the email came from, rather than how it was being sent
* Renamed the outage text to "Possible email notification outage", with who to contact for website and email help

= 2.2.16 =
* Added a From column to the Email Log that showed the address each email went out from, with the full name and address on hover
* Added a search box to the Email Log that matched the recipient, subject, From address or Mailgun message ID, keeping the selected status tab and source filter
* Changed the default From email derived from the sending domain from wordpress@ to noreply@

= 2.2.15 =
* Added an XML-RPC source, so emails sent through xmlrpc.php were labelled "XML-RPC (xmlrpc.php)" instead of "Front-end page"
* Renamed the "Login / password reset" source to "Login page (wp-login.php)"

= 2.2.14 =
* Renamed the plugin from Mailgun Watch to Form Alert, including the admin menu, alert and test email subjects; failure alerts sent under the old name were still ignored by the webhook
* Added the Form Alert logo to the Settings and Email Log pages
* Added submission texts: with SMS alerts on, each watched email was texted to the alert numbers as soon as it was sent
* Merged the unopened-alert and submission lists into one "Watched emails" list, with forms on the General tab and other sources on Alerting & Logging; existing selections carried over
* Removed the per-page limit on watched emails
* Removed the unopened alerts "Send by" choice; they went by text with SMS alerts on, otherwise by email
* Simplified the General tab: alert phone numbers only showed with SMS alerts on, unopened settings moved under Track opens, and the overview was rewritten
* Updated the README to match

= 2.2.13 =
* Added a client-facing General tab for alert email, SMS alerts, alert phone numbers and open tracking; Twilio settings stayed on Alerting & Logging and were always shown
* Made unopened-email alerts run whenever open tracking was on, sent by text, email or both, with an optional secondary notice
* Limited unopened alerts to ticked sources only, and skipped older emails when alerts or the secondary notice were turned on

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

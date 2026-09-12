=== Mailgun Watch ===
Contributors: jimlaroche
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Stable tag: 2.2.0

Sends outgoing WordPress email through the Mailgun HTTP API, logs every send, reconciles delivery via Mailgun webhooks, and alerts on failure.

== Description ==

Mailgun Watch replaces WP Mail SMTP (or any other SMTP plugin) as the mail transport, sending directly through Mailgun's HTTP API. Every send is logged and reconciled against Mailgun's webhook events (delivered vs. bounced vs. complained), and failures trigger alerts by email and SMS (Twilio). See README.md in the plugin folder for full setup and configuration details.

== Changelog ==

= 2.2.0 =
* Added automatic update checking against a private GitHub repository, via the Plugin Update Checker library.

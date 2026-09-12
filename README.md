# Mailgun Watch

Sends every outgoing WordPress email directly through the **Mailgun HTTP API** — no WP Mail SMTP or other SMTP plugin required — logs every send, reconciles the real delivery outcome via Mailgun webhooks, flags failures, and alerts by **email + SMS (Twilio)**. SMS is the fallback that still works when the site can't send any email at all — recipients just need a phone number, no app or account required. (Slack support still exists in the code but is currently disabled and hidden from Settings; see [Alerting logic](#alerting-logic).)

> Formerly "Mailgun Email Monitor". The rename is cosmetic only — internally the plugin still uses its original `wpel` prefix (class names, options, DB table, hooks, REST route, file names) for backward compatibility; see the note at the top of `mailgun-email-monitor.php`.

## Install

1. Copy the `mailgun-email-monitor` folder into `wp-content/plugins/`.
2. Activate **Mailgun Watch** in **Plugins**. Activation creates the log table and a daily prune cron.
3. Deactivate WP Mail SMTP (or any other SMTP plugin) — this plugin replaces it as the mail transport. Leaving one active alongside this plugin is harmless (this plugin's `pre_wp_mail` hook wins), but there's no reason to keep it.
4. Go to **Mailgun Watch → Settings** (two tabs: **Mailgun Sending** and **Alerting & Logging**) and fill in:
   - **Mailgun API key** — Mailgun dashboard → *Settings → API Keys → Private API key*
   - **Mailgun sending domain** (US region only)
   - **Default from name/email** — must be on a domain verified in Mailgun, or sends are rejected
   - **Alert email recipient**
   - **Alert phone numbers** — comma-separated E.164 numbers (e.g. `+15551234567`) that should receive SMS alerts. Recipients don't need an app, an account, or to subscribe to anything.
   - **Mailgun webhook signing key** — Mailgun dashboard → *Settings → Webhooks → HTTP webhook signing key*

   Twilio credentials (Account SID, the Basic Auth SID/token pair, and the from number) are **not** on this page — they're wp-config.php constants only, see [Sharing defaults across sites](#sharing-defaults-across-sites).
5. In the **Mailgun dashboard**, add a webhook pointing at the endpoint shown on the Settings page:
   ```
   https://YOURSITE/wp-json/wpel/v1/mailgun-webhook
   ```
   Subscribe it to at least: `accepted`, `delivered`, `permanent_fail` (add `temporary_fail` if you want soft-failure alerts too).
6. Click **Send test email** on the Settings page to confirm the whole pipeline — API send, logging, and webhook reconciliation — works end to end.

## What gets logged

Every send becomes a row with a status that moves through
`pending → sent (accepted) → delivered`, or lands on `failed` / `temp-fail` / `complained`.
View and filter them under **Mailgun Watch → Log**.

## How it works

This plugin **is** the Mailgun transport — it doesn't sit alongside another SMTP plugin, it replaces one. Three layers reconcile into one log:

1. **Pre-send capture** (`wp_mail` filter): records the send the instant it happens, as `status = pending`.
2. **Send** (`pre_wp_mail` filter, `includes/class-wpel-mailer.php`): posts the message straight to Mailgun's HTTP API and short-circuits `wp_mail()` with the real result — WordPress's default PHPMailer/SMTP transport is never invoked. The row from step 1 is immediately updated with Mailgun's assigned message-id, or marked `failed` (and alerted) on an API-level send failure.
3. **Webhook reconcile**: the source of truth for the *final* outcome (delivered vs bounced) — the one thing step 2 can't see, since it only knows the API handoff succeeded. Matches by message-id, falls back to recipient+subject for the rare case where step 2 didn't fire (e.g. Mailgun sending was switched off at send time), and can create a row on its own as a last resort.

## Sending behavior

- **Force from address** (on by default): always sends using the configured default from name/email, regardless of what a plugin/theme sets via `wp_mail()`'s headers. Turn this off if you specifically want per-email From addresses honored — but they must all be on Mailgun-verified domains, or those sends will fail.
- **Send via Mailgun API** toggle (on by default, including on installs that activated before this option existed): an emergency off-switch. When disabled, `wp_mail()` falls through to WordPress's default transport (usually PHP `mail()`, which most hosts don't deliver reliably) — logging and alerting still work via `wp_mail_failed`.
- HTML vs. plain text, Cc/Bcc, Reply-To, and file attachments (via hand-built `multipart/form-data`, since WordPress's HTTP API has no upload helper) are all forwarded to the Mailgun API.

## Alerting logic

- Every failure → **SMS via Twilio** to every configured number (always) **+ alert email** (best effort, itself sent through Mailgun).
- A burst of failures with no successful sends in the window → a distinct **"POSSIBLE TOTAL EMAIL OUTAGE"** text (throttled to one per window). This is the case where the alert email itself can't get out, which is exactly what SMS covers.
- Slack support (`notify_slack()` in `includes/class-wpel-monitor.php`) is still in the code but every call site is currently commented out in favor of Twilio SMS, and the Settings field is hidden (not removed — see `render_settings_page()`). Uncomment the calls (in `handle_failure()`, `notify_outage_alarm()`, and `notify_unopened()`) and un-hide the field to run Slack alongside SMS again — the saved webhook URL, if any, is untouched.

Thresholds, retention, and whether to store message bodies are all on the Settings page.

## Sharing defaults across sites

Mailgun's signing key and Slack's webhook stay optional constants (Settings can override them). **Twilio credentials have no Settings field at all — `wp-config.php` constants are the only way to configure them:**

```php
define( 'WPEL_MAILGUN_SIGNING_KEY', 'account-level HTTP webhook signing key' );
define( 'WPEL_SLACK_WEBHOOK', 'https://hooks.slack.com/services/...' ); // unused while Slack alerting is disabled

// Required for SMS alerts — no Settings-page equivalent for any of these:
define( 'WPEL_TWILIO_ACCOUNT_SID', 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' ); // real Account SID — always goes in the API URL
define( 'WPEL_TWILIO_SID', 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' );        // Basic Auth user: the Account SID above, OR an API Key SID (starts with SK)
define( 'WPEL_TWILIO_AUTH_TOKEN', 'your Twilio auth token or API Key secret' ); // pairs with whichever WPEL_TWILIO_SID you used
define( 'WPEL_TWILIO_FROM_NUMBER', '+15551234567' );                      // E.164, must include the leading +
```

`WPEL_TWILIO_ACCOUNT_SID` must always be the real Account SID from the Twilio Console dashboard (starts with `AC`) — the API path requires it regardless of which credential pair you authenticate with. `WPEL_TWILIO_SID` + `WPEL_TWILIO_AUTH_TOKEN` is whatever you use for Basic Auth: either that same Account SID plus the master Auth Token, or (recommended, since it's independently revocable) an API Key SID (`SK...`) plus its Secret from *Console → Account → API keys & tokens*.

There's no such shortcut for the Mailgun API key/domain, From address, or the Twilio "alert phone numbers" list — those are inherently per-site (each site sends as itself on its own verified domain, and each site's owner wants their own phone texted).

## Notes / limitations

- Webhook calls are rejected unless the Mailgun signature verifies, so the signing key is required.
- `wp_mail_succeeded` / `wp_mail_failed` don't fire natively for Mailgun-sent mail (short-circuiting `pre_wp_mail` skips the rest of core's `wp_mail()`), so third-party plugins that hook those directly won't see Mailgun sends. Monitor still catches failures independently via `WPEL_Mailer::mark_failed_and_alert()`.
- `blocking => false` is used for the Twilio API call so a slow response never stalls a page load.
- On a Twilio trial account, each "to" number must be verified in the Twilio Console before it can receive SMS; upgrade to a paid account to text arbitrary numbers. For any real US SMS volume, Twilio may also require A2P 10DLC brand/campaign registration for the sending number to avoid carrier filtering — low-volume ops alerts to a handful of numbers typically work unregistered, but this is worth checking if messages start getting silently dropped.

## Updates

This plugin ships with the [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) library and checks the private `TrueMarket/mailgun-watch` GitHub repo for new tagged releases, surfacing them on the Plugins screen like any wordpress.org plugin.

Since the repo is private, each site needs a read-only GitHub token to check for updates:

1. Create a fine-grained personal access token at GitHub → Settings → Developer settings → Personal access tokens, scoped to only the `mailgun-watch` repo with **Contents: Read-only** permission.
2. Add it to `wp-config.php`:
   ```php
   define( 'WPEL_GITHUB_TOKEN', 'github_pat_...' );
   ```

To ship a new version: bump `Version:` in the file header and `WPEL_VERSION` together, commit, then tag and push (e.g. `git tag v2.3.0 && git push origin v2.3.0`).

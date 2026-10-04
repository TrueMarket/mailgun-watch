# Mailgun Watch

Sends every outgoing WordPress email directly through the **Mailgun HTTP API** — no WP Mail SMTP or other SMTP plugin required — logs every send, reconciles the real delivery outcome via Mailgun webhooks, flags failures, and alerts by **email + SMS (Twilio)**. SMS is the fallback that still works when the site can't send any email at all — recipients just need a phone number, no app or account required. Until a site has its own Mailgun domain, mail goes out through a shared **Mailgun SMTP fallback** instead, so a new site cloned from the boilerplate can send from day one (see [SMTP fallback](#smtp-fallback)). (Slack support still exists in the code but is currently disabled and hidden from Settings; see [Alerting logic](#alerting-logic).)

> Formerly "Mailgun Email Monitor". The rename is cosmetic only — internally the plugin still uses its original `wpel` prefix (class names, options, DB table, hooks, REST route, file names) for backward compatibility; see the note at the top of `mailgun-email-monitor.php`.

## Install

1. Copy the `mailgun-email-monitor` folder into `wp-content/plugins/`.
2. Activate **Mailgun Watch** in **Plugins**. Activation creates the log table and a daily prune cron.
3. Deactivate WP Mail SMTP (or any other SMTP plugin) — this plugin replaces it as the mail transport. Leaving one active alongside this plugin is harmless (this plugin's `pre_wp_mail` hook wins), but there's no reason to keep it.
4. Add the webhook signing key to `wp-config.php` — see [Shared credentials (wp-config.php)](#shared-credentials-wp-configphp). It's the same on every site, so it isn't in Settings.
5. Go to **Mailgun Watch → Settings** (two tabs: **Sending** and **Alerting & Logging**) and fill in the following. The Sending tab's **SMTP fallback** section is normally already filled in on sites cloned from the boilerplate, so mail works before any of this is done.
   - **Mailgun API key** — create a separate key for each site (Mailgun → *Settings → API Keys*) so one site's key can be revoked without affecting the others. Use a full API key, not a domain sending key.
   - **Mailgun sending domain** (US region only) — a dedicated one for this site
   - **From name/email** (shared by the API and the SMTP fallback) — the email must be on this site's sending domain once it has one; entering the domain switches an SMTP-domain address over to `wordpress@` that domain automatically. Leave the name blank to use the Site Title.
   - **Alert email recipient**
   - **SMS alerts** (optional) — tick **Enable SMS alerts via Twilio** to show the Twilio fields, then fill in:
     - **Twilio Account SID** — the real Account SID from the Twilio Console dashboard (starts with `AC`). The API path always needs it, whichever credentials you authenticate with.
     - **Twilio API Key SID** + **Twilio auth token** — either an API Key SID (`SK...`) plus its Secret from *Console → Account → API keys & tokens* (recommended, since it can be revoked on its own), or leave the API Key SID blank and enter the account's master Auth Token.
     - **Twilio phone number** — the number alerts are sent from.
     - **Alert phone numbers** — comma-separated numbers that should receive SMS alerts. Recipients don't need an app, an account, or to subscribe to anything.

     Turning the toggle off stops all texts but keeps the saved values. Use **Send test SMS** (after saving) to check the setup.
6. In the **Mailgun dashboard**, go to *Send → Webhooks → Add webhook → Domain-level* (not Account-level), pick this site's domain, and point it at the endpoint shown on the Settings page:
   ```
   https://YOURSITE/wp-json/wpel/v1/mailgun-webhook
   ```
   Subscribe it to at least: `accepted`, `delivered`, `permanent_fail` (add `temporary_fail` if you want soft-failure alerts too).
7. Click **Send test email** on the Settings page to confirm the whole pipeline — API send, logging, and webhook reconciliation — works end to end.

## What gets logged

Every send becomes a row with a status that moves through
`pending → sent (accepted) → delivered`, or lands on `failed` / `temp-fail` / `complained`.
View and filter them under **Mailgun Watch → Log**.

## How it works

This plugin **is** the Mailgun transport — it doesn't sit alongside another SMTP plugin, it replaces one. Three layers reconcile into one log:

1. **Pre-send capture** (`wp_mail` filter): records the send the instant it happens, as `status = pending`.
2. **Send** (`pre_wp_mail` filter, `includes/class-wpel-mailer.php`): posts the message straight to Mailgun's HTTP API and short-circuits `wp_mail()` with the real result — WordPress's default PHPMailer/SMTP transport is never invoked. The row from step 1 is immediately updated with Mailgun's assigned message-id, or marked `failed` (and alerted) on an API-level send failure. When the API isn't set up, the send goes through PHPMailer instead (the [SMTP fallback](#smtp-fallback)), and the row is updated from core's `wp_mail_succeeded` / `wp_mail_failed`.
3. **Webhook reconcile**: the source of truth for the *final* outcome (delivered vs bounced) — the one thing step 2 can't see, since it only knows the API handoff succeeded. Matches by message-id, falls back to recipient+subject for the rare case where step 2 didn't fire (e.g. Mailgun sending was switched off at send time), and can create a row on its own as a last resort.

## Sending behavior

- **From name/email**: one setting, used whether a send goes through the Mailgun API or the SMTP fallback. A blank name means the Site Title; a blank email means the SMTP username while on the fallback (otherwise the admin email).
- **Force from address** (on by default): always sends using the configured from name/email, regardless of what a plugin/theme sets via `wp_mail()`'s headers; the address it overrode is kept as the **Reply-To** (unless the email already has one), so replies still reach it. Turn this off if you specifically want per-email From addresses honored — but they must all be on Mailgun-verified domains, or those sends will fail.
- **Send via Mailgun API** toggle (on by default, including on installs that activated before this option existed): an emergency off-switch. When disabled, `wp_mail()` falls through to the SMTP fallback, or to WordPress's default transport (usually PHP `mail()`, which most hosts don't deliver reliably) if that isn't set up either — logging and alerting still work either way.
- HTML vs. plain text, Cc/Bcc, Reply-To, and file attachments (via hand-built `multipart/form-data`, since WordPress's HTTP API has no upload helper) are all forwarded to the Mailgun API.

## SMTP fallback

The **SMTP fallback** section of the Sending tab holds one set of Mailgun SMTP credentials shared by every site (e.g. `smtp.mailgun.org`, port `587`, TLS, `postmaster@mg.truemarket.io`). It's stored in the database, so set it up once on the boilerplate site and every site cloned from it inherits it. On the boilerplate, leave **From name** blank so each site sends under its own Site Title, and either leave **From email** blank (sends as the SMTP username) or set it to another address on the SMTP domain, e.g. `noreply@mg.truemarket.io`.

Which transport a send uses:

1. **Mailgun API**, if this site has its own API key + sending domain and **Send via Mailgun API** is on — full delivery reconciliation and open tracking.
2. **SMTP fallback**, if it has a username + password — sends and logs `sent` / `failed` (failures alert as usual), but there's no delivery confirmation or open tracking, since the SMTP domain is shared between sites and Mailgun webhooks are per domain.
3. WordPress's default transport otherwise.

The settings sidebar shows which one is active, and **Send test email** reports which one it used.

- A failed **API** send is logged and alerted, but never retried over SMTP: a timeout doesn't prove Mailgun rejected the message, so a retry could deliver it twice, and a broken per-site setup should stay visible rather than be quietly papered over.
- SMTP sends use the same From name/email and **Force from address** rules as API sends, including keeping an overridden From as the Reply-To. An `X-Mailgun-Variables` header tags each message with the site's host, so sites are easy to tell apart in Mailgun's logs.
- **Don't add a webhook for the shared SMTP domain** in Mailgun — one site would receive every other site's events. The webhook endpoint ignores events from that domain anyway (unless it's also this site's own API domain).
- A Mailgun SMTP acceptance counts as a success for outage detection (it's the only success signal SMTP sends get); PHP `mail()` returning true doesn't.
- To turn the fallback off, clear its username. Once saved, the password (like the Twilio auth token) shows as dots and is never printed into the page. Leaving the dots alone keeps it.

## Alerting logic

- Every failure → **SMS via Twilio** to every configured number (when SMS alerts are enabled) **+ alert email** (best effort, itself sent through Mailgun).
- A burst of failures with no successful sends in the window → a distinct **"POSSIBLE TOTAL EMAIL OUTAGE"** text (throttled to one per window). This is the case where the alert email itself can't get out, which is exactly what SMS covers.
- Slack support (`notify_slack()` in `includes/class-wpel-monitor.php`) is still in the code but every call site is currently commented out in favor of Twilio SMS, and the Settings field is hidden (not removed — see `render_settings_page()`). Uncomment the calls (in `handle_failure()`, `notify_outage_alarm()`, and `notify_unopened()`) and un-hide the field to run Slack alongside SMS again — the saved webhook URL, if any, is untouched.

Thresholds and retention are on the Settings page. Every logged email's message is saved and can be viewed from the Email Log (**View**), and is deleted with its log entry once the retention period passes.

## Shared credentials (wp-config.php)

Credentials that are the same on every site live only in `wp-config.php` and aren't shown in Settings. Copy the same block into each site's `wp-config.php`:

```php
define( 'WPEL_MAILGUN_SIGNING_KEY', 'account-level HTTP webhook signing key' );
define( 'WPEL_SLACK_WEBHOOK', 'https://hooks.slack.com/services/...' ); // unused while Slack alerting is disabled
```

The setup checklist on the Settings page lists the signing key if it's missing. Sites set up with an older version of this plugin may still have it saved in Settings. That saved value is used as a fallback until the constant is defined; the constant always wins once it's there.

The Twilio credentials are entered in Settings (see the install steps above), not here. Sites that already define `WPEL_TWILIO_ACCOUNT_SID`, `WPEL_TWILIO_SID`, `WPEL_TWILIO_AUTH_TOKEN` or `WPEL_TWILIO_FROM_NUMBER` in `wp-config.php` keep working: each constant is used while its Settings field is blank, and a value saved in Settings wins over it. SMS alerts still have to be enabled in Settings, though sites upgraded with alert phone numbers already saved start out enabled.

The sending domain, From address, and alert email/phone numbers stay in Settings because they're different on each site (each site sends from its own dedicated domain — see the checklist note on why domains can't be shared).

## Notes / limitations

- Webhook calls are rejected unless the Mailgun signature verifies, so the signing key is required.
- `wp_mail_succeeded` / `wp_mail_failed` don't fire natively for Mailgun API sends (short-circuiting `pre_wp_mail` skips the rest of core's `wp_mail()`), so third-party plugins that hook those directly won't see them. Monitor still catches failures independently via `WPEL_Mailer::mark_failed_and_alert()`. SMTP fallback sends go through core's PHPMailer, so both hooks fire normally for those.
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

### Releasing a new version

To ship an update, paste this to Claude Code (fill in the changelog notes), or follow it by hand:

```
Release a new version of this plugin. Bump the Version header and
WPEL_VERSION in mailgun-email-monitor.php together (patch bump unless
I say otherwise). Add a new entry at the top of the == Changelog ==
section in readme.txt containing a concise list of all changes in the past tense.

Show me the list of changes before commiting.

Commit everything, tag the commit vX.Y.Z to match, and push both main
and the tag to origin.
```

The changelog notes matter, not just as documentation — they're what WordPress shows in the "View version X.X details" popup on the Plugins screen, since the update checker reads them straight out of `readme.txt`'s `== Changelog ==` section for whichever tag it's looking at.

That's: bump `Version:` in the file header and `WPEL_VERSION` together, add the changelog entry to `readme.txt`, commit, then tag and push — e.g. `git tag v2.3.0 && git push origin main && git push origin v2.3.0`.

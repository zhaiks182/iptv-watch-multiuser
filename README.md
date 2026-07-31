# IPTV·WATCH

A self-hosted PHP + MySQL backend for monitoring IPTV M3U playlists. It downloads
each provider's M3U on its own schedule, detects added / modified / removed
channels, notifies those changes over Telegram, and can bulk-import them into an
XUI·ONE panel — all exposed through a JSON API consumed by a single-page HTML
dashboard. Multi-user with self-service registration, admin approval, and an
optional Cloudflare Turnstile captcha.

## Table of contents

- [Features](#features)
- [Project structure](#project-structure)
- [Server requirements](#server-requirements)
- [Setting up a LAMP server from scratch](#setting-up-a-lamp-server-from-scratch-ubuntudebian)
- [Installation](#installation)
- [How synchronization works](#how-synchronization-works)
- [Manual M3U upload & auto-categorization](#manual-m3u-upload--auto-categorization)
- [Channel logos](#channel-logos)
- [Authentication & multi-user accounts](#authentication--multi-user-accounts)
- [Cloudflare Turnstile captcha](#cloudflare-turnstile-captcha)
- [Telegram notifications](#telegram-notifications)
- [XUI·ONE integration](#xuione-integration)
- [Deploying updates](#deploying-updates)
- [Security notes](#security-notes)
- [Suggested extensions (not included)](#suggested-extensions-not-included)

## Features

- Per-provider M3U polling on independently configurable intervals; providers
  can be added, edited (name, URL, interval), or deleted individually.
- Channel-level diffing (added / modified / removed) with a searchable change log.
- Failed provider syncs back off progressively instead of retrying at a fixed
  interval forever.
- Telegram notifications, batched into one message per sync run.
- Manual M3U upload with keyword-based auto-categorization for lists whose
  `group-title` is empty or useless — see [Manual M3U upload & auto-categorization](#manual-m3u-upload--auto-categorization).
- Channel logo search across two public GitHub sources, manual assignment,
  category-based generic fallback icons, and automatic keyword-based assignment
  (globally or per category) — see [Channel logos](#channel-logos).
- XUI·ONE integration: saved panel connections, category/bouquet management,
  concurrent bulk import of a whole category, and optional Low Latency
  On-Demand (LLOD) assignment.
- Multi-user accounts with `admin` / `user` roles, self-service registration, and
  admin approval before a new account can log in.
- Every user's providers, channels, XUI connections, and Telegram settings are
  private to that account.
- Optional Cloudflare Turnstile captcha, shared between registration and login,
  enabled automatically as soon as both API keys are configured.
- Temporary account lockout after repeated failed login attempts.
- `.zip`/`.m3u` export of the whole catalog, a single provider, a category, or a
  single channel.

## Project structure

```
iptv-watch-multiuser/
├── sql/schema.sql              MySQL schema
├── config.example.php          DB credentials template
├── includes/
│   ├── db.php                  PDO connection
│   ├── auth.php                 PHP session / login / logout / role & status checks
│   ├── Crypto.php                AES-256-GCM encryption for recoverable secrets
│   │                              (XUI·ONE session password, Telegram bot token,
│   │                              Turnstile secret key)
│   ├── Turnstile.php             Shared Cloudflare Turnstile verification helper,
│   │                              used by both registration and login
│   ├── M3uParser.php            M3U / M3U Plus playlist parser (name, tvg-id,
│   │                              group-title, tvg-logo)
│   ├── AutoCategorizer.php      Keyword-based category guesser for the manual
│   │                              M3U upload (see below)
│   ├── GithubLogos.php          Channel logo search against github.com/tv-logo/
│   │                              tv-logos, plus the generated generic-icon
│   │                              fallback (see Channel logos below)
│   ├── IptvOrgLogos.php         Second channel logo source, github.com/iptv-org/
│   │                              database (see Channel logos below)
│   ├── Sync.php                 Synchronization and channel-diff logic
│   ├── Telegram.php             Sends Telegram notifications when a provider
│   │                              detects real changes
│   ├── XuiClient.php            Shared HTTP client for the XUI·ONE API (includes
│   │                              batched execution with adaptive concurrency)
│   └── XuiSession.php           Web-session automation for the XUI·ONE panel
│                                  (server/on-demand, LLOD assignment)
├── api/
│   ├── bootstrap.php           Headers, JSON helpers, enforces an active session
│   ├── login.php                 POST log in (username/password + optional captcha)
│   ├── logout.php                POST log out
│   ├── register.php              POST self-service account creation (starts as
│   │                              "pending"); IP rate limiting, honeypot, minimum
│   │                              fill time, and optional captcha
│   ├── me.php                    GET current session identity (username, role)
│   ├── admin_users.php           Account administration — admin only (list,
│   │                              create, edit, approve, disable, delete)
│   ├── app_settings.php          GET (public) / POST (admin) global app config —
│   │                              currently just the Turnstile keys
│   ├── change_password.php       POST change password (requires the current one)
│   ├── providers.php            GET list / POST create, update, or delete a
│   │                              provider (all scoped to the logged-in user;
│   │                              deleting cascades to its channels and change
│   │                              history, but not to shared categories)
│   ├── provider_sync.php        POST sync one provider or all of them
│   ├── channels.php              GET channels grouped by category (supports
│   │                              ?provider_id=)
│   ├── changes.php               GET change log / POST mark as read
│   ├── stats.php                 GET dashboard-wide statistics
│   ├── export.php                GET download a .zip (all / ?provider_id=), a
│   │                              single category's .m3u (?category_id=), or a
│   │                              single channel's .m3u (?channel_id=)
│   ├── reset_database.php        POST wipes channel/provider data for the current
│   │                              user (requires confirm:true)
│   ├── m3u_upload.php            Manual M3U upload: preview (parse + suggest
│   │                              categories) and confirm (import via Sync)
│   ├── logo_search.php           Channel logo search/assign (both sources),
│   │                              generic-fallback fill-in, and keyword-based
│   │                              auto-assign (global or per category)
│   ├── xui_panels.php            CRUD for saved XUI·ONE panel connections
│   ├── xui_test.php              POST read-only tester against an XUI·ONE panel
│   ├── xui_resources.php         GET hardware resources (CPU/RAM/disk) of the
│   │                              active XUI·ONE panel
│   ├── xui_import.php            "XUI·ONE categories" module: create/reorder/
│   │                              delete categories, find uncategorized channels
│   ├── xui_bouquets.php          "XUI·ONE bouquets" module: create/list/delete
│   ├── xui_channels.php          Create/rename/delete individual XUI·ONE streams
│   ├── xui_bulk_import.php       Bulk-imports a whole local category into XUI·ONE
│   └── telegram_settings.php     GET status / POST test, save, delete the
│                                  Telegram integration
├── cron/check_providers.php     Crontab entry point (automatic sync)
├── assets/generic-logos/        Pre-generated fallback icons (one per broad
│                                  category bucket + a default), served directly
│                                  over HTTP — see Channel logos below
├── uploads/                     Created at runtime, blocked from direct HTTP
│   ├── cache/                     access by install.sh (Apache `Require all
│   │                                denied`): cached logo-source indexes and
│   └── m3u/                       reconstructed M3U files from manual uploads
├── iptv-watch-dashboard.html    Frontend, already wired to the API (installed as
│                                  index.html — see Deploying updates below)
├── login.html                   Login page (same visual design as the dashboard)
├── register.html                 Self-service registration page
├── logo.png                     Brand logo shown in the header (static asset)
└── install.sh                   Guided installer (prompts for install path, DB
                                    name/user, and the first admin account)
```

## Server requirements

- Linux (Ubuntu/Debian recommended — this guide uses `apt`, `a2enmod`, and
  `systemctl`; on Nginx the Apache-specific steps don't apply, but the rest does).
- Apache with `mod_php` or PHP-FPM, or Nginx + PHP-FPM.
- PHP 7.4+ with these extensions (all of them are actually used in the code, this
  isn't a generic list):
  - `pdo_mysql` — database connection (`includes/db.php`).
  - `curl` — every call to the XUI·ONE API (including adaptive-concurrency
    parallel execution) and Telegram notifications (`includes/XuiClient.php`,
    `includes/XuiSession.php`, `includes/Telegram.php`) and Cloudflare Turnstile
    verification (`includes/Turnstile.php`) — without this extension those
    features fail with "Call to undefined function curl_init()".
  - `openssl` — encrypts the XUI·ONE session password, the Telegram bot token,
    and the Turnstile secret key (`includes/Crypto.php`, AES-256-GCM).
  - `zip` — generates the "Export" `.zip` (`api/export.php`).
  - `mbstring` — case-insensitive text comparison during bulk import
    (`api/xui_bulk_import.php`).
  - **PHP-CLI** (the `php-cli` package or equivalent, in addition to the Apache
    module or PHP-FPM) — needed by the cron job (`cron/check_providers.php`) and
    by `install.sh` itself (generates the encryption key and the admin password
    hash via `php -r`).
- MySQL or MariaDB — the schema (`sql/schema.sql`) is compatible with MySQL 5.7+
  / MariaDB 10.2+ (no JSON columns, `CHECK` constraints, or window functions).
- **PHP and MySQL don't need matching timezones.** All scheduling math for
  provider syncs (`includes/Sync.php`) is computed with MySQL's own `NOW()`/
  `DATE_ADD()`, never with PHP's `date()`/`time()` — so a mismatched
  `date.timezone` in `php.ini` (a common gotcha on stacks like XAMPP, which
  ships with it hardcoded to `Europe/Berlin` regardless of where the server
  actually is) can't desync "next check" from what the cron compares against.
- Root (or sudo) access to create the database and the cron entry.
- `openssl` as a **CLI command** (normally already present on Ubuntu/Debian) —
  `install.sh` uses it to generate a random database password if you don't type
  one.
- `install.sh` runs `mysql -u root` **without a password**: this works as-is on a
  freshly installed MySQL/MariaDB (OS-level `unix_socket`/`auth_socket`
  authentication for root). If MySQL's root already has its own password, the
  script stops with "Access denied" (it never leaves things half-done) — in that
  case, edit the `mysql -u root` calls in the script or export it as `MYSQL_PWD`
  before running it.

## Setting up a LAMP server from scratch (Ubuntu/Debian)

If you're starting from a clean server, here's the full process before running
`install.sh`:

1. **Update the system**
   ```bash
   sudo apt update && sudo apt upgrade -y
   ```

2. **Install Apache**
   ```bash
   sudo apt install -y apache2
   sudo systemctl enable --now apache2
   ```

3. **Install MariaDB**
   ```bash
   sudo apt install -y mariadb-server
   sudo systemctl enable --now mariadb
   sudo mysql_secure_installation
   ```
   In the wizard, if asked about a root password: leave it unset (keep
   `unix_socket` auth) so `install.sh` can connect as root without a password —
   that's what the script expects. Answer "Yes" to the rest of the prompts
   (remove anonymous users, disable remote root login, remove the `test`
   database).

4. **Install PHP and the required extensions**
   ```bash
   sudo apt install -y php php-cli php-fpm php-mysql php-curl php-zip php-mbstring php-xml
   ```
   `php-mysql` brings in `pdo_mysql`; `openssl` ships with PHP's base package on
   Ubuntu/Debian, no separate install needed.

5. **Connect PHP to Apache** (pick one)
   - `mod_php` (simpler):
     ```bash
     sudo apt install -y libapache2-mod-php
     sudo a2enmod php8.3   # match whatever version apt installed
     sudo systemctl restart apache2
     ```
   - PHP-FPM (recommended in production):
     ```bash
     sudo a2enmod proxy_fcgi setenvif
     sudo a2enconf php8.3-fpm   # match whatever version apt installed
     sudo systemctl restart apache2
     ```

6. **Verify the installed version and extensions**
   ```bash
   php -v
   php -m | grep -Ei 'pdo_mysql|curl|openssl|zip|mbstring'
   ```
   All 5 should show up. If one is missing: `sudo apt install php-<extension>`
   and restart Apache/PHP-FPM.

7. **Verify Apache actually serves PHP**
   ```bash
   echo "<?php phpinfo();" | sudo tee /var/www/html/info.php > /dev/null
   curl -s http://localhost/info.php | grep -i "PHP Version"
   sudo rm /var/www/html/info.php
   ```

With Apache, MariaDB, and PHP running and the extensions verified, continue with
the **Installation** section below (`./install.sh`).

## Installation

1. Upload this whole folder to the server (e.g. `scp -r iptv-watch-multiuser root@YOUR_IP:/root/`),
   or upload a `.zip` of it and `unzip` it there.
2. SSH in and `cd` into the folder.
3. Run:
   ```bash
   chmod +x install.sh
   ./install.sh
   ```
4. Answer the prompts (install directory, DB name, DB user, DB password, first
   admin username/password). By default it installs straight into
   `/var/www/html`. The script then:
   - Copies `api/`, `includes/`, `cron/`, and the HTML files (dashboard as
     `index.html`, plus `login.html` and `register.html`) and `logo.png` into
     the chosen directory.
   - Creates the MySQL database and user, scoped to that database.
   - Imports `sql/schema.sql`.
   - Fixes file ownership and permissions (`www-data` or `apache`, whichever
     exists).
   - Creates the first account as `role='admin'`, `status='approved'` — every
     other account created afterward (via self-service registration or by an
     admin) starts as `status='pending'` until an admin approves it.
   - Adds a crontab entry: `* * * * *` (every minute) running
     `cron/check_providers.php`, which syncs any provider whose interval has
     elapsed.
   - Attempts to enable gzip compression for `api/`'s JSON responses.
   - Locks down direct HTTP access to `config.php` at the Apache level, and
     verifies it with a real HTTP request.

5. Make sure a VirtualHost/DocumentRoot points at the chosen directory, or copy
   the contents inside your existing `DocumentRoot` if you'd rather serve it
   from a subfolder.

6. If you're exposing it under your own domain, set up HTTPS with Let's
   Encrypt/certbot (see [Security notes](#security-notes) below) — without it,
   login credentials travel over plain HTTP.

7. Open the panel in a browser, log in with the admin account you just created,
   and add your first provider (name + M3U URL + interval). Click "Save &
   analyze", then "Sync now" for the first pull (no need to wait for the cron).

## How synchronization works

`Sync::syncProvider()`:

1. Downloads the provider's M3U (`file_get_contents` with a 25s timeout).
2. Parses every `#EXTINF` entry (name, `tvg-id`, `group-title`, `tvg-logo`) and
   its stream URL.
3. Computes the channel's **identity**: if the provider supplies a non-empty
   `tvg-id`, it uses `sha256("tvgid:<tvg-id>")`; otherwise it falls back to
   `sha256("url:<stream_url>")`.
4. Compares against the channels already stored (active) for that provider, by
   identity:
   - New identity → channel **added**.
   - Existing identity, but name/category/logo/URL/tvg-id changed → **modified**
     (a single event per channel per sync run, detailing exactly which fields
     changed).
   - Identity existed before but is missing from this pass → **removed**
     (soft-delete, `status='removed'`, kept in history).
   - A `removed` channel that reappears with the same identity is reactivated
     (never re-inserted, to avoid colliding with the identity's unique
     constraint).
5. Every never-seen-before `group-title` creates a category and logs a
   `category_added` event exactly once, for the lifetime of that category.
6. Every event lands in `channel_changes` and feeds the change log, the
   "NEW"/"MODIFIED"/"NEW CATEGORY" badges, and the pending-changes counters.
   The "Mark as read" button flags everything as read (`is_read = 1`): the
   badges disappear but the history stays in the database.
7. Updates `next_check_at` by adding the provider's configured interval. If the
   download fails, it stores the error in `providers.last_error` and schedules
   a retry using **progressive backoff**: `providers.consecutive_failures` is
   incremented and the next attempt is delayed `5 * 2^(failures - 1)` minutes,
   capped at 240 minutes (4h) — 5, 10, 20, 40, 80, 160, then always 240. A
   provider that just broke retries soon; one that's been down for days stops
   hammering it every 5 minutes. A successful sync resets the counter to 0.
8. If the provider **had already been synced before** and this run detected
   real changes, it notifies via Telegram (see below). A newly added
   provider's first sync never notifies — every channel is marked "added"
   because there's nothing to compare against yet, and notifying that would
   flood the chat with hundreds of channels at once.

**Known limitation:** if a provider never supplies a `tvg-id`, its channels'
identity depends on the stream URL. If that URL changes for such a channel, it
will show up as a removal + addition — there's no other reliable way to
recognize "this is the same channel" without a stable identifier.

## Manual M3U upload & auto-categorization

Some M3U lists have an empty or useless `group-title` on every entry (e.g. `-`),
which would otherwise dump every channel into a single "Otros" category. The
"Subir M3U" button (`api/m3u_upload.php`) handles this with a two-step flow:

1. **Preview** (`action:'preview'`): parses the pasted/uploaded content
   (`includes/M3uParser.php`) and, for every channel whose `group-title` is
   blank or a known placeholder (`-`, "sin categoría", "n/a", etc.), guesses a
   category via `includes/AutoCategorizer.php` — a keyword list matched by
   *whole word* against the channel name (e.g. "deportes"/"sport"/"nba" →
   Sports; the plural/singular Spanish forms are both listed explicitly, since
   `\b`-anchored word matching doesn't treat "deporte" as a substring of
   "deportes"). Nothing is written to the database at this step — the user can
   review and override any suggested category in the browser first, per
   channel or per whole category group.
2. **Confirm** (`action:'confirm'`): rebuilds a standard M3U from the
   (possibly edited) list, saves it under `uploads/m3u/` (outside direct HTTP
   reach), creates a `file://`-backed provider pointing at it, and reuses
   `Sync::syncProvider()` for the actual import — so a manually uploaded list
   goes through the exact same identity/diff/category logic as any HTTP
   provider, instead of duplicating that logic here.

`AutoCategorizer::RULES` covers the most common channel types but isn't
exhaustive — channels that don't match any keyword fall back to "Otros".
Widening the keyword lists is the natural way to improve auto-categorization
over time; there's currently no way to bulk-recategorize channels *after*
they've been imported (only at upload preview time).

## Channel logos

Every channel with no logo shows a small 🖼️ button that opens a search modal.
Three complementary mechanisms fill in logos, roughly in order of quality:

**1. Search & manually assign.** The modal searches two independent, cached
public sources at once:

- **`github.com/tv-logo/tv-logos`** (`includes/GithubLogos.php`) — ~10.8k
  PNG/SVG files, one per channel per country, all served directly from
  `raw.githubusercontent.com`. The full file tree is fetched once (GitHub's
  Trees API, no token needed) and cached locally as a flat path list;
  searches after that are 100% local string matching, no repeated GitHub
  calls. Filenames follow `<slug>-<country-code>.<ext>` — for 2-letter search
  words, matching is restricted to the part of the filename *before* that
  country-code suffix, otherwise a short query like "ID" would match every
  Indonesian logo (`*-id.png`) instead of an actual channel.
- **`github.com/iptv-org/database`** (`includes/IptvOrgLogos.php`) — a much
  larger community database (~43k logos after filtering to `in_use=TRUE`
  rows), built by joining its `channels.csv` (id → name/country) and
  `logos.csv` (id → image URL) once and caching the join. Unlike the first
  source, these images live on many different external hosts (imgur,
  provider CDNs, Wikimedia, etc.) that aren't under this project's control —
  some (Wikimedia in particular) are known to rate-limit non-browser
  requests, so **validation is deliberately client-side**: each result
  thumbnail removes itself from the list if the browser's own `<img>` fails
  to load, rather than the server trying to pre-validate tens of thousands of
  external URLs (which was tested and found impractical).

Both indexes are refreshed together with one button in the modal. Picking a
result sets `channels.logo_url` and `channels.logo_manual = 1` — that column
is what tells `Sync::syncProvider()` to leave the logo alone on the next sync
instead of overwriting it with whatever the provider's own M3U supplies (or
lack thereof).

**2. Generic fallback icons.** "🖼️ Rellenar logos" assigns a pre-generated,
solid-color icon (`assets/generic-logos/<bucket>.png`, one per broad category
bucket: sports, movies, series, documentaries, kids, news, music, adults, plus
a default) to every channel that still has no logo, based on a keyword match
against the channel's *category* name (same word-boundary approach as
`AutoCategorizer`). It also sets `logo_manual = 1`, so it's a real (if
generic) resolution, not a placeholder that gets silently wiped later.

**3. Automatic keyword assignment.** "🎯 Auto-asignar" (globally, or per
category via the small 🎯 next to each category's download button) searches
every channel with no logo *or* still on a generic fallback icon by its own
name, through both sources above, and assigns the best result automatically
— but only when **every word** of the channel name matched (no partial
matches, since there's no human reviewing the pick). Channels without a
confident match are left untouched. **Known limitation:** short or generic
channel names (e.g. "TVN", "Capital") carry no country context, so the
auto-picked result may be a different country's variant of that channel than
the one actually being monitored — worth spot-checking after a bulk run.

## Authentication & multi-user accounts

The panel requires logging in (`login.html`) before viewing any data or using
any `api/` endpoint, and supports multiple independent accounts.

- **Roles**: `admin` and `user`. A `user` account only ever sees and manages its
  own providers, channels, XUI·ONE connections, and Telegram settings. An
  `admin` can additionally manage accounts (see below), but — by design — an
  admin does **not** get access to other users' private data through the
  account-administration screen.
- **Account status**: `pending`, `approved`, or `disabled`. Only `approved`
  accounts can log in.
- **Self-service registration** (`register.html` → `api/register.php`): anyone
  can create an account, but it starts as `pending` and can't log in until an
  admin approves it. Anti-spam protections, applied in order:
  1. **IP rate limit** — max 5 registration attempts per hour per IP (table
     `registration_attempts`), always on, no toggle.
  2. **Honeypot** — a `website` field hidden with CSS that a human never fills,
     but a bot that fills every input does. If it comes back non-empty, the
     server responds with the same generic success message without creating
     the account, so the bot isn't tipped off.
  3. **Minimum fill time** — if the form is submitted less than 3 seconds after
     it finished loading, it's treated the same as the honeypot case (silent
     fake success).
  4. **Captcha** (optional, see [below](#cloudflare-turnstile-captcha)) — a
     failed captcha *does* return a real error, since someone who fails it
     deserves to know they need to retry; it isn't necessarily a bot.
- **Account administration** (`api/admin_users.php`, admin only): list, create,
  edit, approve, disable, or delete accounts. An admin account can never be
  deleted directly (demote it to `user` first if you really need to remove it),
  and the last remaining admin can't be demoted, disabled, or deleted — the
  panel always keeps at least one usable admin.
- **First account**: `install.sh` prompts for an admin username/password and
  creates it as `role='admin'`, `status='approved'` while importing the schema.
  To add or reset a user by hand:
  ```bash
  php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_DEFAULT);"
  mysql -u root YOUR_DB_NAME -e "INSERT INTO users (username, password_hash, role, status) VALUES ('username', 'HASH_FROM_ABOVE', 'admin', 'approved') ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash);"
  ```
- **Deleting a user cascades**: removing an account (via `admin_users.php`)
  takes its providers, channels, XUI·ONE connections, and Telegram settings
  with it (foreign-key cascade).
- **Login lockout**: after 5 failed login attempts for the same username within
  15 minutes, that username is temporarily locked out (`login_attempts` table)
  — further attempts get a 429 response even with the correct password, until
  the window passes. Only actual wrong-password failures count, not rejections
  because the account is `pending`/`disabled`; a successful login clears the
  counter immediately. This is keyed by the submitted username string, not a
  user id, so it doesn't leak whether the account exists.
- **Not included**: password recovery by email — see
  [Suggested extensions](#suggested-extensions-not-included).

## Cloudflare Turnstile captcha

An optional, free captcha layer shared between registration and login,
configurable from the 🛡️ **Account administration** modal in the dashboard.

- **No on/off switch by design.** As soon an admin saves both a Turnstile
  **Site Key** and **Secret Key** (from a site created at
  [dash.cloudflare.com → Turnstile](https://dash.cloudflare.com)), the captcha
  becomes mandatory on *both* registration and login — there's no separate
  toggle to accidentally leave off after configuring the keys. Clearing the
  Site Key and saving disables it again.
- **Verified server-side for real** (`includes/Turnstile.php`, shared by
  `api/register.php` and `api/login.php`): the token is POSTed to Cloudflare's
  `siteverify` endpoint before the request proceeds.
- **The Secret Key is stored encrypted** (`includes/Crypto.php`, same key used
  for the XUI·ONE session password and the Telegram bot token) —
  `api/app_settings.php`'s public `GET` never returns it, only the public Site
  Key and whether a captcha is currently required.
- **Frontend integration** (`login.html`, `register.html`): the Turnstile
  widget is rendered explicitly via `turnstile.render()`, and the token is
  captured through its own `callback`/`expired-callback`/`error-callback`
  rather than read with `turnstile.getResponse()` at submit time — reading it
  synchronously at submit can race with a widget that was just reset after a
  failed attempt, checkmark still visible, token already gone.

## Telegram notifications

Optional module (Telegram-icon button in the header) that notifies via Telegram when a
provider detects real changes — never on its first sync.

- **Setup**: a single row in `telegram_settings` (bot token + chat id). The
  modal includes a step-by-step guide to create the bot with @BotFather and get
  the chat id.
- **The bot token is stored encrypted** (`includes/Crypto.php`, same key as the
  XUI·ONE session) — `api/telegram_settings.php` never returns it, only
  confirms whether one is saved.
- **Validated with a real message before saving**: both "Test connection" and
  "Save" send an actual message to Telegram; if the token or chat id is wrong,
  it's rejected with the reason instead of being saved broken silently.
- **One message per run, not one per channel**: if a sync detects several
  changes at once, a single grouped message is sent (counts by type + up to 15
  channels of detail with their category, "… and N more." if there are more),
  instead of flooding the chat.
- **Over `curl`** (with real timeouts and an automatic retry on connection
  failures) — already a hard dependency of the project, nothing new added.

## XUI·ONE integration

A set of modules (🧩📁📦📺📥 buttons in the header) to connect the panel to an
XUI·ONE server and push detected channels there.

- **Saved connections** (`api/xui_panels.php`): panel URL, Access Code, and API
  Key for the admin API; optionally a panel username/password (stored
  encrypted) for the web session, which is the only way to assign
  server/on-demand to a stream — the `api_key` API accepts those fields but
  never actually persists them. Only one connection can be active at a time,
  and none is saved unless it actually connects (validated with a real call
  before saving, for both the API and the session).
- **Categories** (`api/xui_import.php`): create, reorder (drag & drop), and
  delete `live` categories on the panel; includes a tool to find and delete
  channels left without any assigned category ("No Category" in the panel).
- **Bouquets** (`api/xui_bouquets.php`): create, list, and delete bouquets.
- **Channels** (`api/xui_channels.php`): create, rename, and delete individual
  streams, always resending the fields needed to avoid losing category or
  bouquet membership (XUI·ONE doesn't do partial updates: any field omitted on
  an edit gets cleared).
- **Bulk import** (`api/xui_bulk_import.php`): takes an already-synced local
  category and creates/updates its active channels on XUI·ONE in parallel
  batches with adaptive concurrency (see `includes/XuiClient.php`), cleans up
  the ones the provider already removed, and assigns server/on-demand to
  everything touched in a single pass at the end. Includes automatic
  verification and repair of bouquet membership after the parallel creation
  step, since the panel can drop some assignments under high concurrency.
- **Low Latency On-Demand (LLOD)**: an optional `<select>` (Disabled / LLOD
  v2-FFMPEG / LLOD v3-PHP) next to "Habilitar On-Demand" in both the
  single-channel and bulk-import modals, wired through
  `includes/XuiSession.php`'s existing session-based stream assignment
  (`?int $llod` param on `xui_session_assign_stream`/`_batch`). The panel's
  `get_streams` API doesn't expose a stream's current LLOD value the way it
  does for server/on-demand, so there's no way to detect "already set
  correctly" — whenever LLOD is requested, it's reassigned unconditionally to
  every touched channel, not just ones missing a server assignment.

## Deploying updates

`install.sh` copies `iptv-watch-dashboard.html` to the server **as
`index.html`** (see [Installation](#installation) above) — Apache's default
`DirectoryIndex` serves `index.html` first when a browser requests `/`.

**When redeploying a change to the dashboard after the initial install, copy
it to the server's `index.html`, not to a file named
`iptv-watch-dashboard.html`.** Updating the latter (or any other name) has no
effect on what visitors actually load, since it isn't what `/` resolves to —
it'll sit there unused while the site keeps serving the old cached-looking
version, which looks exactly like a browser-caching problem but isn't one.
Don't keep both a same-named copy and `index.html` on the server at once
either; pick `index.html` as the single deployed file so there's no drift
between two copies of the same page.

The rest of the backend (`api/*.php`, `includes/*.php`, `cron/*.php`) has no
such gotcha — deploy those files under their own real names, same as in this
repository.

## Security notes

- `config.php` (generated by `install.sh`, not included in this repository)
  ends up with `640` permissions and is owned by the web server user; never
  commit it to a shared repository — it contains the database password and
  the encryption key.
- Rotate the server's root password if you ever shared it in plain text over
  an unencrypted chat or channel.
- **The panel requires login** (see [Authentication](#authentication--multi-user-accounts)
  above). Even so, if you're exposing it to the internet, set up HTTPS (e.g.
  Let's Encrypt/certbot) — without it, credentials travel over plain HTTP on
  every login.

## Suggested extensions (not included)

- Password recovery by email.

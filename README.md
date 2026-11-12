# calendar-sync

[Italiano](README.it.md)

Self-hosted, one-way calendar sync and aggregation for **iCloud, CalDAV and Google Calendar**, with a web panel.

Copy the events of one or more calendars into another one, automatically: your work calendar into your personal one, two family calendars into a shared one, everything into a single "busy" calendar that colleagues can see. Your calendars stay where they are: calendar-sync only creates, updates and deletes the copies it made.

- **Sources → target.** A sync job reads one or more source calendars and keeps a target calendar up to date, at the frequency you choose (5 minutes to once a day).
- **Any mix of accounts.** iCloud (app-specific password), any CalDAV server (Nextcloud, Synology, Fastmail, Radicale…) and Google Calendar (OAuth).
- **Privacy per job.** Copy everything, or only "busy" blocks with a title you choose: no details, location or notes leave the source.
- **Filters.** Copy or exclude events by title, location or notes (contains, starts with, regular expression…), weekday, start time, all-day, free/busy, tentative or recurring. A title prefix like `[Work]` can be added or stripped.
- **Safe by design.**
  - Never touches events it did not create, and never adds attendees or reminders, so nobody gets invitations.
  - Never deletes anything when a source cannot be read completely.
  - Every event is copied once even if it shows up in several sources.
- **See before you sync.** A dry-run shows what would be created, updated and deleted, with no writes. An event preview shows what a calendar contains in a date range.
- **Run history and alerts.** Per-job history with errors, a dashboard, and a banner when a password is rejected or a Google token expires.
- **Panel in English and Italian**, groups to keep accounts and jobs apart, a single administrator, no public registration.
- **Credentials encrypted at rest** and never shown again; errors and logs are scrubbed of secrets. See [SECURITY.md](SECURITY.md).

## Install with Docker

You need Docker with the Compose plugin. The image runs the panel, the queue worker and the scheduler.

```bash
git clone https://github.com/laleace/calendar-sync.git
cd calendar-sync/docker
cp .env.example .env
```

Edit `.env`: set `DB_PASSWORD` to a long random password and `APP_URL` to the address you will open the panel at. Then:

```bash
docker compose up -d
docker compose exec web php artisan calsync:create-admin
```

Open `APP_URL` (by default <http://localhost:8080>) and sign in. This uses MySQL; a variant with SQLite (no database container) is `compose.sqlite.yaml`.

The panel is plain HTTP on port 8080: for anything beyond your own machine put a reverse proxy with HTTPS in front. **Back up the `APP_KEY`** (it is generated at the first start): without it the stored credentials cannot be decrypted. Details, the SQLite variant, reverse proxy examples, updates and backups: [docs/docker.md](docs/docker.md).

## Install without Docker

On a Debian/Ubuntu machine or an LXC container with nginx, PHP-FPM and systemd: [deploy/README.md](deploy/README.md). To run it locally for development:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan calsync:create-admin
php artisan dev
```

Requirements: PHP 8.4 and Composer. `php artisan dev` starts the web server (<http://localhost:8000/admin>), the queue worker, the scheduler and the log viewer together. Without a worker the syncs stay "Queued": the panel warns you after two minutes.

## Connect your accounts

| Account | What you need |
|---|---|
| iCloud | Your Apple ID and an [app-specific password](https://support.apple.com/102654). |
| CalDAV | The server URL, a username and a password. |
| Google | Your own OAuth client from Google Cloud Console, free and for personal use: [docs/google-oauth.md](docs/google-oauth.md). The app asks for the Calendar scope only. |

Then create a sync job: pick the source calendars and the target calendar (the target must be writable and cannot also be a source), choose privacy, filters and frequency, run a **dry-run**, and enable it.

## Configuration

Settings are environment variables in `.env`. The ones you will most likely change:

| Variable | Default | Meaning |
|---|---|---|
| `APP_URL` | | Address of the panel. Google's redirect URI is `APP_URL/oauth/google/callback`. |
| `APP_LOCALE` | `en` in the Docker `.env`, `it` in the others | Panel and messages language: `en` or `it`. |
| `CALSYNC_TIMEZONE` | `Europe/Rome` (`UTC` in the Docker `.env`) | Timezone of the panel and of the "today − X days" sync window. |
| `CALSYNC_RUN_RETENTION_DAYS` | `30` | How long the run history is kept. |
| `CALSYNC_HTTP_LOG` | `false` | Log every CalDAV request (credentials masked), for troubleshooting. |
| `TRUSTED_PROXIES` | `127.0.0.1,::1` | Reverse proxies allowed to set `X-Forwarded-*` headers. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | | Google OAuth client. |

Other languages can be added with a `lang/xx.json` file: copy `lang/it.json` and translate the values.

## Commands

| Command | What it does |
|---|---|
| `php artisan calsync:create-admin` | Creates the administrator, or resets its password |
| `php artisan calsync:doctor` | Checks configuration, database, scheduler and queue worker (`--services` skips the configuration) |
| `php artisan calsync:test-account {id}` | Calendar discovery for an account (`--debug` shows the HTTP requests) |
| `php artisan calsync:list-events {calendar} --from= --to=` | Events of a calendar, recurrences expanded |
| `php artisan calsync:run {job} [--dry-run]` | Runs a job in the foreground and prints the result |
| `php artisan calsync:dispatch-due` | Queues the enabled jobs that are due (the scheduler runs it every minute) |

With Docker, prefix them with `docker compose exec web`.

## FAQ

**Is it two-way sync?** No. Events go from the sources to the target, never back. Edit the original event: the next run updates the copy.

**Does it modify or delete my events?** Only the copies it created in the target. Source calendars are only read. A copy edited by hand in the target is overwritten at the next change of its source.

**Will people get invitations?** No: copies have no attendees or organizer, and no reminders.

**What happens to recurring events?** Each occurrence inside the sync window becomes its own event in the target, so exceptions and cancelled occurrences are handled exactly.

**Why does Google need my own OAuth client?** Everyone installs their own instance, so there is no shared app to verify. Creating the client takes a few minutes, and for personal use no review by Google is needed. See [docs/google-oauth.md](docs/google-oauth.md).

**Can I expose it to the Internet?** It is designed for your LAN or a VPN: it holds the credentials of your calendars. If you do expose it, use HTTPS and an access layer in front of it, such as Cloudflare Access.

**A Google account shows "Token expired".** Google revokes the refresh token if the app is in "Testing" mode (every 7 days), if you revoke access, or after months without use. Use **Reconnect** on the account; see [docs/google-oauth.md](docs/google-oauth.md) to avoid the 7-day expiry.

## Documentation

- [docs/docker.md](docs/docker.md): Docker installation, reverse proxy, updates, backups
- [deploy/README.md](deploy/README.md): installation on a Debian server or LXC container
- [docs/https-lan-cloudflare.md](docs/https-lan-cloudflare.md): valid HTTPS inside a LAN with a Cloudflare domain
- [docs/google-oauth.md](docs/google-oauth.md): Google OAuth credentials
- [docs/PIANO.md](docs/PIANO.md): design notes and decisions (in Italian)

## Contributing and support

Bug reports and pull requests are welcome: see [CONTRIBUTING.md](CONTRIBUTING.md). To report a vulnerability, see [SECURITY.md](SECURITY.md).

calendar-sync is free software. If it saves you time, you can support its development through [GitHub Sponsors](https://github.com/sponsors/laleace).

## License

[AGPL-3.0](LICENSE). If you run a modified version as a service for others, you must offer them its source code: the privacy page links the source, and `CALSYNC_SOURCE_URL` points it to your fork.

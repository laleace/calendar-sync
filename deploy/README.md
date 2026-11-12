# Deploying on a Debian server or LXC container, LAN only

[Italiano](README.it.md)

This is the installation without Docker (for Docker see [docs/docker.md](../docs/docker.md)). Step-by-step guide to install calendar-sync on a Debian 12 container or server reachable only from the local network, with valid HTTPS on `calsync.<your-domain>`.

In the commands use these values, replacing them with yours:

| Placeholder | Example | What it is |
|---|---|---|
| `calsync.example.com` | `calsync.mydomain.com` | Hostname of the panel |
| `192.168.1.50` | | Static IP of the calendar-sync container |
| `192.168.1.51` | | IP of the MySQL container (or of the MySQL server) |

All the commands run as **root** on the container, unless stated otherwise.

## Architecture

```
Browser (LAN) ──HTTPS──▶ nginx ──socket──▶ php-fpm (user calsync) ──▶ Laravel
                                                                    ├─▶ MySQL (another container)
queue worker (systemd) ─┐                                           └─▶ iCloud / Google (outbound)
scheduler (systemd) ────┘
```

- No port is open towards the Internet. iCloud and Google are reached **outbound**.
- The return from Google (OAuth) goes through your browser, so the panel does not have to be exposed (see `docs/google-oauth.md`).
- The Let's Encrypt certificate is obtained with the DNS-01 challenge through the Cloudflare API: port 80 does not need to be open to the Internet.

## 1. Container

- Debian 12, even "unprivileged". 1 vCPU and 1 GB of RAM are plenty. Static IP.
- On Proxmox, if you want the sandboxing options commented out in the systemd units, enable `features: nesting=1`.

## 2. Packages

PHP 8.4 from the Sury repository (Debian 12 has 8.2, Laravel 13 needs at least 8.3):

```bash
apt update && apt install -y ca-certificates curl git unzip nginx certbot python3-certbot-dns-cloudflare
curl -sSLo /tmp/debsuryorg-archive-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
dpkg -i /tmp/debsuryorg-archive-keyring.deb
. /etc/os-release
echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ ${VERSION_CODENAME} main" > /etc/apt/sources.list.d/php.list
apt update
apt install -y php8.4-fpm php8.4-cli php8.4-mysql php8.4-xml php8.4-mbstring php8.4-curl php8.4-intl php8.4-zip php8.4-opcache
php -v
```

Composer, checking the installer's signature:

```bash
curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
[ "$EXPECTED" = "$ACTUAL" ] && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm /tmp/composer-setup.php
```

## 3. Application user

The app runs as a dedicated system user, `calsync`, without a login shell. nginx (`www-data`) must be able to read only `public/`.

```bash
useradd --system --create-home --home-dir /home/calsync --shell /usr/sbin/nologin calsync
mkdir -p /var/www/calendar-sync
chown calsync:www-data /var/www/calendar-sync
chmod 750 /var/www/calendar-sync
```

## 4. Database (on the MySQL container)

Adapt the IP and the password in `deploy/mysql/create-database.sql`, then run the script on the MySQL server. The file is in the repository: you can copy it after the clone of step 5, or paste its contents.

```bash
mysql -u root -p < create-database.sql
```

- The `calendar_sync` user can connect only from the IP of the calendar-sync container. If the MySQL container has a firewall, open port 3306 to that IP only.
- **Use a database separate from the development one.** Two installations on the same database would share the queue and the scheduler: a development installation (`php artisan dev`) would run the production syncs and vice versa.

## 5. Code

The container downloads the code with `git clone`. At every update `update.sh` refreshes it with `git pull`.

**5.1 Clone the repository.** The folder must be empty: the final dot of `git clone` means "here". From the public repository HTTPS is enough, without credentials:

```bash
cd /var/www/calendar-sync
runuser -u calsync -- env HOME=/home/calsync git clone https://github.com/laleace/calendar-sync.git .
```

If you install from a private repository, for example your own fork, follow point 5.3 first and clone with its SSH address.

**5.2 Install the dependencies.** The `vendor/` folder is not in the repository: Composer recreates it.

```bash
runuser -u calsync -- env HOME=/home/calsync composer install --no-dev --optimize-autoloader --no-interaction
```

`runuser -u calsync` runs the command as the `calsync` user, so the files belong to it. `HOME=/home/calsync` makes git and Composer use its home folder instead of root's.

**5.3 Private repository only: deploy key.** To read a private repository (Gitea, GitLab, GitHub) the container needs an identity of its own. The most suitable is a **deploy key**:
- it is an SSH key generated on the container for the `calsync` user;
- the git server ties it to **a single repository**, **read-only**;
- no password of your account stays on the server;
- you can revoke it at any time.

Generate the key on the container, as the `calsync` user and without a passphrase, because updates must run without interaction:

```bash
install -d -o calsync -g calsync -m 700 /home/calsync/.ssh
runuser -u calsync -- ssh-keygen -t ed25519 -N "" -f /home/calsync/.ssh/id_ed25519 -C "calendar-sync deploy"
cat /home/calsync/.ssh/id_ed25519.pub
```

The last command prints the **public** key, a line starting with `ssh-ed25519`: copy it. The private key (`id_ed25519`, without `.pub`) never leaves the container.

Register it in the repository settings, under **Deploy keys**, leaving write access **off**.

The repository's SSH address is in the clone button, **SSH** tab. It looks like `git@github.com:user/calendar-sync.git` or `ssh://git@git.example.com:2222/user/calendar-sync.git`. A git server running in Docker often uses port 2222 instead of 22. Test the access with your host and port:

```bash
runuser -u calsync -- ssh -T -p 2222 git@git.example.com
```

The first time SSH asks you to confirm the server's fingerprint: answer `yes`. If the server says the authentication succeeded but does not offer a shell, all is well. Then clone as in 5.1, with the SSH address instead of the HTTPS one.

> **Alternative without SSH:** create a read-only **access token** on the git server and clone over HTTPS: `git clone https://<user>:<token>@git.example.com/user/calendar-sync.git .`. The token stays saved in the container's `.git/config`. It works, but the deploy key is cleaner.

The `.env` file is not in the repository (it is in `.gitignore`): you create it on the container in the next step.

## 6. Configuration (`.env`)

```bash
cd /var/www/calendar-sync
install -o calsync -g calsync -m 600 deploy/env.production.example .env
runuser -u calsync -- php artisan key:generate
nano .env
```

To fill in:
- `APP_URL=https://calsync.example.com`;
- `APP_LOCALE` (`en` or `it`) and `CALSYNC_TIMEZONE`;
- `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`;
- `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`, if you use Google accounts.

On Google Cloud Console add the redirect URI `https://calsync.example.com/oauth/google/callback`.

> **Save `APP_KEY` somewhere safe**, for example in your password manager. It encrypts the credentials of the accounts: if you lose it, app-specific passwords and Google tokens in the database become unreadable and have to be entered again. It never goes in the repository.

Permissions and preparation:

```bash
chown -R calsync:www-data /var/www/calendar-sync
find /var/www/calendar-sync -type d -exec chmod 750 {} \;
find /var/www/calendar-sync -type f -exec chmod 640 {} \;
chmod 600 .env
chmod +x artisan deploy/update.sh

runuser -u calsync -- php artisan migrate --force
runuser -u calsync -- php artisan optimize
runuser -u calsync -- php artisan filament:optimize
```

The administrator must be created interactively. `runuser` does not open a terminal for the prompts, so run it like this:

```bash
su -s /bin/bash calsync -c "php /var/www/calendar-sync/artisan calsync:create-admin"
```

## 7. DNS and certificate (LAN only)

Set the hostname once: the following commands reuse it.

```bash
HOST=calsync.example.com
```

1. On Cloudflare, under **DNS → Records**, add an `A` record `calsync` → `192.168.1.50` with the **proxy off** (grey cloud, "DNS only"). The name resolves to the private IP: from the Internet it leads nowhere.

   Some routers and local DNS servers (FRITZ!Box, pfSense/OPNsense, Pi-hole, AdGuard) block public names that resolve to private IPs, the so-called "DNS rebinding" protection. To recognise the case, from your computer:

   ```bash
   dig +short calsync.example.com @1.1.1.1
   dig +short calsync.example.com
   ```

   If the first command returns the container's IP and the second nothing, the filter is on the router. Add an exception:
   - **FRITZ!Box**: **Home Network → Network → Network Settings → DNS rebind protection**. In "Host name exceptions" write `calsync.example.com` and press **Apply**. Depending on the FRITZ!OS version the path may change slightly: look for "rebind".
   - **Other routers or local DNS servers**: an equivalent exception, or a local DNS record `calsync.example.com → 192.168.1.50`.

   The router may keep giving the filtered answer from its cache for up to 5 minutes (the record's TTL): wait, then flush your computer's DNS cache (on macOS: `sudo dscacheutil -flushcache; sudo killall -HUP mDNSResponder`).

2. On Cloudflare create an **API Token** under **My Profile → API Tokens → Create Token**, starting from the "Edit zone DNS" template. Permissions: `Zone → DNS → Edit`, limited to the zone of your domain only.

3. Save the token and request the certificate:

   ```bash
   mkdir -p /etc/letsencrypt
   install -m 600 /dev/null /etc/letsencrypt/cloudflare.ini
   echo "dns_cloudflare_api_token = <THE-TOKEN>" > /etc/letsencrypt/cloudflare.ini
   certbot certonly --dns-cloudflare --dns-cloudflare-credentials /etc/letsencrypt/cloudflare.ini \
     --dns-cloudflare-propagation-seconds 30 -d "$HOST"
   ```

4. Have nginx reload at every automatic renewal (the `certbot.timer` is already active):

   ```bash
   mkdir -p /etc/letsencrypt/renewal-hooks/deploy
   printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
   chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
   certbot renew --dry-run
   ```

The generic version of this part, for any site, is in [docs/https-lan-cloudflare.md](../docs/https-lan-cloudflare.md).

## 8. PHP-FPM and nginx

```bash
cd /var/www/calendar-sync
cp deploy/php/calendar-sync-pool.conf /etc/php/8.4/fpm/pool.d/calendar-sync.conf
cp deploy/php/99-calendar-sync.ini /etc/php/8.4/fpm/conf.d/99-calendar-sync.ini
systemctl restart php8.4-fpm

sed "s/calsync.example.com/$HOST/g" deploy/nginx/calendar-sync.conf > /etc/nginx/sites-available/calendar-sync
ln -s /etc/nginx/sites-available/calendar-sync /etc/nginx/sites-enabled/calendar-sync
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

In the nginx file, the `allow`/`deny` block accepts only private networks and localhost. Adapt it to your subnets.

## 9. Queue worker and scheduler

```bash
cp deploy/systemd/calendar-sync-queue.service deploy/systemd/calendar-sync-scheduler.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now calendar-sync-queue calendar-sync-scheduler
systemctl status calendar-sync-queue calendar-sync-scheduler
```

As an alternative to the scheduler as a service you can use cron with `cp deploy/cron/calendar-sync /etc/cron.d/`. Use one of the two, not both.

## 10. Verification

```bash
runuser -u calsync -- php /var/www/calendar-sync/artisan calsync:doctor
```

All the items must be `OK`. The scheduler shows as running after the first minute.

Then, from the browser on the LAN:
1. Open `https://calsync.example.com`: the certificate is valid and the login page appears.
2. **Accounts → New:** create the iCloud accounts (an app-specific password is needed) or CalDAV ones, and connect Google with "Connect with Google".
3. Create a job, run a **Dry-run** and then **Run now**. Check the **Run history**.
4. Enable the job: the scheduler runs it at the frequency you set.

If the same jobs were enabled in a development installation, disable them there after the first production sync, so they do not write twice on the same calendars.

## Updates

To install a new version of the branch the checkout is on:

```bash
bash /var/www/calendar-sync/deploy/update.sh
```

The script:
1. puts the app in maintenance mode;
2. downloads the code, installs the dependencies and runs the migrations;
3. recreates the caches;
4. restarts the workers and reloads PHP-FPM;
5. leaves maintenance mode and runs `calsync:doctor`.

## Changes to `.env`

The configuration is cached (`php artisan optimize`): a change to `.env` has no effect until you regenerate the cache and restart the processes.

```bash
cd /var/www/calendar-sync
runuser -u calsync -- php artisan optimize
runuser -u calsync -- php artisan queue:restart
systemctl reload php8.4-fpm
```

**`APP_KEY`:** if it changes, the credentials saved before can no longer be decrypted. The panel keeps working, but marks those accounts as "Unreadable credentials", and `calsync:doctor` lists them. There are two solutions:
- put back the key they were saved with, for example the one of the development installation, if the database comes from there;
- enter the password of the CalDAV accounts again (**Edit**) and reconnect the Google ones (**Reconnect Google**). Calendars and jobs stay where they are.

## Logs

| What | Where |
|---|---|
| Application | `/var/www/calendar-sync/storage/logs/laravel-YYYY-MM-DD.log` (14 days) |
| Worker and scheduler | `journalctl -u calendar-sync-queue -u calendar-sync-scheduler -f` |
| PHP-FPM | `/var/www/calendar-sync/storage/logs/php-fpm.log` |
| nginx | `/var/log/nginx/` |

To diagnose CalDAV problems, `CALSYNC_HTTP_LOG=true` logs every request, with the `Authorization` header masked. Remember to set it back to `false`.

## Backup

You need two things, and one without the other is not enough:
- the **database**: `mysqldump --single-transaction calendar_sync > calendar_sync.sql`, from the MySQL container or with your backup system;
- the **`.env`** file, or at least the `APP_KEY`.

## Security checklist

- `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`.
- `.env` with mode 600 and owned by `calsync`. The code is not writable by `www-data`.
- nginx answers only to private networks and serves no hidden files and no PHP other than `index.php`.
- The MySQL user is limited to the container's IP and to the `calendar_sync` database only.
- No port forwarded from the router to the container.
- The Cloudflare token is limited to the DNS edit of a single zone and kept in a 600 file.

# Deploy su container LXC (Debian 12), solo LAN

Guida operativa per installare calendar-sync su un container Debian 12 raggiungibile solo dalla rete locale, con HTTPS valido su `calsync.<tuo-dominio>`.

Nei comandi usa questi valori, sostituendoli con i tuoi:

| Segnaposto | Esempio | Cos'è |
|---|---|---|
| `calsync.example.com` | `calsync.example.com` | Hostname del pannello |
| `192.168.1.50` | | IP statico del container calendar-sync |
| `192.168.1.51` | | IP del container MySQL |

Tutti i comandi vanno lanciati come **root** sul container, salvo dove indicato.

## Architettura

```
Browser (LAN) ──HTTPS──▶ nginx ──socket──▶ php-fpm (utente calsync) ──▶ Laravel
                                                                      ├─▶ MySQL (altro container)
worker della coda (systemd) ─┐                                        └─▶ iCloud / Google (in uscita)
scheduler (systemd) ─────────┘
```

- Nessuna porta aperta verso Internet. iCloud e Google si raggiungono **in uscita**.
- Il ritorno da Google (OAuth) passa dal tuo browser, quindi non serve esporre il pannello (vedi `docs/google-oauth.md`).
- Il certificato Let's Encrypt si ottiene con la challenge DNS-01 tramite le API di Cloudflare: non serve la porta 80 aperta su Internet.

## 1. Container

- Debian 12, anche "unprivileged". 1 vCPU e 1 GB di RAM sono abbondanti. IP statico.
- Su Proxmox, se vuoi le opzioni di sandboxing commentate nelle unit systemd, abilita `features: nesting=1`.

## 2. Pacchetti

PHP 8.4 dal repository Sury (Debian 12 ha la 8.2, Laravel 13 richiede almeno la 8.3):

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

Composer, con verifica della firma dell'installer:

```bash
curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
[ "$EXPECTED" = "$ACTUAL" ] && php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm /tmp/composer-setup.php
```

## 3. Utente dell'applicazione

L'app gira come utente di sistema dedicato `calsync`, senza shell di login. nginx (`www-data`) deve poter leggere solo `public/`.

```bash
useradd --system --create-home --home-dir /home/calsync --shell /usr/sbin/nologin calsync
mkdir -p /var/www/calendar-sync
chown calsync:www-data /var/www/calendar-sync
chmod 750 /var/www/calendar-sync
```

## 4. Database (sul container MySQL)

Adatta IP e password in `deploy/mysql/create-database.sql`, poi esegui lo script sul server MySQL. Il file è nel repository: puoi copiarlo dopo il clone del punto 5, oppure incollarne il contenuto.

```bash
mysql -u root -p < create-database.sql
```

- L'utente `calendar_sync` può collegarsi solo dall'IP del container calendar-sync. Se il container MySQL ha un firewall, apri la porta 3306 solo a quell'IP.
- **Usa un database separato da quello di sviluppo.** Due installazioni sullo stesso database condividerebbero coda e scheduler: il `php artisan dev` del Mac eseguirebbe i sync di produzione e viceversa.

## 5. Codice

Dal Gitea, con una deploy key in sola lettura per l'utente `calsync`:

```bash
install -d -o calsync -g calsync -m 700 /home/calsync/.ssh
runuser -u calsync -- ssh-keygen -t ed25519 -N "" -f /home/calsync/.ssh/id_ed25519 -C "calendar-sync deploy"
cat /home/calsync/.ssh/id_ed25519.pub
```

Aggiungi la chiave pubblica su Gitea, in **Repository → Impostazioni → Chiavi di deploy**, senza permesso di scrittura. Poi clona:

```bash
cd /var/www/calendar-sync
runuser -u calsync -- env HOME=/home/calsync git clone ssh://git@<gitea>/<utente>/calendar-sync.git .
runuser -u calsync -- env HOME=/home/calsync composer install --no-dev --optimize-autoloader --no-interaction
```

## 6. Configurazione (`.env`)

```bash
cd /var/www/calendar-sync
install -o calsync -g calsync -m 600 deploy/env.production.example .env
runuser -u calsync -- php artisan key:generate
nano .env
```

Da compilare:
- `APP_URL=https://calsync.example.com`;
- `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`;
- `GOOGLE_CLIENT_ID` e `GOOGLE_CLIENT_SECRET`, gli stessi dello sviluppo.

Su Google Cloud Console aggiungi il redirect URI `https://calsync.example.com/oauth/google/callback`.

> **Salva `APP_KEY` in un posto sicuro**, per esempio nel password manager. Cifra le credenziali degli account: se la perdi, password per app e token Google nel database diventano illeggibili e vanno reinseriti. Non va mai nel repository.

Permessi e preparazione:

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

L'amministratore va creato in modo interattivo. `runuser` non apre un terminale per i prompt, quindi lancialo così:

```bash
su -s /bin/bash calsync -c "php /var/www/calendar-sync/artisan calsync:create-admin"
```

## 7. DNS e certificato (solo LAN)

Imposta l'hostname una volta: i comandi seguenti lo riusano.

```bash
HOST=calsync.example.com
```

1. Su Cloudflare, in **DNS → Records**, aggiungi un record `A` `calsync` → `192.168.1.50` con il **proxy disattivato** (nuvola grigia, "DNS only"). Il nome risolve all'IP privato: da Internet non porta da nessuna parte.

   Alcuni router e DNS locali (FRITZ!Box, pfSense/OPNsense, Pi-hole, AdGuard) bloccano i nomi pubblici che risolvono su IP privati, la cosiddetta protezione "DNS rebinding". Se `calsync.example.com` non risolve dalla LAN, aggiungi un'eccezione per il dominio o un record locale sul tuo DNS.

2. Su Cloudflare crea un **API Token** in **My Profile → API Tokens → Create Token**, partendo dal modello "Edit zone DNS". Permessi: `Zone → DNS → Edit`, limitati alla sola zona del tuo dominio.

3. Salva il token e richiedi il certificato:

   ```bash
   mkdir -p /etc/letsencrypt
   install -m 600 /dev/null /etc/letsencrypt/cloudflare.ini
   echo "dns_cloudflare_api_token = <IL-TOKEN>" > /etc/letsencrypt/cloudflare.ini
   certbot certonly --dns-cloudflare --dns-cloudflare-credentials /etc/letsencrypt/cloudflare.ini \
     --dns-cloudflare-propagation-seconds 30 -d "$HOST"
   ```

4. Fai ricaricare nginx a ogni rinnovo automatico (il timer `certbot.timer` è già attivo):

   ```bash
   mkdir -p /etc/letsencrypt/renewal-hooks/deploy
   printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
   chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
   certbot renew --dry-run
   ```

## 8. PHP-FPM e nginx

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

Nel file nginx, il blocco `allow`/`deny` accetta solo reti private e localhost. Adattalo alle tue sottoreti.

## 9. Worker della coda e scheduler

```bash
cp deploy/systemd/calendar-sync-queue.service deploy/systemd/calendar-sync-scheduler.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now calendar-sync-queue calendar-sync-scheduler
systemctl status calendar-sync-queue calendar-sync-scheduler
```

In alternativa allo scheduler come servizio puoi usare cron con `cp deploy/cron/calendar-sync /etc/cron.d/`. Usa uno dei due, non entrambi.

## 10. Verifica

```bash
runuser -u calsync -- php /var/www/calendar-sync/artisan calsync:doctor
```

Tutte le voci devono essere `OK`. Lo scheduler risulta attivo dopo il primo minuto.

Poi, dal browser in LAN:
1. Apri `https://calsync.example.com`: il certificato è valido e compare la pagina di login.
2. **Account → Nuovo:** ricrea l'account iCloud (password per app) e collega Google ("Connetti con Google"). Il database di produzione parte vuoto.
3. Ricrea il job, fai un **Dry-run** e poi **Esegui ora**. Controlla lo **Storico run**.
4. Attiva il job: lo scheduler lo esegue con la frequenza impostata.

Dopo il primo sync in produzione, ferma o disattiva gli stessi job sul Mac, così non scrivono due volte sugli stessi calendari.

## Aggiornamenti

Dopo un push su Gitea:

```bash
bash /var/www/calendar-sync/deploy/update.sh
```

Lo script:
1. mette l'app in manutenzione;
2. scarica il codice, installa le dipendenze e lancia le migration;
3. ricrea le cache;
4. riavvia i worker e ricarica PHP-FPM;
5. toglie la manutenzione e lancia `calsync:doctor`.

## Log

| Cosa | Dove |
|---|---|
| Applicazione | `/var/www/calendar-sync/storage/logs/laravel-AAAA-MM-GG.log` (14 giorni) |
| Worker e scheduler | `journalctl -u calendar-sync-queue -u calendar-sync-scheduler -f` |
| PHP-FPM | `/var/www/calendar-sync/storage/logs/php-fpm.log` |
| nginx | `/var/log/nginx/` |

Per diagnosticare problemi CalDAV, `CALSYNC_HTTP_LOG=true` registra ogni richiesta, con l'header `Authorization` mascherato. Ricordati di rimetterlo a `false`.

## Backup

Servono due cose, e una senza l'altra non basta:
- il **database**: `mysqldump --single-transaction calendar_sync > calendar_sync.sql`, dal container MySQL o con il tuo sistema di backup;
- il file **`.env`**, o almeno la `APP_KEY`.

## Sicurezza: checklist

- `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`.
- `.env` con permessi 600 e proprietario `calsync`. Il codice non è scrivibile da `www-data`.
- nginx risponde solo alle reti private e non serve file nascosti né PHP diversi da `index.php`.
- L'utente MySQL è limitato all'IP del container e al solo database `calendar_sync`.
- Nessuna porta inoltrata dal router verso il container.
- Il token Cloudflare è limitato alla modifica DNS di una sola zona e conservato in un file 600.

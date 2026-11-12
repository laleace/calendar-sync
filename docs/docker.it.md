# calendar-sync con Docker

[English](docker.md)

L'immagine esegue tre servizi dallo stesso codice: `web` (il pannello, con le migration all'avvio), `worker` (esegue le sync) e `scheduler` (mette in coda i job in scadenza ogni minuto). Tutti i file sono nella cartella [`docker/`](../docker).

| File | Cos'è |
|---|---|
| `compose.yaml` | I servizi più MySQL 8.4. La configurazione consigliata. |
| `compose.sqlite.yaml` | I servizi con SQLite, senza container del database. Va bene per un'installazione personale con pochi job. |
| `compose.build.yaml` | Aggiunta che costruisce l'immagine da questo checkout invece di scaricarla dal registry. |
| `.env.example` | Le impostazioni, commentate. Copialo in `.env` nella stessa cartella. |

## 1. Installazione

```bash
git clone https://github.com/laleace/calendar-sync.git
cd calendar-sync/docker
cp .env.example .env
```

In `.env` imposta almeno:
- `DB_PASSWORD`: una password lunga e casuale (`openssl rand -hex 24`). Viene applicata alla creazione del database: scegli prima del primo avvio. Con SQLite non serve.
- `APP_URL`: l'indirizzo da cui aprirai il pannello, per esempio `http://192.168.1.50:8080`. L'URI di reindirizzamento di Google si costruisce da qui.
- `APP_LOCALE` (`en` o `it`) e `CALSYNC_TIMEZONE`.

Avvia e crea l'amministratore:

```bash
docker compose up -d
docker compose exec web php artisan calsync:create-admin
```

Con SQLite aggiungi `-f compose.sqlite.yaml` a ogni comando `docker compose` (anche a `exec`). Vale anche per i comandi che seguono.

Per costruire l'immagine dal sorgente invece di scaricarla, aggiungi `-f compose.yaml -f compose.build.yaml` e `--build` al comando `up`.

Dopo un paio di minuti controlla che sia tutto sano:

```bash
docker compose ps
docker compose exec web php artisan calsync:doctor
```

`web`, `worker`, `scheduler` e `db` devono risultare `healthy`. Worker e scheduler sono sani solo finché lo scheduler batte ogni minuto e nessun run è fermo in coda. In HTTP semplice il `calsync:doctor` completo segnala come non superati "APP_URL su HTTPS" e il cookie sicuro: è normale finché non metti HTTPS davanti.

## 2. La chiave di cifratura

`APP_KEY` cifra le credenziali dei tuoi account. Se in `.env` `APP_KEY` è vuota, il primo avvio ne genera una e la salva nel volume dei dati, in `/app/storage/app/app.key`. **Fanne una copia**: senza, password e token Google salvati diventano illeggibili e vanno reinseriti.

```bash
docker compose cp web:/app/storage/app/app.key ./app.key
```

Tieni quel file fuori dal repository e in un password manager. Per gestire tu la chiave, mettila in `.env` (`APP_KEY=base64:...`, come la produce `php artisan key:generate --show`).

## 3. HTTPS e reverse proxy

Il container parla solo HTTP sulla porta 8080. Per usarlo oltre il tuo computer metti davanti un reverse proxy con un certificato. Il pannello va aperto allo stesso indirizzo di `APP_URL`.

In `.env`:

```dotenv
APP_URL=https://calsync.example.com
SESSION_SECURE_COOKIE=true
# Pubblica la porta solo su questa macchina, per un proxy sulla stessa macchina
CALSYNC_BIND=127.0.0.1
```

Poi `docker compose up -d`. Caddy ottiene da solo il certificato:

```caddyfile
calsync.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

Con nginx:

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_read_timeout 90s;
}
```

`TRUSTED_PROXIES` decide quali proxy possono dire all'app che è servita in HTTPS. Il valore predefinito, `172.16.0.0/12`, copre le reti usuali di Docker, da cui appare un proxy sulla stessa macchina. Se il tuo Docker usa altri intervalli di indirizzi, o il proxy è su un'altra macchina, metti l'indirizzo del proxy. Non usare `*`.

Per un certificato valido su un indirizzo privato, senza aprire nessuna porta verso Internet, vedi [https-lan-cloudflare.it.md](https-lan-cloudflare.it.md).

## 4. Aggiornamento

Con l'immagine pubblicata:

```bash
cd calendar-sync/docker
git pull
docker compose pull
docker compose up -d
```

Il container `web` applica le migration del database quando parte. **Prima di un aggiornamento che porta nuove migration, fai il backup del database** (sotto). Per fissare una versione imposta `CALSYNC_VERSION` in `.env` (per esempio `1.0.0`; il valore predefinito è `latest`).

Se costruisci dal sorgente: `git pull` e poi `docker compose -f compose.yaml -f compose.build.yaml up -d --build`.

## 5. Backup e ripristino

Servono due cose: il database e la `APP_KEY`.

MySQL:

```bash
docker compose exec db sh -c 'exec mysqldump --single-transaction -u calendar_sync -p"$MYSQL_PASSWORD" calendar_sync' > calendar-sync.sql
```

Ripristino, su uno stack attivo con la stessa `APP_KEY`:

```bash
docker compose exec -T db sh -c 'exec mysql -u calendar_sync -p"$MYSQL_PASSWORD" calendar_sync' < calendar-sync.sql
```

SQLite sta nel volume dei dati. Ferma lo stack per una copia coerente:

```bash
docker compose -f compose.sqlite.yaml stop
docker run --rm -v calendar-sync_data:/data -v "$PWD":/backup alpine tar czf /backup/calendar-sync-data.tgz -C /data .
docker compose -f compose.sqlite.yaml start
```

Questo archivio contiene la chiave (`app.key`) e il database: conservalo con la cura di una password.

## 6. Uso quotidiano

| Cosa | Come |
|---|---|
| Log | `docker compose logs -f web worker scheduler` |
| Reimpostare la password dell'amministratore | `docker compose exec web php artisan calsync:create-admin` |
| Eseguire un job subito, in primo piano | `docker compose exec web php artisan calsync:run {job}` |
| Diagnosticare un account CalDAV | `docker compose exec web php artisan calsync:test-account {id} --debug` |
| Fermare, conservando i dati | `docker compose down` |
| Fermare e cancellare i dati | `docker compose down -v` |

Dopo una modifica a `.env` lancia `docker compose up -d`: i container che dipendono dai valori cambiati vengono ricreati.

## Problemi comuni

| Sintomo | Causa e rimedio |
|---|---|
| `set DB_PASSWORD in .env` all'avvio | `DB_PASSWORD` è vuota. |
| MySQL "Access denied" dopo aver cambiato `DB_PASSWORD` | La password si imposta solo alla prima creazione del database. Cambiala dentro MySQL, oppure `docker compose down -v` per ripartire da zero (cancella i dati). |
| I run restano "In coda" | Il worker non gira: `docker compose ps` e `docker compose logs worker`. |
| Pagina senza stile, o link che iniziano con `http://` | `APP_URL` non coincide con l'indirizzo che usi, oppure il proxy non invia `X-Forwarded-Proto` o non è in `TRUSTED_PROXIES`. |
| Google dice che l'URI di reindirizzamento non corrisponde | `APP_URL/oauth/google/callback` va registrato su Google Cloud Console esattamente. Vedi [google-oauth.it.md](google-oauth.it.md). |
| "Credenziali illeggibili" sugli account | La `APP_KEY` è diversa da quella con cui erano state salvate. Ripristina la chiave giusta, oppure reinserisci le password. |
| `web` non sano subito dopo il primo avvio | Esegue tutte le migration prima di servire: aspetta un minuto, poi guarda `docker compose logs web`. |

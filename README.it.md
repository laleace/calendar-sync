# calendar-sync

[English](README.md)

[![CI](https://github.com/laleace/calendar-sync/actions/workflows/ci.yml/badge.svg)](https://github.com/laleace/calendar-sync/actions/workflows/ci.yml)

Sincronizzazione unidirezionale e aggregazione di calendari **iCloud, CalDAV e Google Calendar**, self-hosted, con un pannello web.

Copia in automatico gli eventi di uno o più calendari in un altro: il calendario di lavoro in quello personale, due calendari di famiglia in uno condiviso, tutto in un unico calendario "occupato" che i colleghi possono vedere. I tuoi calendari restano dove sono: calendar-sync crea, aggiorna ed elimina solo le copie che ha fatto lui.

- **Sorgenti → destinazione.** Un job legge uno o più calendari sorgente e tiene aggiornato un calendario di destinazione, con la frequenza che scegli (da 5 minuti a una volta al giorno).
- **Account di ogni tipo, anche insieme.** iCloud (password per app), qualsiasi server CalDAV (Nextcloud, Synology, Fastmail, Radicale…) e Google Calendar (OAuth).
- **Privacy per job.** Copia tutto, oppure solo blocchi "occupato" con il titolo che scegli: nessun dettaglio, luogo o nota lascia la sorgente.
- **Filtri.** Copia o escludi gli eventi per titolo, luogo o note (contiene, inizia con, espressione regolare…), giorno della settimana, ora di inizio, giornata intera, libero/occupato, provvisorio o ricorrente. Si può aggiungere o togliere un prefisso come `[Lavoro]` al titolo.
- **Sicuro per costruzione.**
  - Non tocca mai gli eventi che non ha creato e non aggiunge partecipanti né promemoria, quindi nessuno riceve inviti.
  - Non cancella niente se una sorgente non è leggibile per intero.
  - Ogni evento viene copiato una sola volta anche se compare in più sorgenti.
- **Guarda prima di sincronizzare.** Il dry-run mostra cosa verrebbe creato, aggiornato ed eliminato, senza scrivere niente. L'anteprima eventi mostra cosa contiene un calendario in un intervallo di date.
- **Storico e avvisi.** Storico dei run per job con gli errori, dashboard e un banner quando una password viene rifiutata o un token Google scade.
- **Pannello in italiano e inglese**, gruppi per tenere separati account e job, un solo amministratore, nessuna registrazione pubblica.
- **Credenziali cifrate** e mai più mostrate; errori e log ripuliti dai segreti. Vedi [SECURITY.md](SECURITY.md) (in inglese).

## Installazione con Docker

Serve Docker con il plugin Compose. L'immagine esegue il pannello, il worker della coda e lo scheduler.

```bash
git clone https://github.com/laleace/calendar-sync.git
cd calendar-sync/docker
cp .env.example .env
```

Modifica `.env`: imposta `DB_PASSWORD` con una password lunga e casuale e `APP_URL` con l'indirizzo da cui aprirai il pannello; per il pannello in italiano metti anche `APP_LOCALE=it`. Poi:

```bash
docker compose up -d
docker compose exec web php artisan calsync:create-admin
```

Apri `APP_URL` (di default <http://localhost:8080>) e accedi. Usa MySQL; la variante con SQLite (senza container del database) è `compose.sqlite.yaml`.

Il pannello è in HTTP semplice sulla porta 8080: per qualunque uso oltre la tua macchina metti davanti un reverse proxy con HTTPS. **Fai una copia della `APP_KEY`** (viene generata al primo avvio): senza non si possono decifrare le credenziali salvate. Dettagli, variante SQLite, esempi di reverse proxy, aggiornamenti e backup: [docs/docker.it.md](docs/docker.it.md).

## Installazione senza Docker

Su una macchina Debian/Ubuntu o un container LXC con nginx, PHP-FPM e systemd: [deploy/README.it.md](deploy/README.it.md). Per usarlo in locale, in sviluppo:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan calsync:create-admin
php artisan dev
```

Requisiti: PHP 8.4 e Composer. `php artisan dev` avvia insieme il server web (<http://localhost:8000/admin>), il worker della coda, lo scheduler e i log. Senza worker i sync restano "In coda": il pannello lo segnala dopo 2 minuti.

## Collegare gli account

| Account | Cosa serve |
|---|---|
| iCloud | Il tuo Apple ID e una [password per app](https://support.apple.com/it-it/102654). |
| CalDAV | L'URL del server, un nome utente e una password. |
| Google | Un tuo client OAuth da Google Cloud Console, gratuito e per uso personale: [docs/google-oauth.it.md](docs/google-oauth.it.md). L'app chiede solo l'ambito Calendar. |

Poi crea un job: scegli i calendari sorgente e quello di destinazione (deve essere scrivibile e non può essere anche una sorgente), imposta privacy, filtri e frequenza, fai un **dry-run** e attivalo.

## Configurazione

Le impostazioni sono variabili d'ambiente nel `.env`. Quelle che cambierai più spesso:

| Variabile | Predefinito | Significato |
|---|---|---|
| `APP_URL` | | Indirizzo del pannello. L'URI di reindirizzamento di Google è `APP_URL/oauth/google/callback`. |
| `APP_LOCALE` | `en` nel `.env` di Docker, `it` negli altri | Lingua del pannello e dei messaggi: `en` o `it`. |
| `CALSYNC_TIMEZONE` | `Europe/Rome` (`UTC` nel `.env` di Docker) | Fuso orario del pannello e della finestra "oggi − X giorni". |
| `CALSYNC_RUN_RETENTION_DAYS` | `30` | Per quanto tempo si conserva lo storico dei run. |
| `CALSYNC_HTTP_LOG` | `false` | Registra ogni richiesta CalDAV (credenziali mascherate), per la diagnosi. |
| `TRUSTED_PROXIES` | `127.0.0.1,::1` | Reverse proxy autorizzati a impostare gli header `X-Forwarded-*`. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | | Client OAuth di Google. |

Si possono aggiungere altre lingue con un file `lang/xx.json`: copia `lang/it.json` e traduci i valori.

## Comandi

| Comando | A cosa serve |
|---|---|
| `php artisan calsync:create-admin` | Crea l'amministratore, o ne reimposta la password |
| `php artisan calsync:doctor` | Controlla configurazione, database, scheduler e worker (`--services` salta la configurazione) |
| `php artisan calsync:test-account {id}` | Discovery dei calendari di un account (`--debug` mostra le richieste HTTP) |
| `php artisan calsync:list-events {calendario} --from= --to=` | Eventi di un calendario, ricorrenze espanse |
| `php artisan calsync:run {job} [--dry-run]` | Esegue un job in primo piano e stampa l'esito |
| `php artisan calsync:dispatch-due` | Accoda i job attivi in scadenza (lo lancia lo scheduler ogni minuto) |

Con Docker, anteponi `docker compose exec web`.

## Domande frequenti

**È una sincronizzazione bidirezionale?** No. Gli eventi vanno dalle sorgenti alla destinazione, mai al contrario. Modifica l'evento originale: al run successivo la copia si aggiorna.

**Modifica o elimina i miei eventi?** Solo le copie che ha creato nella destinazione. I calendari sorgente vengono solo letti. Una copia modificata a mano nella destinazione viene sovrascritta alla prossima modifica della sorgente.

**Le persone ricevono inviti?** No: le copie non hanno partecipanti né organizzatore, e nemmeno promemoria.

**E gli eventi ricorrenti?** Ogni occorrenza dentro la finestra di sincronizzazione diventa un evento a sé nella destinazione, così eccezioni e occorrenze annullate sono gestite con precisione.

**Perché Google richiede un mio client OAuth?** Ognuno installa la propria istanza, quindi non c'è un'app condivisa da verificare. Creare il client richiede pochi minuti e per uso personale Google non fa nessuna revisione. Vedi [docs/google-oauth.it.md](docs/google-oauth.it.md).

**Posso esporlo su Internet?** È pensato per la tua LAN o per una VPN: contiene le credenziali dei tuoi calendari. Se lo esponi, usa HTTPS e un livello di accesso davanti, come Cloudflare Access.

**Un account Google mostra "Token scaduto".** Google revoca il refresh token se l'app è in modalità "Testing" (ogni 7 giorni), se revochi l'accesso o dopo mesi di inattività. Usa **Riconnetti** sull'account; per evitare la scadenza dei 7 giorni vedi [docs/google-oauth.it.md](docs/google-oauth.it.md).

## Documentazione

- [docs/docker.it.md](docs/docker.it.md): installazione con Docker, reverse proxy, aggiornamenti, backup
- [deploy/README.it.md](deploy/README.it.md): installazione su un server Debian o un container LXC
- [docs/https-lan-cloudflare.it.md](docs/https-lan-cloudflare.it.md): HTTPS valido in una LAN con un dominio Cloudflare
- [docs/google-oauth.it.md](docs/google-oauth.it.md): credenziali Google OAuth
- [docs/PIANO.md](docs/PIANO.md): note e decisioni di progetto

## Contributi e supporto

Segnalazioni e pull request sono benvenute: vedi [CONTRIBUTING.md](CONTRIBUTING.md) (in inglese). Per segnalare una vulnerabilità vedi [SECURITY.md](SECURITY.md).

calendar-sync è software libero. Se ti fa risparmiare tempo, puoi sostenerne lo sviluppo con [GitHub Sponsors](https://github.com/sponsors/laleace).

## Licenza

[AGPL-3.0](LICENSE). Se esegui una versione modificata come servizio per altri, devi offrire loro il codice sorgente: la pagina privacy linka il sorgente e `CALSYNC_SOURCE_URL` la punta al tuo fork.

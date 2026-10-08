# calendar-sync — Piano (Step 0)

> Stato: **approvato il 2026-10-07.** Step completati: 0, 1, 2, 3, 4, 5.
> Questo file resta come riferimento per tutti gli step. Lo aggiorno quando una decisione cambia.

## 1. Ambiente rilevato

- La cartella `CalendarAggregator/` è vuota (contiene solo `.claude/`) e non è un repo git.
- In locale ci sono PHP 8.4.1 (Herd Lite), Composer 2.8, Node 24 e SQLite. MySQL non c'è.
- Versioni stabili su Packagist al 2026-10-07:

| Pacchetto | Versione | Requisiti |
|---|---|---|
| Laravel | 13.35 | PHP ≥ 8.3 |
| Filament | 5.10 | Livewire 4, Laravel 11.28 / 12 / 13 |
| Sanctum | 4.3 | |
| google/apiclient | 2.20 | |
| sabre/vobject | 4.6.1 e 5.0.0 | |
| Pest | 5 | |

## 2. Stack

| Componente | Scelta | Note |
|---|---|---|
| PHP | 8.4 | Il container è Debian 12: PHP 8.4 dal repo Sury (packages.sury.org) |
| Framework | Laravel 13 | Skeleton puro (`laravel/laravel`) senza starter kit, perché l'autenticazione la fornisce Filament |
| GUI | **Filament 5** | Vedi §2.1 |
| Auth API | **Sanctum 4** (personal access token con abilities) | Vedi §2.2 |
| DB | MySQL in produzione, SQLite in dev e test | Connessione via env. Migration portabili, senza funzioni specifiche di un solo DB |
| Coda, cache, sessioni, lock | Driver `database` | Niente Redis da installare e gestire |
| CalDAV | Facade `Http` + `DOMDocument`/`DOMXPath` + `sabre/vobject` ^5.0 | Scelta allo step 3: stessa API della 4.6, con i tipi PHP moderni |
| Google | `google/apiclient` con il solo servizio Calendar | Il cleanup di Composer rimuove le altre ~200 API |
| Test | Pest | CalDAV con `Http::fake`. Google con `MockHandler` di Guzzle (il client Google usa Guzzle diretto, non la facade). SyncEngine con un provider in-memory |

### 2.1 GUI: le opzioni

| Opzione | Pro | Contro |
|---|---|---|
| **Filament 5** | Pronti all'uso: CRUD, form con multi-select, tabelle filtrabili, azioni con modale, relation manager, login con rate limit, notifiche, widget | Dipendenza pesante. Major frequenti (v3 → v4 → v5 in circa 18 mesi). Le schermate fuori dagli schemi standard, come l'anteprima del dry-run, richiedono view Blade custom |
| Livewire 4 + Blade (+ Flux) | Controllo totale, meno dipendenze | Login, tabelle, form e modali da scrivere a mano: circa 3-4 volte il codice di GUI |
| Inertia + Vue/React | UI moderna | Doppio stack, con build JS e npm anche nel deploy. Sproporzionato per un pannello di configurazione |
| Nova, Backpack Pro | Prodotti maturi | A pagamento |

**Scelta: Filament 5**, in linea con la tua preferenza.
- Quasi tutta la GUI richiesta ricade nei casi d'uso nativi di Filament: CRUD di Account e SyncJob, storico dei run, azioni "Test connessione", "Scopri calendari" ed "Esegui ora".
- Le parti custom restano dentro il pannello:
  - anteprima del dry-run e anteprima eventi: Page custom e modali con view Blade;
  - banner per token scaduto: render hook.
- Filament è costruito su Livewire, quindi all'occorrenza posso scrivere componenti Livewire puri nello stesso progetto.

### 2.2 Auth API: le opzioni

- **`X-Api-Key` da env, confrontata con `hash_equals`.** È semplice, ma ha limiti:
  - una sola chiave;
  - per revocarla bisogna modificare `.env`;
  - nessun permesso granulare.
- **Sanctum (scelto).**
  - Token multipli, per esempio uno per workflow n8n.
  - Revoca dalla GUI e campo `last_used_at`.
  - Abilities: `events:read`, `sync:run`, `runs:read`.
  - Sanctum salva solo lo SHA-256 del token e lo confronta con `hash_equals`, quindi il requisito è soddisfatto nativamente.
  - Il token in chiaro si vede una sola volta, alla creazione (dalla GUI o con `calsync:api-token`).
  - In n8n basta una credenziale Header Auth con `Authorization: Bearer <token>`.
  - Il caso "n8n legge senza scrivere" diventa un token con la sola ability `events:read`.

## 3. Struttura del progetto

```
CalendarAggregator/              # root del repo = app Laravel "calendar-sync"
├── app/
│   ├── CalSync/                 # dominio, indipendente da Filament e HTTP
│   │   ├── Contracts/CalendarProvider.php
│   │   ├── Dto/                 # EventDto, CalendarDto, ConnectionResult
│   │   ├── Exceptions/          # AuthenticationFailed, TokenExpired, ProviderException
│   │   ├── CalDav/              # CalDavProvider, CalDavClient, MultistatusParser, ICalendarMapper
│   │   ├── Google/              # GoogleProvider, GoogleClientFactory, GoogleEventMapper
│   │   ├── Sync/                # SyncEngine, SyncPlan, PlannedOperation, EventTransformer, Markers
│   │   ├── Support/SecretRedactor.php
│   │   └── ProviderRegistry.php # AccountType → provider
│   ├── Enums/                   # AccountType, AccountStatus, PrivacyMode, SyncRunStatus, SyncTrigger
│   ├── Models/                  # User, Account, Calendar, SyncJob, EventMapping, SyncRun
│   ├── Jobs/SyncJobRunner.php
│   ├── Console/Commands/        # CreateAdmin, AddCalDavAccount, TestAccount, ListEvents, DispatchDue, ApiToken
│   ├── Filament/
│   │   ├── Resources/           # AccountResource (+Calendars RM), SyncJobResource (+SyncRuns RM), SyncRunResource
│   │   ├── Pages/               # Dashboard, CalendarPreview, ApiTokens
│   │   └── Widgets/             # AccountHealth, RecentRuns
│   └── Http/Controllers/
│       ├── GoogleOAuthController.php
│       └── Api/                 # CalendarEvents, SyncJobRun, SyncRunShow (+ JsonResource)
├── database/migrations/
├── routes/{web,api,console}.php
├── tests/
│   ├── Fixtures/{caldav,ics,google}/   # risposte 207 XML, file ICS, JSON Google realistici
│   ├── Unit/                    # parser XML, mapper ICS, transformer, redactor
│   └── Feature/                 # comandi, engine, GUI, API
├── deploy/                      # step 9
└── docs/PIANO.md                # questo file
```

## 4. Schema DB

Relazioni:
- Account 1─N Calendar
- SyncJob N─M Calendar (sorgenti, tramite `sync_job_sources`)
- SyncJob N─1 Calendar (target)
- SyncJob 1─N EventMapping
- SyncJob 1─N SyncRun

Tutti i datetime sono salvati in UTC.

### users
Tabella Laravel standard (`name`, `email`, `password`, `remember_token`). Contiene un solo admin, creato con `calsync:create-admin`.

### accounts
| Colonna | Tipo | Note |
|---|---|---|
| id | bigint PK | |
| name | string(100) | Etichetta, es. "iCloud personale" |
| type | string(20) | Enum `AccountType`: `icloud_caldav`, `caldav_generic`, `google` |
| base_url | string(500) null | Per CalDAV generico. Per iCloud vale `https://caldav.icloud.com/` |
| credentials | text, cast `encrypted:array` | CalDAV: `username`, `password`. Google: `access_token`, `refresh_token`, `expires_at`, `email` |
| principal_url | string(500) null | Scoperto dalla discovery |
| calendar_home_url | string(500) null | Scoperto dalla discovery. Per iCloud contiene l'host shard `pXX-caldav.icloud.com` |
| status | string(20) | `unknown`, `ok`, `auth_failed`, `token_expired`, `error` |
| status_message | string(500) null | Mai segreti |
| last_checked_at | timestamp null | |
| timestamps | | |

### calendars
| Colonna | Tipo | Note |
|---|---|---|
| id | bigint PK | |
| account_id | FK → accounts, cascade | |
| remote_id | string(500) | href CalDAV o calendarId Google. `unique(account_id, remote_id)` |
| name | string(255) | |
| color | string(9) null | |
| timezone | string(64) null | |
| can_write | bool | CalDAV: privilegio `write` o `write-content`. Google: `accessRole` uguale a `owner` o `writer` |
| missing_since | timestamp null | Calendario non più trovato alla discovery. Non lo cancello perché un job può farvi riferimento |
| timestamps | | |

Alla discovery scarto le collezioni senza VEVENT, per esempio i Promemoria iCloud (VTODO).

### sync_jobs
| Colonna | Tipo | Note |
|---|---|---|
| id | bigint PK | |
| name | string(100) | |
| enabled | bool, default false | |
| target_calendar_id | FK → calendars, restrict | |
| direction | string(20), default `one_way` | Per ora è l'unico valore |
| range_past_days | unsigned smallint, default 7 | |
| range_future_days | unsigned smallint, default 60 | |
| interval_minutes | unsigned smallint, default 15 | Preset nella GUI: 5, 15, 30, 60, 360, 1440 |
| privacy_mode | string(20) | `full`, `busy_only` |
| busy_title | string(100), default "Occupato" | Titolo dei blocchi in modalità `busy_only` |
| title_prefix | string(50) null | |
| allow_duplicates | bool, default false | `false`: lo stesso UID in più sorgenti produce una sola copia |
| skip_existing_in_target | bool, default false | `true`: salta gli eventi il cui UID esiste già nel target, per esempio l'invito originale |
| next_run_at | timestamp null, indice | Usato dallo scheduler |
| last_run_at | timestamp null | |
| timestamps | | |

### sync_job_sources (pivot)
| Colonna | Tipo | Note |
|---|---|---|
| sync_job_id | FK, cascade | |
| calendar_id | FK, restrict | |
| position | unsigned smallint | Priorità della sorgente, usata dalla deduplica (§5.4) |

Vincolo `unique(sync_job_id, calendar_id)`. La validazione impedisce che il target compaia anche tra le sorgenti.

### event_mappings
| Colonna | Tipo | Note |
|---|---|---|
| id | bigint PK | |
| sync_job_id | FK, cascade | |
| source_calendar_id | FK → calendars | Sorgente da cui proviene la copia |
| source_uid | text | |
| recurrence_id | string(32) null | Normalizzato: `20261007T090000Z`, oppure `20261007` per gli all-day |
| source_key | char(64) | SHA-256 di uid + recurrence_id, più l'id del calendario sorgente se `allow_duplicates` è attivo. `unique(sync_job_id, source_key)` |
| target_calendar_id | FK → calendars | Il target al momento della creazione |
| target_event_id | string(500) | Id evento Google o href della risorsa CalDAV |
| target_etag | string(255) null | CalDAV: usato in `If-Match` negli update |
| content_hash | char(64) | |
| starts_at | datetime | Inizio dell'istanza, serve alla logica della finestra (§5.3). È `datetime` e non `timestamp`, per evitare il limite del 2038 e le conversioni di timezone di MySQL |
| last_synced_at | timestamp | |
| timestamps | | |

Perché un hash come chiave: alcuni server generano UID lunghi centinaia di caratteri e MySQL limita la lunghezza delle chiavi d'indice. L'unicità passa quindi da `source_key`.

### sync_runs
| Colonna | Tipo | Note |
|---|---|---|
| id | bigint PK | |
| sync_job_id | FK, cascade | |
| triggered_by | string(20) | `schedule`, `manual`, `api`. Non si chiama `trigger` perché è una parola riservata in MySQL |
| dry_run | bool | |
| status | string(20) | `queued`, `running`, `success`, `partial`, `failed`, `skipped` |
| started_at, finished_at | timestamp null | `created_at` corrisponde al momento dell'accodamento |
| created_count, updated_count, deleted_count, skipped_count, error_count | unsigned int, default 0 | |
| errors | json null | Al massimo 100 voci `{op, source, uid, message}`, già sanitizzate |
| plan | json null | Solo per i dry-run: operazioni previste con anteprima (titolo, inizio, fine, sorgente), al massimo 500 voci |
| message | string(500) null | Es. "già in esecuzione" |
| timestamps | | |

Indice su `(sync_job_id, created_at)`. I run vengono eliminati con `Prunable` dopo `CALSYNC_RUN_RETENTION_DAYS` giorni (default 30).

### Tabelle di framework
`personal_access_tokens` (Sanctum), `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions`.

## 5. Decisioni di design

### 5.1 Provider
- L'interfaccia `CalendarProvider` è quella della specifica. `ProviderRegistry` risolve il provider giusto da `Account::type`.
- Il provider CalDAV è uno solo, `CalDavProvider`: iCloud è un CalDAV generico con `base_url` predefinito e un'allowlist di host (§5.7).
- Discovery CalDAV in tre passi:
  1. PROPFIND `current-user-principal` sul `base_url`;
  2. PROPFIND `calendar-home-set` sul principal, risolvendo gli href relativi rispetto all'URL che ha risposto;
  3. PROPFIND con Depth 1 sulla home per leggere `displayname`, `calendar-color`, `supported-calendar-component-set` e `current-user-privilege-set`.
- Shard iCloud: la home restituita (es. `https://p42-caldav.icloud.com:443/123456789/calendars/`) viene salvata e usata tal quale.

### 5.2 Normalizzazione eventi
- `EventDto` contiene i campi della specifica più `transparency` e `markers` (i marker calsync letti dall'evento).
- Lato CalDAV le ricorrenze vengono espanse con l'`EventIterator` di vobject, che gestisce RRULE, EXDATE e override RECURRENCE-ID. L'iteratore conserva il TZID originale, mentre `VCalendar::expand()` converte tutto in UTC.
- Gli eventi floating (senza TZID) prendono la timezone del calendario, o in mancanza quella dell'app.
- Lato Google uso `singleEvents=true`. `recurrenceId` deriva da `originalStartTime` e `uid` da `iCalUID`, normalizzati come in CalDAV.

### 5.3 SyncEngine
La finestra va da oggi − X giorni alle 00:00 fino alla fine del giorno oggi + Y, nella timezone dell'app.

1. **Lettura.** Per ogni sorgente chiamo `listEvents()` e scarto:
   - gli eventi con un marker calsync, di qualsiasi job;
   - gli eventi con stato cancellato;
   - in `busy_only`, anche gli eventi segnati "libero" e, dove il provider lo espone, gli inviti rifiutati.

   Se la lettura di una sorgente fallisce, la sorgente è marcata come non affidabile: l'errore finisce nel run e **nessuna delete** viene eseguita per i suoi eventi.
2. **Trasformazione.** Converto il DTO nel payload per il target, applicando privacy e prefisso.
   - `content_hash` è lo SHA-256 del payload target in forma canonica, non dell'evento sorgente.
   - Così cambiare il prefisso o la modalità privacy aggiorna tutte le copie.
3. **Diff con `event_mappings`.**
   - **create**: evento sorgente senza mapping;
   - **update**: hash diverso da quello salvato;
   - **delete**: mapping senza evento sorgente, con sorgente affidabile e `starts_at` dentro la finestra.

   Gli eventi usciti dalla finestra (cioè passati) non vengono cancellati. Semplicemente il job smette di gestirli.
4. **Apply** (saltato in dry-run).
   - Ogni operazione è isolata in un try/catch.
   - Il mapping viene salvato subito dopo ogni scrittura riuscita.
   - Una delete che riceve 404 o 410 dal target significa che l'evento è già sparito, e la tratto come riuscita.
5. **Esito.** Il run chiude in uno di tre stati:
   - `success`;
   - `partial`, se alcune operazioni sono fallite;
   - `failed`, in caso di errore bloccante come un token scaduto.

`plan()` e `apply()` sono metodi separati: un dry-run esegue solo `plan()`.

Idempotenza:
- due run consecutivi senza modifiche producono zero operazioni;
- gli id deterministici (§5.5) permettono di recuperare un crash avvenuto tra la scrittura sul target e il salvataggio del mapping.

### 5.4 Loop e duplicati
- **Marker.**
  - Google: `extendedProperties.private` con `calsync_job_id`, `calsync_source_uid` e `calsync_source_key`.
  - CalDAV: proprietà `X-CALSYNC-JOB` e `X-CALSYNC-SOURCE`.
- **Marker ridondante nell'identificativo.** UID CalDAV `calsync-<hash>@calendar-sync`, e id Google derivato dall'hash. Se un server scarta le proprietà X- (da verificare su iCloud agli step 3 e 6), il marker sopravvive comunque.
- **Esclusione in lettura.** Qualsiasi evento marcato viene escluso dalle sorgenti, quindi non ci sono loop A → B → A né catene.

  Conseguenza voluta: un job non può usare come sorgente le copie create da un altro job.
- **Proprietà.**
  - Un job aggiorna o cancella solo gli eventi presenti nei propri `event_mappings`.
  - Un mapping può essere "adottato" da un evento già presente nel target solo se l'evento porta il marker di quello stesso job. Serve a recuperare un crash.
- **Deduplica nel merge**, configurabile per job. Lo stesso UID può comparire in più sorgenti, per esempio una riunione a cui sono invitati entrambi gli account.
  - Con `allow_duplicates = false` (default) viene creata una sola copia, con i dati della sorgente con `position` più bassa.
  - Con `skip_existing_in_target = true` vengono saltati anche gli eventi il cui UID esiste già nel target come evento non creato dal job.
  - Cambiare `allow_duplicates` cambia le chiavi dei mapping: al run successivo le copie vengono ricreate.
- **Test dedicato.** Google → Google sullo stesso account, con due job A → B e B → A: lo stato deve restare stabile dopo N run.

### 5.5 Scrittura sul target
- **Mai partecipanti.** Le copie non contengono mai ATTENDEE né ORGANIZER, altrimenti iCloud e Google invierebbero inviti via email. Su Google uso anche `sendUpdates=none`.
- **Mai promemoria.** Niente VALARM, e su Google `reminders.useDefault=false`, per non duplicare le notifiche.
- **Ricorrenze come istanze singole.** Sul target ogni occorrenza diventa un evento a sé.
  - Pro: eccezioni ed EXDATE gestite senza logica aggiuntiva, idempotenza per singola istanza, stessa logica per tutti i provider.
  - Contro: il target contiene più eventi, ma il numero è limitato dalla finestra.
- **Id deterministici.**
  - CalDAV: PUT su `<calendario>/calsync-<hash>.ics` con `If-None-Match: *`.
  - Google: campo `id` in base32hex derivato dall'hash. Un 409 significa che l'evento esiste già, e il job riprende da lì.

### 5.6 Coda, lock, scheduler
- **Esecuzione.** `SyncJobRunner(syncRunId)` gira sulla coda `database` (queue `sync`). Il SyncRun viene creato in stato `queued` prima del dispatch, così API e GUI hanno subito un id da mostrare.
- **Lock.** `Cache::lock("calsync:job:{id}", 900)` in modalità non bloccante. Se il lock è occupato, il run passa a `skipped` con il messaggio "già in esecuzione". Lo store dei lock è `database`, condiviso tra web e worker.
- **Retry.** Il worker usa `tries=1`: il sync è idempotente e il giro successivo ritenta da solo. Questo evita tempeste di retry quando le credenziali sono sbagliate.
- **Scheduler.** Un solo comando, `calsync:dispatch-due`, gira ogni minuto con `withoutOverlapping`.
  - Seleziona i job `enabled` con `next_run_at <= now` e nessun run in stato queued o running.
  - Li accoda e sposta in avanti `next_run_at`.
  - È più semplice e robusto che registrare una voce di schedule per ogni job.
  - Ogni giorno gira anche `model:prune` sui SyncRun vecchi.

### 5.7 Account e token
- **OAuth Google.**
  - Web flow con `access_type=offline` e `prompt=consent`, che garantisce sempre il refresh token.
  - Il parametro `state` è casuale, salvato in sessione e verificato con `hash_equals`.
  - Scope unico: `https://www.googleapis.com/auth/calendar`.
  - L'email dell'account la ricavo da `calendarList.get('primary')`, senza chiedere altri scope.
- **Refresh.** Il refresh è automatico e il refresh token è salvato cifrato.
  - Se Google risponde `invalid_grant`, l'account passa a `token_expired`.
  - I run falliscono con un messaggio chiaro.
  - La GUI mostra un banner rosso su tutte le pagine con il pulsante "Riconnetti".
- **CalDAV.** Un 401 porta l'account a `auth_failed`, per esempio perché l'app password è stata revocata.
- **Dove vanno le credenziali CalDAV.** Solo all'host configurato oppure, per iCloud, agli host `*.icloud.com` restituiti dalla discovery.
- **Redirect CalDAV.** Li gestisco a mano, perché su 301 e 302 Guzzle trasformerebbe un PROPFIND in GET.

### 5.8 Sicurezza
- **Cifratura.**
  - Il cast `encrypted:array` usa AES-256 con `APP_KEY`.
  - `APP_PREVIOUS_KEYS` permette di ruotare la chiave.
  - `credentials` è in `$hidden`, quindi non compare mai in `toArray()`, nel JSON o negli export.
- **Form.** Nei form il campo password è di sola scrittura: lasciarlo vuoto significa "invariato", e il valore salvato non viene mai ricaricato nel form.
- **Stack trace.** Uso `#[\SensitiveParameter]` sui parametri che ricevono segreti, così spariscono dagli stack trace.
- **Redazione.** Un `SecretRedactor` filtra i messaggi d'errore salvati e i log. Maschera:
  - gli header Authorization e Cookie;
  - le stringhe `Bearer …` e `Basic …`;
  - i segreti noti dell'account.
- **Log HTTP.** Opzionale (`CALSYNC_HTTP_LOG=false`), sempre con gli header mascherati.
- **GUI.**
  - Autenticazione Filament e protezione CSRF.
  - Nessuna registrazione e nessun reset password.
  - Login con rate limit.
- **API.**
  - Sanctum con abilities.
  - Rate limit: 60 richieste al minuto in lettura, 10 al minuto per i run.
  - Range massimo di 90 giorni.
- **Produzione.**
  - `APP_DEBUG=false`.
  - Cookie `secure` e HSTS.
  - `.env` con permessi 0640.
  - `APP_KEY` generata sul server, mai nel repo.

### 5.9 Lingua e timezone
- La GUI è in italiano (`APP_LOCALE=it`; Filament include le traduzioni).
- Codice e commenti sono in inglese, la documentazione operativa in italiano.
- Il DB è in UTC. La GUI e la finestra di sync usano `Europe/Rome`, configurabile.

## 6. Step

| Step | Contenuto | Verifica |
|---|---|---|
| 1 | Laravel 13 nella root del repo, Filament 5, Sanctum. Migration, modelli con cast cifrati, enum, `calsync:create-admin`, login | `php artisan test`, `migrate:fresh`, login su `/admin` |
| 2 | `CalDavClient`, `MultistatusParser`, discovery. Comandi `calsync:add-caldav-account` (crea l'account da CLI con password nascosta, serve finché non c'è la GUI) e `calsync:test-account {id}` | Test con `Http::fake` su risposte 207 realistiche: iCloud con shard, server generico, 401, redirect. Poi lo esegui tu su iCloud reale |
| 3 | REPORT `calendar-query`, `ICalendarMapper`, espansione ricorrenze. Comando `calsync:list-events {calendar} --from --to` | Unit test del parser: evento singolo, all-day, ricorrente con eccezione ed EXDATE, TZID diverso, floating. Poi tu su iCloud reale |
| 4 | Filament: Account (CRUD per tipo, Test connessione, Scopri calendari), relation manager Calendari, pagina Anteprima eventi, banner di stato degli account | Test Livewire/Filament e prova manuale |
| 5 | `GoogleProvider`: OAuth (redirect e callback), discovery, lettura e scrittura con marker, refresh e scadenza del token. Guida per Google Cloud Console | Test con `MockHandler`. Poi colleghi tu un account reale |
| 6 | `SyncEngine` (`plan` e `apply`), `EventTransformer`, CRUD SyncJob, dry-run nella GUI | Test feature: create, update, delete, merge, finestra temporale, sorgente in errore, Google → Google sullo stesso account senza loop |
| 7 | `SyncJobRunner`, lock, `calsync:dispatch-due`, "Esegui ora", storico SyncRun, azione "Rimuovi copie del job" | Test di coda, lock e scheduler. Prova reale con `queue:work` |
| 8 | API per n8n, gestione dei token nella GUI, rate limiting | Test feature: auth, abilities, range, cache, risposta 202 e polling del run |
| 9 | `deploy/` (nginx, pool php-fpm, systemd per coda e scheduler, cron alternativo, env di esempio) e README operativo | Checklist di deploy sul tuo LXC |

## 7. Decisioni dalle risposte (2026-10-07)

1. **Debian 12.** PHP 8.4 arriva dal repo Sury.
2. **MySQL.** Server MySQL gestito da te su un container separato, driver `mysql`. Versione: 8.0.45. Le migration dello step 1 sono applicate e funzionanti.
3. **Dominio su Cloudflare**, già usato per n8n e Home Assistant. Allo step 9 documento due configurazioni:
   - Cloudflare Tunnel, con TLS terminato da Cloudflare e nginx in HTTP nel container;
   - in alternativa, proxy DNS con certificato origin.

   In entrambi i casi servono:
   - `TrustProxies`, così Laravel genera URL `https` e il redirect URI OAuth è corretto;
   - l'IP reale da `CF-Connecting-IP`, perché il rate limit del login funzioni.

   Valutare Cloudflare Access davanti alla GUI. n8n chiamerà l'API sulla rete interna.
4. **Duplicati configurabili per job:** `allow_duplicates` e `skip_existing_in_target` (§4, §5.4).
5. **Progetto nella root del repo**, git locale con un commit per step. Il push verso Gitea lo gestisci tu.

## 8. Note di implementazione

- **Step 1.**
  - Laravel 13 usa gli attributi PHP (`#[Fillable]`, `#[Hidden]`, `#[Signature]`) al posto delle proprietà: il codice segue questo stile.
  - Lo skeleton includeva `AGENTS.md` e `CLAUDE.md`, con istruzioni per installare Laravel Boost. Li ho rimossi perché non richiesti.
  - Test: Pest 5 (PHPUnit 13), SQLite in memoria.
- **Step 2.**
  - L'interfaccia `CalendarProvider` cresce con gli step. Ora contiene `testConnection` e `discoverCalendars`; `listEvents` arriva allo step 3, le scritture agli step 5 e 6. Così non restano metodi vuoti.
  - I provider non scrivono mai sul DB: la persistenza passa da `AccountService`, che userà anche la GUI.
  - Errori di rete (`ServerUnreachable`) e credenziali rifiutate (`AuthenticationFailed`) non fanno tentare `/.well-known/caldav`. Il fallback scatta solo se il server risponde, ma non indica il principal.
  - I calendari vengono abbinati per URL completo e, in mancanza, per path. Così, se iCloud sposta l'account su un altro shard, i calendari mantengono il loro id.
  - Un calendario che non dichiara `current-user-privilege-set` viene considerato scrivibile. Se poi una scrittura fallisce, l'errore compare nel run.
  - Diagnostica HTTP con header mascherati: opzione `calsync:test-account --debug` e variabile `CALSYNC_HTTP_LOG=true` per scrivere nel log.
  - Ogni test chiama `Http::preventStrayRequests()`, quindi nessun test può raggiungere un server reale.
- **Step 3.**
  - `listEvents` restituisce un `EventList`: gli eventi, più l'elenco delle risorse che non è stato possibile leggere. Una lista con problemi è *incompleta*, e allo step 6 il SyncEngine non cancellerà copie sulla base di una lista incompleta.
  - Ricorrenze:
    - vobject conta le iterazioni dall'inizio della serie e si ferma a 3500 di default. Il limite è alzato a 50.000; oltre quella soglia l'evento finisce tra i problemi invece di bloccare tutto.
    - Le eccezioni salvate senza l'evento principale vengono gestite a mano, per conservare il loro `RECURRENCE-ID` originale.
    - Gli eventi all-day vengono ricalcolati a giorni interi, perché vobject misura le durate in secondi e a cavallo del cambio d'ora le sbaglierebbe di un'ora.
  - Il formato di `recurrenceId` è sempre l'istante originale in UTC (`20261019T070000Z`), oppure la sola data (`20261012`) per gli all-day.
  - Gli orari floating usano la timezone del calendario; se manca o non è valida, quella dell'app.
  - I TZID in stile Windows/Outlook vengono mappati da vobject su zone IANA, per esempio "W. Europe Standard Time" diventa Europe/Berlin.
  - Nuovo comando `calsync:list-events {calendar} --from --to`.
- **Step 4.**
  - Resource Account in Filament 5, con form, infolist e tabella in classi separate, come genera la v5.
  - Username e password sono campi virtuali, mappati sul campo cifrato `credentials` nelle pagine Create ed Edit. La password non viene mai caricata nel form; dopo un salvataggio viene tolta anche dallo stato Livewire che torna al browser.
  - Alla creazione la discovery parte subito. Se in modifica cambiano le credenziali o l'URL, la connessione viene ritestata.
  - Non si può eliminare un account i cui calendari sono usati da un job. Il tipo di account non è modificabile.
  - Il tipo Google non compare ancora tra le opzioni: arriva con l'OAuth allo step 5. Il banner gestisce già lo stato `token_expired`, con il link "Riconnetti".
  - Pagina "Anteprima eventi": stessa lettura che userà il SyncEngine. Range massimo di 90 giorni, ricerca, paginazione fatta a mano (Filament non pagina da solo i dati da array).
  - Verifica visiva fatta nel browser integrato, con un SQLite temporaneo e un finto server CalDAV locale.
- **Step 5.**
  - La scrittura è un'interfaccia separata, `WritableCalendarProvider`. Google la implementa già; CalDAV la implementerà allo step 6, insieme al SyncEngine. `ProviderRegistry::writerFor()` restituisce il provider in scrittura e solleva un'eccezione se il tipo di account non la supporta.
  - OAuth: authorization code con PKCE, `state` monouso in sessione verificato con `hash_equals`, `prompt=consent` e `access_type=offline`. In più:
    - si controlla che l'utente abbia lasciato spuntato il permesso del calendario (consenso granulare);
    - si controlla la presenza del refresh token;
    - una riconnessione verso un'email Google diversa da quella già collegata viene rifiutata.
  - Il flusso parte solo se il pannello è aperto sullo stesso host del redirect URI. Altrimenti il cookie di sessione andrebbe perso al ritorno, per esempio tra `127.0.0.1` e `localhost`.
  - Il rinnovo dell'access token avviene prima di ogni uso, con 60 secondi di margine, e il nuovo token viene salvato subito: è l'unica scrittura sul DB sotto il livello dei servizi. Un `invalid_grant` porta l'account a `token_expired`.
  - Copie su Google:
    - id deterministico, `sha256("calsync:{job}:{source_key}")`;
    - in caso di 409 (id già usato, anche da un evento cancellato) la copia viene ripresa solo se porta il marker dello stesso job;
    - `sendUpdates=none`, nessun partecipante, `reminders.useDefault=false`, marker in `extendedProperties.private`.
  - Lettura da Google: le voci "working location" e le istanze cancellate vengono scartate. `EventDto::$declined` segnala gli inviti rifiutati, grazie a `maxAttendees=1` che fa restituire solo il partecipante proprietario dell'account.
  - `google/apiclient` mantiene solo il servizio Calendar. Retry automatico su errori 5xx e rate limit (3 tentativi; 0 nei test).
  - Guida per la console: [docs/google-oauth.md](google-oauth.md).

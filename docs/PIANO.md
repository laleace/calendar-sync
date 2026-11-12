# calendar-sync — Piano (Step 0)

> Stato: **approvato il 2026-10-07.** Step completati: tutti (0-7, 9, 10 e 11; lo step 8 è stato eliminato).
> **2026-10-08: niente API per n8n.** L'app si limita a sincronizzare e aggregare calendari. Lo step 8 è stato eliminato e Sanctum rimosso (§7, punto 6).
> **2026-11-12: rilascio open source su GitHub** (AGPL-3.0, donazioni). Piano negli step 12-17, §9, approvato. Step completati: 12, 13, 14, 15, 16.
> Questo file resta come riferimento per tutti gli step. Lo aggiorno quando una decisione cambia.

## 1. Ambiente rilevato

- La cartella `CalendarAggregator/` è vuota (contiene solo `.claude/`) e non è un repo git.
- In locale ci sono PHP 8.4.1 (Herd Lite), Composer 2.8, Node 24 e SQLite. MySQL non c'è.
- Versioni stabili su Packagist al 2026-10-07:

| Pacchetto | Versione | Requisiti |
|---|---|---|
| Laravel | 13.35 | PHP ≥ 8.3 |
| Filament | 5.10 | Livewire 4, Laravel 11.28 / 12 / 13 |
| google/apiclient | 2.20 | |
| sabre/vobject | 4.6.1 e 5.0.0 | |
| Pest | 5 | |

## 2. Stack

| Componente | Scelta | Note |
|---|---|---|
| PHP | 8.4 | Il container è Debian 12: PHP 8.4 dal repo Sury (packages.sury.org) |
| Framework | Laravel 13 | Skeleton puro (`laravel/laravel`) senza starter kit, perché l'autenticazione la fornisce Filament |
| GUI | **Filament 5** | Vedi §2.1 |
| DB | MySQL in produzione, SQLite in dev e test | Connessione via env. Migration portabili, senza funzioni specifiche di un solo DB |
| Coda, cache, sessioni, lock | Driver `database` | Niente Redis da installare e gestire |
| CalDAV | Facade `Http` + `DOMDocument`/`DOMXPath` + `sabre/vobject` ^5.0 | Scelta allo step 3: stessa API della 4.6, con i tipi PHP moderni |
| Google | `google/apiclient` con il solo servizio Calendar | Il cleanup di Composer rimuove le altre ~200 API |
| Test | Pest | CalDAV con `Http::fake`. Google con un handler Guzzle finto (`FakeGoogle`), perché il client Google usa Guzzle direttamente e non la facade. SyncEngine con un provider in-memory. Le richieste reali, sia CalDAV sia Google, sono bloccate in tutti i test |

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

### 2.2 API per n8n: rimosse

La specifica iniziale prevedeva API protette da Sanctum, per permettere a n8n di avviare i sync e leggere gli eventi. Il 2026-10-08 sono state tolte: lo scheduler interno e il pulsante "Esegui ora" coprono tutti i casi d'uso. Se un giorno serviranno, lettura eventi e sync sono già servizi riutilizzabili.

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
│   ├── Console/Commands/        # CreateAdmin, AddCalDavAccount, TestAccount, ListEvents, DispatchDue
│   ├── Filament/
│   │   ├── Resources/           # AccountResource (+Calendars RM), SyncJobResource (+SyncRuns RM), SyncRunResource
│   │   ├── Pages/               # Dashboard, CalendarPreview
│   │   └── Widgets/             # AccountHealth, RecentRuns
│   └── Http/Controllers/
│       └── GoogleOAuthController.php
├── database/migrations/
├── routes/{web,console}.php
├── tests/
│   ├── Fixtures/{caldav,ics,google}/   # risposte 207 XML, file ICS, JSON Google realistici
│   ├── Unit/                    # parser XML, mapper ICS, transformer, redactor
│   └── Feature/                 # comandi, engine, GUI, OAuth
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
| group_id | FK → sync_groups null, null on delete | Step 11. Solo organizzativo |
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
| group_id | FK → sync_groups null, null on delete | Step 11. Solo organizzativo |
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
| filters | json null | Regole di inclusione ed esclusione sugli eventi sorgente (step 10, §5.3) |
| next_run_at | timestamp null, indice | Usato dallo scheduler |
| last_run_at | timestamp null | |
| timestamps | | |

### sync_groups (step 11)
| Colonna | Tipo | Note |
|---|---|---|
| id | bigint PK | |
| name | string(50), unique | Es. "Personale", "Utente X". Unicità senza distinguere maiuscole, controllata dal form |
| timestamps | | |

Il nome della tabella non è `groups` perché GROUPS è una parola riservata in MySQL 8.

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
| triggered_by | string(20) | `schedule`, `manual`. Non si chiama `trigger` perché è una parola riservata in MySQL |
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
`jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions`.

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
   - in `busy_only`, anche gli eventi segnati "libero" e, dove il provider lo espone, gli inviti rifiutati;
   - gli eventi che non passano i filtri del job (step 10).

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
- **Esecuzione.** `SyncJobRunner(syncRunId)` gira sulla coda `database` (queue `sync`). Il SyncRun viene creato in stato `queued` prima del dispatch, così la GUI ha subito un id da mostrare.
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
- **Produzione.**
  - `APP_DEBUG=false`.
  - Cookie `secure` e HSTS.
  - `.env` con permessi 0640.
  - `APP_KEY` generata sul server, mai nel repo.

### 5.9 Lingua e timezone
- La GUI è in italiano o in inglese, scelta con `APP_LOCALE` (default `it`; Filament include le traduzioni). Dallo step 13 i testi stanno in `lang/it.json` (§9.3).
- Codice e commenti sono in inglese, la documentazione operativa in italiano.
- Il DB è in UTC. La GUI e la finestra di sync usano `Europe/Rome`, configurabile.

## 6. Step

| Step | Contenuto | Verifica |
|---|---|---|
| 1 | Laravel 13 nella root del repo, Filament 5. Migration, modelli con cast cifrati, enum, `calsync:create-admin`, login | `php artisan test`, `migrate:fresh`, login su `/admin` |
| 2 | `CalDavClient`, `MultistatusParser`, discovery. Comandi `calsync:add-caldav-account` (crea l'account da CLI con password nascosta, serve finché non c'è la GUI) e `calsync:test-account {id}` | Test con `Http::fake` su risposte 207 realistiche: iCloud con shard, server generico, 401, redirect. Poi lo esegui tu su iCloud reale |
| 3 | REPORT `calendar-query`, `ICalendarMapper`, espansione ricorrenze. Comando `calsync:list-events {calendar} --from --to` | Unit test del parser: evento singolo, all-day, ricorrente con eccezione ed EXDATE, TZID diverso, floating. Poi tu su iCloud reale |
| 4 | Filament: Account (CRUD per tipo, Test connessione, Scopri calendari), relation manager Calendari, pagina Anteprima eventi, banner di stato degli account | Test Livewire/Filament e prova manuale |
| 5 | `GoogleProvider`: OAuth (redirect e callback), discovery, lettura e scrittura con marker, refresh e scadenza del token. Guida per Google Cloud Console | Test con `MockHandler`. Poi colleghi tu un account reale |
| 6 | `SyncEngine` (`plan` e `apply`), `EventTransformer`, CRUD SyncJob, dry-run nella GUI | Test feature: create, update, delete, merge, finestra temporale, sorgente in errore, Google → Google sullo stesso account senza loop |
| 7 | `SyncJobRunner`, lock, `calsync:dispatch-due`, "Esegui ora", storico SyncRun, azione "Rimuovi copie del job" | Test di coda, lock e scheduler. Prova reale con `queue:work` |
| 8 | ~~API per n8n~~: eliminato il 2026-10-08 | — |
| 9 | `deploy/` (nginx, pool php-fpm, systemd per coda e scheduler, cron alternativo, env di esempio, script di aggiornamento) e README operativo. Configurazione solo LAN | `calsync:doctor` e checklist in `deploy/README.md` |
| 10 | Filtri per job: regole "copia solo" ed "escludi" su titolo, luogo, note, giorno, ora di inizio, tutto il giorno, libero, provvisorio, ricorrente. Opzione per togliere il prefisso filtrato dal titolo della copia | Test unitari delle regole, test del motore e del form. Poi dry-run su un job reale |
| 11 | Gruppi per organizzare account e job (es. "Personale", "Utente X"): pagina Gruppi, campo nei form con creazione al volo, colonna, filtro e assegnazione multipla nelle liste, filtro nello storico | Test Filament. Poi assegni i gruppi agli account e ai job esistenti |

## 7. Decisioni dalle risposte (2026-10-07)

1. **Debian 12.** PHP 8.4 arriva dal repo Sury.
2. **MySQL.** Server MySQL gestito da te su un container separato, driver `mysql`. Versione: 8.0.45. Le migration dello step 1 sono applicate e funzionanti.
3. **Dominio su Cloudflare**, già usato per n8n e Home Assistant. Allo step 9 documento due configurazioni:
   - Cloudflare Tunnel, con TLS terminato da Cloudflare e nginx in HTTP nel container;
   - in alternativa, proxy DNS con certificato origin.

   In entrambi i casi servono:
   - `TrustProxies`, così Laravel genera URL `https` e il redirect URI OAuth è corretto;
   - l'IP reale da `CF-Connecting-IP`, perché il rate limit del login funzioni.

   Valutare Cloudflare Access davanti alla GUI.
4. **Duplicati configurabili per job:** `allow_duplicates` e `skip_existing_in_target` (§4, §5.4).
5. **Progetto nella root del repo**, git locale con un commit per step. Il push verso Gitea lo gestisci tu.
6. **Niente API per n8n** (decisione del 2026-10-08). Lo scopo dell'app è solo sincronizzare e aggregare calendari. Rimossi Sanctum, `routes/api.php` e il trigger `api` dei run. La migration `drop_personal_access_tokens_table` elimina la tabella dai database già migrati.
7. **Filtri per job** (decisione del 2026-10-09). Valgono per tutte le sorgenti del job: per filtri diversi su sorgenti diverse si creano più job verso la stessa destinazione. Con l'opzione "togli il prefisso", la copia di "[Lavoro] Riunione" si chiama "Riunione".
8. **Gruppi** (decisione del 2026-10-09). Il pannello lo usi solo tu: i gruppi servono a tenere separate le sincronizzazioni personali da quelle fatte per altri, senza utenti né permessi separati. Si chiamano "Gruppo", non "Cliente".

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
- **Step 6.**
  - Scrittura CalDAV:
    - PUT con `If-None-Match: *` su una risorsa con nome deterministico `calsync-{hash}.ics` e UID `calsync-{hash}@calendar-sync`;
    - orari in UTC, quindi senza VTIMEZONE; marker `X-CALSYNC-JOB`, `X-CALSYNC-SOURCE` e `X-CALSYNC-KEY`;
    - in caso di 412 la risorsa viene ripresa solo se porta il marker dello stesso job;
    - update con PUT incondizionato; delete che ignora 404 e 410.
  - Struttura del motore: `SyncEngine::plan()` legge e confronta, `apply()` scrive, `SyncService` registra il SyncRun. `SyncService::execute(SyncRun)` è pronto per il runner in coda dello step 7.
  - Eventi esclusi dalle sorgenti:
    - quelli con marker calsync, di qualsiasi job;
    - quelli annullati;
    - gli inviti rifiutati, in entrambe le modalità;
    - in `busy_only`, quelli segnati come liberi.
  - Regole di sicurezza:
    - si eliminano solo copie presenti nei mapping del job;
    - nessuna delete per le sorgenti illeggibili o incomplete;
    - una sorgente che risulta improvvisamente vuota, pur avendo almeno 3 copie nel periodo, viene trattata come inaffidabile;
    - le copie uscite dalla finestra temporale restano dove sono.
  - Casi gestiti:
    - cambio del target: le copie vengono spostate;
    - sorgente rimossa dal job: le sue copie vengono cancellate;
    - errori di credenziali o di rete sul target: il run si ferma e lo stato dell'account viene aggiornato;
    - update su una copia cancellata a mano: la copia viene ricreata.
  - ~~Limite noto: una copia cancellata a mano torna solo quando la sorgente cambia.~~ Risolto allo step 7: il target viene riletto a ogni run.
  - Il dry-run ha una pagina dedicata (`/admin/sync-jobs/{id}/dry-run`): esegue il piano all'apertura, lo salva come SyncRun `dry_run` e lo mostra filtrabile. Si è scelta una pagina invece di un modale perché il modale rischierebbe di rieseguire le letture a ogni render.
  - I calendari omonimi nello stesso account vengono distinti nelle select con timezone e id.
- **Step 7.**
  - Esecuzione:
    - `SyncService::queue()` crea il run in stato `queued` e lo affida a `SyncJobRunner` sulla coda `sync`;
    - un `Cache::lock("calsync:job:{id}")` impedisce due sync contemporanei dello stesso job: il secondo diventa `skipped`;
    - il worker usa `tries=1` e `timeout` di 600 s, con lock da 900 s; `failed()` marca il run come fallito.
  - Scheduler:
    - `calsync:dispatch-due` gira ogni minuto con `withoutOverlapping`;
    - non accoda un nuovo run se il precedente è ancora in coda o in esecuzione;
    - marca come falliti i run `running` da più di 30 minuti (worker morto) e quelli `queued` da più di 6 ore (nessun worker).
  - Ripristino delle copie: il target viene riletto a ogni run. Una copia mancante, riconosciuta per chiave del marker o per id remoto, viene ricreata. Se il target non è leggibile niente ripristino, salvo con `skip_existing_in_target`, che in quel caso fa fallire il run perché rischierebbe di creare doppioni.
  - "Rimuovi copie": un run `purge` (nuova colonna `sync_runs.purge`) elimina tutte le copie del job, anche fuori finestra, e disattiva il job.
  - Comando `calsync:run {job} [--dry-run]` per lanciare un job da terminale. Usa lo stesso lock del worker.
  - GUI:
    - azione "Esegui ora", disattivata se c'è già un run in corso;
    - "Rimuovi copie" con conferma;
    - "Storico run": lista aggiornata ogni 5 s, filtri, dettaglio con errori e operazioni, eseguite o previste;
    - storico nella pagina di modifica del job;
    - widget in dashboard: statistiche e ultimi run;
    - banner se ci sono run in coda da più di 2 minuti.
  - `php artisan dev` avvia server, worker sulle code `sync,default`, scheduler e log. Rimosso lo scaffold frontend (Vite, `package.json`), che Filament non usa.
  - Anche i sync veri salvano l'elenco delle operazioni in `sync_runs.plan`, così lo storico mostra cosa è stato creato, aggiornato o eliminato.
  - Verifica end-to-end su HTTP reale contro un finto server CalDAV: scheduler → worker → 11 PUT; secondo giro con sole 2 letture; copia cancellata a mano ripristinata; rimozione di tutte le copie.
- **Step 9.**
  - Rete: solo LAN (decisione del 2026-10-08). Record DNS Cloudflare "DNS only" verso l'IP privato e certificato Let's Encrypt con challenge DNS-01 (token Cloudflare limitato a una zona). nginx accetta solo reti private e localhost; localhost serve per un eventuale futuro Cloudflare Tunnel.
  - nginx 1.22 di Debian 12, quindi `listen 443 ssl http2` e non `http2 on`. Serve solo `index.php`, mai file nascosti o altri PHP. HSTS e header di sicurezza.
  - PHP-FPM ha un pool dedicato con utente `calsync`, socket proprio e `pm=ondemand`. OPcache ha `validate_timestamps=0`: `update.sh` ricarica FPM.
  - systemd: worker della coda (`--timeout=660 --max-time=3600`) e scheduler (`schedule:work`), con cron come alternativa. Il sandboxing extra resta commentato, perché nei container unprivileged può fallire senza `nesting=1`.
  - Bug evitato: con il default di Laravel (`retry_after` a 90 s) un sync lungo sarebbe stato consegnato a un secondo worker mentre era ancora in corso. Ora vale 900 s, sopra il timeout di 600 s del runner, e un test lo garantisce.
  - `calsync:doctor` controlla APP_KEY, debug, HTTPS, cookie sicuri, `retry_after`, store dei lock, OAuth e redirect URI, database, admin, battito dello scheduler e coda ferma.
  - Database di produzione separato da quello di sviluppo, altrimenti il `php artisan dev` del Mac eseguirebbe i job di produzione. `APP_KEY` va salvata: cifra le credenziali.
- **Step 10.**
  - Le regole stanno nella colonna JSON `sync_jobs.filters`: `include`, `include_mode` (`any` o `all`), `exclude` e `strip_prefix`. `EventFilters` e `FilterRule` le interpretano; le regole incomplete o non valide vengono ignorate, ma il form non le lascia salvare.
  - Si filtra l'evento originale, prima della privacy e del prefisso del job. Un evento che corrisponde a una regola di esclusione viene saltato anche se rispetta quelle di inclusione.
  - Il testo si confronta senza distinguere maiuscole e minuscole. L'espressione regolare si scrive senza delimitatori; viene validata al salvataggio, e un errore durante l'esecuzione conta come "non corrisponde".
  - Giorno della settimana e ora di inizio si valutano nella timezone dell'app. Gli eventi di tutto il giorno mantengono la loro data e non rientrano mai in una fascia oraria; una fascia con "Alle" prima di "Dalle" scavalca la mezzanotte.
  - I filtri si applicano prima della scelta tra sorgenti duplicate: se l'evento viene escluso nella prima sorgente, vale la copia della seconda.
  - Le copie di eventi che smettono di passare i filtri vengono cancellate con motivo "escluso dai filtri del job". La protezione "sorgente vuota" conta gli eventi letti dalla sorgente, non quelli rimasti dopo i filtri.
  - "Togli il prefisso" usa solo le regole di inclusione "Titolo inizia con": vince il prefisso più lungo, si tolgono anche i separatori che seguono (`:`, `-`, `–`, `|`) e un titolo fatto solo del prefisso resta com'è. Cambiare l'opzione aggiorna le copie esistenti, perché cambia l'hash.
  - Le ore della fascia oraria non passano per la conversione di fuso del TimePicker di Filament: sono ore del giorno, non istanti.
  - Nella lista dei job la colonna "Filtri" mostra il numero di regole.
- **Step 11.**
  - Modello `Group` (tabella `sync_groups`) e `group_id` facoltativo su `accounts` e `sync_jobs`. Eliminando un gruppo, account e job restano senza gruppo. Il sync ignora i gruppi.
  - `App\Filament\Support\GroupFields` raccoglie i pezzi in comune tra account e job: la select con creazione e modifica al volo, la colonna, il filtro (con "Senza gruppo") e l'azione di massa "Assegna gruppo".
  - L'azione di massa fa un `update` diretto sul database: nessun evento dei modelli, e le credenziali cifrate restano intatte.
  - I filtri delle liste restano salvati in sessione. I numeri nella pagina Gruppi aprono le liste già filtrate (`?filters[group][value]=…`).
  - Nel form del job, se il gruppo è vuoto e sorgenti e destinazione appartengono tutte ad account dello stesso gruppo, il gruppo viene proposto.
  - Nelle select dei calendari l'intestazione diventa "Gruppo · Account", così gli account dello stesso gruppo stanno vicini.
  - Lo storico dei run si filtra per gruppo del job.

## 9. Rilascio open source (proposto il 2026-11-12)

### 9.1 Decisioni
1. **Pubblicazione gratuita su GitHub** (account `laleace`), con donazioni tramite GitHub Sponsors e/o Ko-fi (`.github/FUNDING.yml`). Niente SaaS: ogni utente installa la propria istanza e crea il proprio client OAuth Google per uso personale, quindi a noi non serve la verifica dell'app.
2. **Licenza AGPL-3.0.** Chi offre l'app come servizio online deve pubblicare le modifiche. Essendo l'unico autore, resta possibile una futura licenza commerciale.
3. **Storia git intera**, con gli autori riscritti su un'unica identità: `Alessandro <1488206+laleace@users.noreply.github.com>`. Gli hash cambiano: Gitea riceve un force push e sul container serve una volta `git fetch` + `git reset --hard origin/main` (fatto da te, allo step 17).
4. **Il deploy di produzione non cambia.** Il container continua a fare pull da Gitea con `deploy/update.sh`. GitHub è un secondo remote.
5. **Pubblico internazionale.** GUI tradotta (italiano e inglese, scelti con `APP_LOCALE`), README e documentazione in inglese con versione italiana, Docker Compose come installazione principale. Il deploy LXC resta come guida "bare metal", resa generica.
6. **Fuori dal repository pubblico:** `DEPLOY_ANALYSIS.md` e `deploy-kit/` (non tracciati). Se in futuro il progetto adotta il Deploy-Kit, sarà da decidere se la copia del kit può diventare pubblica.

### 9.2 Step

| Step | Contenuto | Verifica |
|---|---|---|
| 12 | Pulizia dati personali: hostname, IP di LAN, Gitea e FRITZ!Box diventano placeholder o esempi generici in `deploy/`, `docs/` e `.env.example`. File `LICENSE` (AGPL-3.0), intestazione `license` in `composer.json`, `SECURITY.md`. Pagina privacy generica (vale per chiunque installi l'app) | `git grep` senza riferimenti personali, test verdi, `update.sh` invariato |
| 13 | GUI tradotta: testi di Filament, enum, notifiche, messaggi di stato ed errori in `lang/it` e `lang/en`. Default `it`, come oggi. `busy_title` predefinito tradotto. Test che nessuna chiave manchi in una delle due lingue | Test verdi in entrambe le lingue, controllo visivo del pannello in inglese |
| 14 | Docker: `Dockerfile` (PHP 8.4 + nginx o FrankenPHP), `docker-compose.yml` con app, worker, scheduler e MySQL (variante SQLite), volume per lo storage, `APP_KEY` generata al primo avvio, `calsync:doctor` come healthcheck. Immagine pubblicata su GitHub Container Registry | `docker compose up` da zero, login, account CalDAV di prova, sync, riavvio senza perdita dati |
| 15 | Documentazione: `README.md` in inglese (funzioni, screenshot, installazione Docker e bare metal, Google OAuth, FAQ) e `README.it.md`. `docs/google-oauth.md` e la guida HTTPS anche in inglese. `CONTRIBUTING.md` breve, `FUNDING.yml`, template per le issue. `PIANO.md` resta in italiano come documento di progetto | Rilettura; i link funzionano |
| 16 | CI su GitHub Actions: test Pest e Pint a ogni push e pull request, build dell'immagine Docker sui tag | Workflow verde su un branch di prova |
| 17 | Pubblicazione: riscrittura degli autori, force push su Gitea (dopo il tuo OK), riallineamento del container (lo fai tu), creazione del repository pubblico su GitHub, push, tag `v1.0.0`, release, attivazione di Sponsors | Repository visibile, CI verde, container aggiornato con `calsync:doctor` OK |

### 9.3 Note di implementazione
- **Step 12.**
  - Licenza `AGPL-3.0-only`; il pacchetto Composer si chiama `laleace/calendar-sync`, come il futuro repository GitHub.
  - La pagina privacy vale per qualsiasi installazione e linka il codice sorgente (`CALSYNC_SOURCE_URL`), come chiede la sezione 13 dell'AGPL a chi offre l'app in rete. Chi pubblica una versione modificata punta la variabile al proprio fork.
  - La guida di deploy clona dal repository pubblico via HTTPS; la deploy key resta per i repository privati. Hostname e IP reali sostituiti da esempi (`calsync.miodominio.it`, `192.168.1.50`, `192.168.1.51`). Le installazioni esistenti non cambiano: `update.sh` è invariato e il `.env` di produzione non è toccato.
  - `SECURITY.md` in inglese: segnalazioni tramite il private vulnerability reporting di GitHub, da attivare allo step 17.
  - `composer audit` segnala 11 advisory su 6 pacchetti (Livewire 4.4.7 e componenti Symfony). Da aggiornare con un `composer update` prima della pubblicazione.
- **Step 13.**
  - Chiavi in inglese nel codice (`__('Run now')`) e traduzione italiana in `lang/it.json`, 408 voci. L'inglese non ha un file: è il testo stesso della chiave. Una nuova lingua è un nuovo `lang/xx.json`.
  - Le frasi con parti variabili usano i placeholder (`:name`, `:count`), le forme singolare e plurale `trans_choice`. Le etichette statiche di Filament (`$modelLabel`, `$navigationLabel`, `$title`) sono diventate metodi, perché le proprietà non possono chiamare `__()`; per lo stesso motivo `SyncJobForm::INTERVALS` e `PlanFormatter::OPERATIONS` sono metodi.
  - Tradotti anche i messaggi d'errore dei provider, gli stati degli account e i motivi delle operazioni. Finiscono nel database (`status_message`, `sync_runs.errors`, `plan`) nella lingua attiva al momento: cambiando lingua, quelli già salvati restano come sono.
  - Tradotti anche i comandi da terminale; le descrizioni dei comandi erano già in inglese.
  - Date: `App\Support\DateFormat` usa `d/m/Y` in italiano e il formato ISO (`Y-m-d`) nelle altre lingue. I giorni della settimana dei filtri vengono da Carbon (lun… / Mon…).
  - `busy_title` predefinito nella lingua attiva ("Occupato" / "Busy"), impostato nel costruttore del modello. I job esistenti mantengono il titolo salvato.
  - Test: `phpunit.xml` fissa `APP_LOCALE=it`, così i test esistenti non dipendono dal `.env`. I test unitari ora avviano l'app, che serve per il traduttore. `TranslationsTest` controlla che ogni chiave usata nel codice sia tradotta, che non restino traduzioni inutilizzate, che placeholder e forme plurali coincidano, e che il pannello si mostri in entrambe le lingue.
  - Verifica visiva del pannello in inglese con un database temporaneo: dashboard, job, form, storico, account, gruppi, dry-run, anteprima eventi e privacy.
  - Non tradotti: i messaggi di validazione standard di Laravel (es. "campo obbligatorio") restano in inglese in entrambe le lingue, come prima dello step 13.
- **Step 14.**
  - Immagine unica basata su FrankenPHP (`dunglas/frankenphp:1-php8.4`): Caddy e PHP nello stesso processo, HTTP sulla porta 8080, utente `www-data`, nessun file `.env` dentro l'immagine. Estensioni aggiunte: intl, zip, pdo_mysql, pcntl (serve al worker per interrompere una sync oltre il timeout) e opcache.
  - Lo stesso container fa tre ruoli, scelti dal comando (`docker/entrypoint.sh`): `web` esegue le migration e serve il pannello, `worker` esegue le sync, `scheduler` mette in coda i job ogni minuto. Ogni container mette in cache configurazione, rotte, viste e componenti Filament all'avvio.
  - `docker/compose.yaml` con MySQL 8.4 e `docker/compose.sqlite.yaml` senza il container del database (SQLite in WAL con `DB_BUSY_TIMEOUT`, opzioni ora lette da env in `config/database.php`). `docker/compose.build.yaml` costruisce l'immagine dal checkout invece di scaricarla.
  - I file Docker stanno in `docker/`, con il loro `.env.example`: il `.env` di Compose non si confonde con quello di Laravel nella radice. Chi installa scarica solo `compose.yaml` e `.env.example`; l'immagine arriva da `ghcr.io/laleace/calendar-sync`, pubblicata dalla CI allo step 16.
  - Volume `data` su `storage/app`: contiene la `APP_KEY` generata al primo avvio (`app.key`, se `APP_KEY` è vuota) e il database SQLite. Log su stderr (`docker compose logs`), sessioni, cache e coda nel database.
  - Healthcheck: `web` chiama `/up`; `worker` e `scheduler` usano il nuovo `calsync:doctor --services`, che controlla solo database, credenziali leggibili, scheduler e coda. I controlli di configurazione (HTTPS, cookie sicuro) restano nel doctor completo: su HTTP in chiaro li segnala, com'è giusto.
  - HTTPS: l'immagine serve solo HTTP; davanti va un reverse proxy con TLS. `TRUSTED_PROXIES` predefinito sulle reti Docker (`172.16.0.0/12`), così un proxy sulla stessa macchina è fidato e i client della LAN no.
  - Verifica: `docker compose up` da zero su una VM Debian 13 arm64 (VirtualBox), variante MySQL, con build dal sorgente (`compose.build.yaml`).
  - Simulazione locale senza Docker su una copia pulita: build senza `.env` né database, avvio dei tre ruoli, migration su SQLite in WAL, `/up` e login a 200, tick dello scheduler, `calsync:doctor --services` OK.
- **Step 15.**
  - Convenzione per le lingue: il file inglese ha il nome normale (`README.md`, `docs/google-oauth.md`, `deploy/README.md`…), quello italiano il suffisso `.it.md`; ognuno linka l'altro in cima. `PIANO.md` resta solo in italiano.
  - `README.md` e `README.it.md`: funzioni, installazione con Docker e senza, collegamento degli account, variabili principali, comandi, FAQ, link alla documentazione, sostegno con GitHub Sponsors, nota AGPL. Nessuno screenshot per ora: si possono aggiungere in `docs/images/` prima della pubblicazione.
  - Nuova guida `docs/docker.md` (+ `.it.md`): installazione, `APP_KEY`, reverse proxy (Caddy e nginx) e `TRUSTED_PROXIES`, aggiornamenti, backup e ripristino di MySQL e SQLite, problemi comuni.
  - `docs/google-oauth`, `docs/https-lan-cloudflare` e `deploy/README` tradotti in inglese, con gli esempi su `example.com`. La guida di deploy dice dove sta la versione Docker. `deploy/update.sh` e gli altri file di `deploy/` non cambiano.
  - Docker: variabile `CALSYNC_BIND` per pubblicare la porta solo su `127.0.0.1` dietro un proxy sulla stessa macchina; fuso orario predefinito del `.env` di Docker `UTC`.
  - `CONTRIBUTING.md` (le regole da non rompere: marker `calsync`, mai toccare ciò che l'app non ha creato, traduzioni in `lang/it.json`), `.github/FUNDING.yml` (solo `github: laleace`), template per bug e richieste, `config.yml` che manda le vulnerabilità al reporting privato, template di pull request.
  - I contributi esterni sono accettati sotto AGPL-3.0, come il resto del progetto (nessun CLA). Se in futuro vorrai una licenza commerciale, i contributi di terzi andranno gestiti a parte.
- **Step 16.** Tre workflow in `.github/workflows/`, più `dependabot.yml`.
  - `ci.yml`, a ogni push su `main` e a ogni pull request: Pint (`--test`) e Pest su PHP 8.4 con SQLite, più ShellCheck su `docker/entrypoint.sh` e `deploy/update.sh`. Il `.env` si crea da `.env.example` con `key:generate`, come farebbe un contributore.
  - `docker.yml`: le pull request che toccano immagine o Compose validano i tre file Compose e costruiscono l'immagine, senza pubblicarla. Su un tag `v*` si costruisce prima l'immagine amd64 e la si avvia (migration su SQLite vuoto, `APP_KEY` generata, `/up` risponde); solo se parte, si pubblica su `ghcr.io/laleace/calendar-sync` per amd64 e arm64 con i tag `X.Y.Z`, `X.Y` e `latest`.
  - `audit.yml`: `composer audit` ogni lunedì e a richiesta. Non blocca i push, perché nuove advisory compaiono in qualsiasi momento; fallendo, GitHub avvisa.
  - Dependabot: Composer (settimanale, Symfony raggruppato), GitHub Actions e immagine Docker (mensile).
  - Provato in locale su un clone pulito: installazione, `key:generate`, Pint e 358 test, e ShellCheck sugli script. I workflow stessi si vedono girare solo su GitHub, allo step 17. Dopo il primo push dell'immagine il pacchetto su GHCR è privato: va reso pubblico dalle impostazioni del pacchetto.


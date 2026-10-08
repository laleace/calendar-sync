# calendar-sync

Sincronizzazione unidirezionale e aggregazione di calendari (iCloud / CalDAV / Google) con GUI Filament.
Piano e decisioni di progetto: [docs/PIANO.md](docs/PIANO.md). Guida al deploy: `deploy/README.md` (step 9).

## Sviluppo locale

Requisiti: PHP 8.4, Composer. In locale il DB può essere SQLite (`database/database.sqlite`) o MySQL.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan calsync:create-admin
php artisan dev
```

`php artisan dev` avvia insieme il server web (http://localhost:8000/admin), il worker della coda (code `sync` e `default`), lo scheduler e i log. Senza worker i sync restano "In coda": il pannello lo segnala dopo 2 minuti.

Comandi utili:

| Comando | A cosa serve |
|---|---|
| `php artisan calsync:test-account {id}` | Discovery dei calendari di un account (`--debug` per vedere le richieste HTTP) |
| `php artisan calsync:list-events {calendario} --from= --to=` | Eventi di un calendario, ricorrenze espanse |
| `php artisan calsync:run {job} [--dry-run]` | Esegue un job in primo piano e stampa l'esito |
| `php artisan calsync:dispatch-due` | Accoda i job attivi in scadenza (lo lancia lo scheduler ogni minuto) |

## Test

```bash
php artisan test
```

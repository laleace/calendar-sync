# calendar-sync

Sincronizzazione unidirezionale di calendari (iCloud / CalDAV / Google) con GUI Filament e API per n8n.
Piano e decisioni di progetto: [docs/PIANO.md](docs/PIANO.md). Guida al deploy: `deploy/README.md` (step 9).

## Sviluppo locale

Requisiti: PHP 8.4, Composer. In locale il DB è SQLite (`database/database.sqlite`).

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan calsync:create-admin
php artisan serve
```

Il pannello è su http://localhost:8000/admin.

## Test

```bash
php artisan test
```

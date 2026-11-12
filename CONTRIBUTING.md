# Contributing

Bug reports, ideas and pull requests are welcome. For a vulnerability, do not open a public issue: see [SECURITY.md](SECURITY.md).

## Before you start

calendar-sync does one thing: a **one-way** sync from source calendars to a target calendar. Fixes, support for more CalDAV servers, better filters and translations fit well. For a bigger feature, open an issue first so we can agree on the approach before you spend time on it.

## Development setup

You need PHP 8.4 and Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan calsync:create-admin
php artisan dev
```

The panel is at <http://localhost:8000/admin>. Use SQLite (the default in `.env.example`) and calendars you do not mind experimenting on.

## Checks

Tests and code style must pass before a pull request:

```bash
php artisan test
vendor/bin/pint
```

- Tests use Pest. No test reaches a real calendar: CalDAV is faked with `Http::fake`, Google with a fake client, and real requests are blocked. Add a test with every behavior change.
- `phpunit.xml` pins the Italian locale, so the tests are independent of your `.env`.

## Translations

Texts in the code are English and go through `__()` or `trans_choice()`. The Italian translation is in `lang/it.json`; English has no file, the key is the text. Add the new keys to `lang/it.json`: `tests/Feature/TranslationsTest.php` fails if a key is missing, unused, or has different placeholders. To add a language, create `lang/xx.json` with all the keys.

## Things to keep

- **Markers.** The `X-CALSYNC-*` properties, the `calsync_job_id` fields and the `calsync-<hash>` identifiers are how the app recognises its own copies in the calendars. Do not rename them: existing copies would be duplicated.
- **Never touch what the app did not create.** A job only updates or deletes the events it has a mapping for.
- **No secrets anywhere.** Credentials never go in logs, errors or the browser: errors pass through `SecretRedactor`.

## Pull requests

- One topic per pull request, with a short description of what changes and why.
- Keep the diff focused: no unrelated reformatting.
- By contributing you agree that your contribution is released under the [AGPL-3.0](LICENSE), like the rest of the project.

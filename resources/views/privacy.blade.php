<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy · {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: system-ui, -apple-system, sans-serif; line-height: 1.6; max-width: 40rem; margin: 3rem auto; padding: 0 1rem; }
        h1 { font-size: 1.5rem; }
    </style>
</head>
<body>
    <h1>Informativa sulla privacy</h1>

    <p>{{ config('app.name') }} è un'installazione privata di calendar-sync, un software open source che sincronizza calendari Google, iCloud e CalDAV. È gestita da chi amministra il dominio {{ request()->getHost() }}, e solo chi accede a questo pannello può collegare i propri account.</p>

    <p>L'applicazione accede ai calendari collegati solo per copiare gli eventi tra di essi, secondo le regole impostate nel pannello. I dati restano su questo server: non vengono condivisi con terzi, venduti o usati per altri scopi, e non vengono inviati agli autori del software.</p>

    <p>Le credenziali e i token di accesso sono conservati cifrati. L'accesso a Google si può revocare in qualsiasi momento da <a href="https://myaccount.google.com/permissions">myaccount.google.com/permissions</a>.</p>

    <p>Codice sorgente: <a href="{{ config('calsync.source_url') }}">{{ config('calsync.source_url') }}</a> (licenza AGPL-3.0).</p>
</body>
</html>

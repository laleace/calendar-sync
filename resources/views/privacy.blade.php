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

    <p>{{ config('app.name') }} è un'applicazione personale, usata esclusivamente dal titolare del dominio {{ request()->getHost() }}.</p>

    <p>L'applicazione accede a Google Calendar e iCloud solo per sincronizzare tra loro i calendari del titolare. I dati letti non vengono condivisi con terzi, venduti o usati per altri scopi.</p>

    <p>Le credenziali e i token di accesso sono conservati cifrati. L'accesso a Google si può revocare in qualsiasi momento da <a href="https://myaccount.google.com/permissions">myaccount.google.com/permissions</a>.</p>
</body>
</html>

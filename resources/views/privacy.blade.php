<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
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
    <h1>{{ __('Privacy policy') }}</h1>

    <p>{{ __(':app is a private installation of calendar-sync, open source software that syncs Google, iCloud and CalDAV calendars. It is run by whoever manages the domain :host, and only those who can sign in to this panel can connect their accounts.', ['app' => config('app.name'), 'host' => request()->getHost()]) }}</p>

    <p>{{ __('The application accesses the connected calendars only to copy events between them, following the rules set in the panel. The data stays on this server: it is not shared with third parties, sold or used for other purposes, and it is not sent to the authors of the software.') }}</p>

    <p>{{ __('Credentials and access tokens are stored encrypted. Access to Google can be revoked at any time from') }} <a href="https://myaccount.google.com/permissions">myaccount.google.com/permissions</a>.</p>

    <p>{{ __('Source code:') }} <a href="{{ config('calsync.source_url') }}">{{ config('calsync.source_url') }}</a> ({{ __('AGPL-3.0 license') }}).</p>
</body>
</html>

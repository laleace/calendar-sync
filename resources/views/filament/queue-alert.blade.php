@if ($stuck > 0)
    <x-filament::callout
        color="warning"
        icon="heroicon-o-clock"
        heading="{{ $stuck }} sync in coda da più di {{ $minutes }} minuti"
        description="Il worker della coda non sembra attivo. In sviluppo avvia php artisan dev (oppure php artisan queue:work --queue=sync,default); in produzione controlla il servizio systemd del worker."
    />
@endif

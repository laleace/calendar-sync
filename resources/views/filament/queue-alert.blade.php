@if ($stuck > 0)
    <x-filament::callout
        color="warning"
        icon="heroicon-o-clock"
        :heading="__(':count syncs queued for more than :minutes minutes', ['count' => $stuck, 'minutes' => $minutes])"
        :description="__('The queue worker does not seem to be running. In development start php artisan dev (or php artisan queue:work --queue=sync,default); in production check the worker service.')"
    />
@endif

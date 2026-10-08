<?php

return [

    /*
    | Proxies allowed to set X-Forwarded-* headers (scheme, host, client IP).
    | Default: only local processes, i.e. cloudflared or nginx running on the same
    | machine. Without this, behind an HTTPS tunnel Laravel would generate http:// URLs
    | and the browser would block the panel's assets.
    */
    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1'),

];

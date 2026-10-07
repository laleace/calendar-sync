<?php

namespace App\CalSync\Exceptions;

/**
 * Network-level failure (DNS, TLS, timeout): retrying another URL on the same server is pointless.
 */
class ServerUnreachable extends ProviderException {}

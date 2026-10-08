<?php

namespace App\CalSync\Exceptions;

/**
 * The target event no longer exists (deleted by hand on the target calendar).
 */
class EventNotFound extends ProviderException {}

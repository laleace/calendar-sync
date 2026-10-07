<?php

namespace App\CalSync\Exceptions;

use App\Enums\AccountStatus;
use RuntimeException;

/**
 * Error talking to a calendar backend. Messages are shown in the GUI and stored in the
 * database, so they must never contain credentials or tokens.
 */
class ProviderException extends RuntimeException
{
    /**
     * The account status this error implies.
     */
    public function accountStatus(): AccountStatus
    {
        return AccountStatus::Error;
    }
}

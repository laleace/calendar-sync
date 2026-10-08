<?php

namespace App\CalSync\Exceptions;

use App\Enums\AccountStatus;

/**
 * OAuth access can no longer be renewed (revoked, expired, never connected): the user
 * must reconnect the account.
 */
class TokenExpired extends ProviderException
{
    public function accountStatus(): AccountStatus
    {
        return AccountStatus::TokenExpired;
    }
}

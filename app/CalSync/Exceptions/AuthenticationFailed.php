<?php

namespace App\CalSync\Exceptions;

use App\Enums\AccountStatus;

class AuthenticationFailed extends ProviderException
{
    public function accountStatus(): AccountStatus
    {
        return AccountStatus::AuthFailed;
    }
}

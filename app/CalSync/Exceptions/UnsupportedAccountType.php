<?php

namespace App\CalSync\Exceptions;

use App\Enums\AccountType;

class UnsupportedAccountType extends ProviderException
{
    public static function for(AccountType $type): self
    {
        return new self(__('No provider available for the account type ":type".', ['type' => $type->getLabel()]));
    }

    public static function forWriting(AccountType $type): self
    {
        return new self(__('Writing events is not available yet for ":type" accounts.', ['type' => $type->getLabel()]));
    }
}

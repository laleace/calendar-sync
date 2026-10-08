<?php

namespace App\CalSync\Exceptions;

use App\Enums\AccountType;

class UnsupportedAccountType extends ProviderException
{
    public static function for(AccountType $type): self
    {
        return new self("Nessun provider disponibile per il tipo di account \"{$type->getLabel()}\".");
    }

    public static function forWriting(AccountType $type): self
    {
        return new self("La scrittura di eventi non è ancora disponibile per gli account \"{$type->getLabel()}\".");
    }
}

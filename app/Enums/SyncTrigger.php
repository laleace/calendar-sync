<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SyncTrigger: string implements HasLabel
{
    case Schedule = 'schedule';
    case Manual = 'manual';
    case Api = 'api';

    public function getLabel(): string
    {
        return match ($this) {
            self::Schedule => 'Pianificato',
            self::Manual => 'Manuale',
            self::Api => 'API',
        };
    }
}

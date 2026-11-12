<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SyncTrigger: string implements HasLabel
{
    case Schedule = 'schedule';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Schedule => __('Scheduled'),
            self::Manual => __('Manual'),
        };
    }
}

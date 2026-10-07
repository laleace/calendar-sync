<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SyncDirection: string implements HasLabel
{
    case OneWay = 'one_way';

    public function getLabel(): string
    {
        return match ($this) {
            self::OneWay => 'Unidirezionale',
        };
    }
}

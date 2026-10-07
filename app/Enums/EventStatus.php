<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EventStatus: string implements HasLabel
{
    case Confirmed = 'confirmed';
    case Tentative = 'tentative';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Confirmed => 'Confermato',
            self::Tentative => 'Provvisorio',
            self::Cancelled => 'Annullato',
        };
    }
}

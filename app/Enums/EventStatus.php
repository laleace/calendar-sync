<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EventStatus: string implements HasColor, HasLabel
{
    case Confirmed = 'confirmed';
    case Tentative = 'tentative';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Confirmed => __('Confirmed'),
            self::Tentative => __('Tentative'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Confirmed => 'success',
            self::Tentative => 'warning',
            self::Cancelled => 'danger',
        };
    }
}

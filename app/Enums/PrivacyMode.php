<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PrivacyMode: string implements HasLabel
{
    case Full = 'full';
    case BusyOnly = 'busy_only';

    public function getLabel(): string
    {
        return match ($this) {
            self::Full => __('Full (all fields)'),
            self::BusyOnly => __('"Busy" only'),
        };
    }
}

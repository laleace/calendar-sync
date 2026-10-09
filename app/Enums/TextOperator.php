<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How a text filter rule compares a field with its value (always case-insensitive).
 */
enum TextOperator: string implements HasLabel
{
    case Contains = 'contains';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case Equals = 'equals';
    case Regex = 'regex';

    public function getLabel(): string
    {
        return match ($this) {
            self::Contains => 'contiene',
            self::StartsWith => 'inizia con',
            self::EndsWith => 'finisce con',
            self::Equals => 'è uguale a',
            self::Regex => 'espressione regolare',
        };
    }
}

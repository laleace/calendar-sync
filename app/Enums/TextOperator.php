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
            self::Contains => __('contains'),
            self::StartsWith => __('starts with'),
            self::EndsWith => __('ends with'),
            self::Equals => __('equals'),
            self::Regex => __('regular expression'),
        };
    }
}

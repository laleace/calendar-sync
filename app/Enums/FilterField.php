<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a job filter rule looks at on the source event.
 */
enum FilterField: string implements HasLabel
{
    case Title = 'title';
    case Location = 'location';
    case Description = 'description';
    case Weekday = 'weekday';
    case StartTime = 'start_time';
    case AllDay = 'all_day';
    case Free = 'free';
    case Tentative = 'tentative';
    case Recurring = 'recurring';

    public function getLabel(): string
    {
        return match ($this) {
            self::Title => __('Title'),
            self::Location => __('Location'),
            self::Description => __('Notes'),
            self::Weekday => __('Day of the week'),
            self::StartTime => __('Start time'),
            self::AllDay => __('All-day event'),
            self::Free => __('Marked as free'),
            self::Tentative => __('Tentative'),
            self::Recurring => __('Recurring'),
        };
    }

    /**
     * Compared with a text and an operator.
     */
    public function isText(): bool
    {
        return in_array($this, [self::Title, self::Location, self::Description], true);
    }
}

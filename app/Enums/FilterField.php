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
            self::Title => 'Titolo',
            self::Location => 'Luogo',
            self::Description => 'Note',
            self::Weekday => 'Giorno della settimana',
            self::StartTime => 'Ora di inizio',
            self::AllDay => 'Evento di tutto il giorno',
            self::Free => 'Segnato come libero',
            self::Tentative => 'Provvisorio',
            self::Recurring => 'Ricorrente',
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

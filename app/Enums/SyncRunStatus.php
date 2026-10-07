<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SyncRunStatus: string implements HasColor, HasLabel
{
    case Queued = 'queued';
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => 'In coda',
            self::Running => 'In esecuzione',
            self::Success => 'Completato',
            self::Partial => 'Completato con errori',
            self::Failed => 'Fallito',
            self::Skipped => 'Saltato',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued, self::Skipped => 'gray',
            self::Running => 'info',
            self::Success => 'success',
            self::Partial => 'warning',
            self::Failed => 'danger',
        };
    }

    public function isFinished(): bool
    {
        return ! in_array($this, [self::Queued, self::Running], true);
    }
}

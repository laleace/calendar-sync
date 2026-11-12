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
            self::Queued => __('Queued'),
            self::Running => __('Running'),
            self::Success => __('Completed'),
            self::Partial => __('Completed with errors'),
            self::Failed => __('Failed'),
            self::Skipped => __('Skipped'),
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

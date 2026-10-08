<?php

namespace App\Filament\Actions;

use App\Filament\Resources\SyncJobs\SyncJobResource;
use App\Models\SyncJob;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Opens the dry-run page: it reads the calendars and shows what the sync would do,
 * without writing anything.
 */
class DryRunAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'dryRun';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Dry-run')
            ->icon(Heroicon::OutlinedBeaker)
            ->color('gray')
            ->url(fn (SyncJob $record): string => SyncJobResource::getUrl('dry-run', ['record' => $record]));
    }
}

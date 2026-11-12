<?php

namespace App\Filament\Resources\SyncRuns\Pages;

use App\Filament\Resources\SyncJobs\SyncJobResource;
use App\Filament\Resources\SyncRuns\SyncRunResource;
use App\Models\SyncRun;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * @property SyncRun $record
 */
class ViewSyncRun extends ViewRecord
{
    protected static string $resource = SyncRunResource::class;

    public function getTitle(): string|Htmlable
    {
        return "Run #{$this->record->id} · {$this->record->syncJob->name}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(__('Refresh'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => ! $this->record->status->isFinished())
                ->action(fn () => $this->record->refresh()),
            Action::make('job')
                ->label(__('Open the job'))
                ->color('gray')
                ->url(fn (): string => SyncJobResource::getUrl('edit', ['record' => $this->record->sync_job_id])),
        ];
    }
}

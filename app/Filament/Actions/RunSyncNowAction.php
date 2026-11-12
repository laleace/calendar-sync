<?php

namespace App\Filament\Actions;

use App\CalSync\Sync\SyncService;
use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Filament\Resources\SyncRuns\SyncRunResource;
use App\Models\SyncJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Queues a real sync of the job for the worker.
 */
class RunSyncNowAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'runNow';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Run now'))
            ->icon(Heroicon::OutlinedPlay)
            ->color('primary')
            ->disabled(fn (SyncJob $record): bool => self::isBusy($record))
            ->tooltip(fn (SyncJob $record): ?string => self::isBusy($record) ? __('A sync of this job is already queued or running.') : null)
            ->action(function (SyncJob $record, SyncService $sync): void {
                $run = $sync->queue($record, SyncTrigger::Manual);

                Notification::make()
                    ->title(__('Sync queued'))
                    ->body(__('Run #:id starts as soon as the queue worker is free.', ['id' => $run->id]))
                    ->success()
                    ->actions([
                        Action::make('view')
                            ->label(__('View the run'))
                            ->url(SyncRunResource::getUrl('view', ['record' => $run])),
                    ])
                    ->send();
            });
    }

    private static function isBusy(SyncJob $job): bool
    {
        return $job->runs()->whereIn('status', [SyncRunStatus::Queued, SyncRunStatus::Running])->exists();
    }
}

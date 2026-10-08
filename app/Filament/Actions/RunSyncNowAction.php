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

        $this->label('Esegui ora')
            ->icon(Heroicon::OutlinedPlay)
            ->color('primary')
            ->disabled(fn (SyncJob $record): bool => self::isBusy($record))
            ->tooltip(fn (SyncJob $record): ?string => self::isBusy($record) ? 'Un sync di questo job è già in coda o in esecuzione.' : null)
            ->action(function (SyncJob $record, SyncService $sync): void {
                $run = $sync->queue($record, SyncTrigger::Manual);

                Notification::make()
                    ->title('Sync accodato')
                    ->body("Il run #{$run->id} parte appena il worker della coda è libero.")
                    ->success()
                    ->actions([
                        Action::make('view')
                            ->label('Vedi il run')
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

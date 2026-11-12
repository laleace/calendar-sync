<?php

namespace App\Filament\Actions;

use App\CalSync\Sync\SyncService;
use App\Enums\SyncTrigger;
use App\Filament\Resources\SyncRuns\SyncRunResource;
use App\Models\SyncJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Deletes every copy the job created on its target (past ones included) and disables
 * the job, so the copies are not created again.
 */
class PurgeCopiesAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'purgeCopies';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Remove copies'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (SyncJob $record): bool => $record->mappings()->exists())
            ->requiresConfirmation()
            ->modalHeading(__('Remove the copies created by this job?'))
            ->modalDescription(fn (SyncJob $record): string => __('The :count copies created by this job, past ones included, will be deleted from the calendar ":calendar". The original events are not touched. The job will be disabled.', [
                'calendar' => $record->target->name,
                'count' => $record->mappings()->count(),
            ]))
            ->modalSubmitActionLabel(__('Remove the copies'))
            ->action(function (SyncJob $record, SyncService $sync): void {
                $run = $sync->queue($record, SyncTrigger::Manual, purge: true);

                Notification::make()
                    ->title(__('Copy removal queued'))
                    ->body(__('The job has been disabled. Follow the progress in run #:id.', ['id' => $run->id]))
                    ->success()
                    ->actions([
                        Action::make('view')
                            ->label(__('View the run'))
                            ->url(SyncRunResource::getUrl('view', ['record' => $run])),
                    ])
                    ->send();
            });
    }
}

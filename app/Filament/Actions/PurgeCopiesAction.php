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

        $this->label('Rimuovi copie')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (SyncJob $record): bool => $record->mappings()->exists())
            ->requiresConfirmation()
            ->modalHeading('Rimuovere le copie create da questo job?')
            ->modalDescription(fn (SyncJob $record): string => sprintf(
                'Verranno eliminate dal calendario "%s" le %d copie create da questo job, comprese quelle passate. Gli eventi originali non vengono toccati. Il job verrà disattivato.',
                $record->target->name,
                $record->mappings()->count(),
            ))
            ->modalSubmitActionLabel('Rimuovi le copie')
            ->action(function (SyncJob $record, SyncService $sync): void {
                $run = $sync->queue($record, SyncTrigger::Manual, purge: true);

                Notification::make()
                    ->title('Rimozione delle copie accodata')
                    ->body("Il job è stato disattivato. Segui l'avanzamento nel run #{$run->id}.")
                    ->success()
                    ->actions([
                        Action::make('view')
                            ->label('Vedi il run')
                            ->url(SyncRunResource::getUrl('view', ['record' => $run])),
                    ])
                    ->send();
            });
    }
}

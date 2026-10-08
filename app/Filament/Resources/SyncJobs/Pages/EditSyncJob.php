<?php

namespace App\Filament\Resources\SyncJobs\Pages;

use App\Filament\Actions\DryRunAction;
use App\Filament\Resources\SyncJobs\SyncJobResource;
use App\Models\SyncJob;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * @property SyncJob $record
 */
class EditSyncJob extends EditRecord
{
    protected static string $resource = SyncJobResource::class;

    /** @var list<int> */
    private array $sourceIds = [];

    protected function getHeaderActions(): array
    {
        return [
            DryRunAction::make(),
            DeleteAction::make()
                ->modalDescription('Le copie già create da questo job resteranno nel calendario di destinazione e non saranno più gestite.'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'source_ids' => $this->record->sources()->pluck('calendars.id')->all()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->sourceIds = array_map('intval', $data['source_ids'] ?? []);
        unset($data['source_ids']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->syncSources($this->sourceIds);
    }
}

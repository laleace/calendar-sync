<?php

namespace App\Filament\Resources\SyncJobs\Pages;

use App\Filament\Resources\SyncJobs\SyncJobResource;
use App\Models\SyncJob;
use Filament\Resources\Pages\CreateRecord;

/**
 * @property SyncJob $record
 */
class CreateSyncJob extends CreateRecord
{
    protected static string $resource = SyncJobResource::class;

    /** @var list<int> */
    private array $sourceIds = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->sourceIds = array_map('intval', $data['source_ids'] ?? []);
        unset($data['source_ids']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncSources($this->sourceIds);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\CalSync\AccountService;
use App\Enums\AccountType;
use App\Filament\Actions\DiscoverCalendarsAction;
use App\Filament\Resources\Accounts\AccountResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateAccount extends CreateRecord
{
    protected static string $resource = AccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $type = $data['type'] instanceof AccountType ? $data['type'] : AccountType::from($data['type']);

        return match ($type) {
            // Tokens arrive with the OAuth callback.
            AccountType::Google => ['name' => $data['name'], 'type' => $type, 'base_url' => null, 'credentials' => []],
            default => [
                'name' => $data['name'],
                'type' => $type,
                'base_url' => $type === AccountType::IcloudCaldav ? config('calsync.icloud.base_url') : $data['base_url'],
                'credentials' => ['username' => $data['username'], 'password' => $data['password']],
            ],
        };
    }

    /**
     * CalDAV: run the discovery right away, it verifies the credentials and lists the
     * calendars. Google: nothing to check before the OAuth consent.
     */
    protected function afterCreate(): void
    {
        if ($this->getRecord()->type->isCalDav()) {
            DiscoverCalendarsAction::discover($this->getRecord(), app(AccountService::class));
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getRecord()->type === AccountType::Google
            ? route('oauth.google.redirect', $this->getRecord())
            : $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}

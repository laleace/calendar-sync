<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\CalSync\AccountService;
use App\Enums\AccountType;
use App\Filament\Actions\ConnectGoogleAction;
use App\Filament\Actions\DeleteAccountAction;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

/**
 * @property Account $record
 */
class EditAccount extends EditRecord
{
    protected static string $resource = AccountResource::class;

    private bool $connectionChanged = false;

    protected function getHeaderActions(): array
    {
        return [
            ConnectGoogleAction::make(),
            ViewAction::make(),
            DeleteAccountAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['credentials']);

        // The stored password is never sent to the browser.
        return [...$data, 'username' => $this->record->credential('username'), 'password' => null];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Google: credentials come only from the OAuth flow.
        if ($this->record->type === AccountType::Google) {
            return ['name' => $data['name'], 'group_id' => $data['group_id'] ?? null];
        }

        if ($this->record->hasUnreadableCredentials() && blank($data['password'] ?? null)) {
            throw ValidationException::withMessages([
                'data.password' => __('The saved password cannot be decrypted (APP_KEY has changed): enter it again.'),
            ]);
        }

        $stored = $this->record->safeCredentials() ?? [];
        $credentials = $stored;
        $credentials['username'] = $data['username'];

        if (filled($data['password'] ?? null)) {
            $credentials['password'] = $data['password'];
        }

        $baseUrl = $this->record->type === AccountType::CaldavGeneric ? $data['base_url'] : $this->record->base_url;

        $this->connectionChanged = $credentials !== $stored || $baseUrl !== $this->record->base_url;

        return ['name' => $data['name'], 'group_id' => $data['group_id'] ?? null, 'base_url' => $baseUrl, 'credentials' => $credentials];
    }

    protected function afterSave(): void
    {
        // Do not keep the typed password in the component state sent back to the browser.
        $this->data['password'] = null;

        if (! $this->connectionChanged) {
            return;
        }

        $result = app(AccountService::class)->testConnection($this->record);

        Notification::make()
            ->title($result->successful() ? __('Connection verified') : __('Connection failed with the new details'))
            ->body($result->message)
            ->status($result->successful() ? 'success' : 'danger')
            ->send();
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}

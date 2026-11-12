<?php

namespace App\Filament\Actions;

use App\Enums\AccountType;
use App\Models\Account;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Starts the Google OAuth flow for an account (first connection or reconnection).
 */
class ConnectGoogleAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'connectGoogle';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (Account $record): string => filled($record->credential('refresh_token')) ? __('Reconnect Google') : __('Connect with Google'))
            ->icon(Heroicon::OutlinedLink)
            ->color('primary')
            ->visible(fn (Account $record): bool => $record->type === AccountType::Google)
            ->url(fn (Account $record): string => route('oauth.google.redirect', $record));
    }
}

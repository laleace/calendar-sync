<?php

namespace App\Filament\Actions;

use App\CalSync\AccountService;
use App\Models\Account;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class TestConnectionAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'testConnection';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Test connessione')
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            ->action(function (Account $record, AccountService $accounts): void {
                $result = $accounts->testConnection($record);

                Notification::make()
                    ->title($result->successful() ? 'Connessione riuscita' : 'Connessione non riuscita')
                    ->body($result->message)
                    ->status($result->successful() ? 'success' : 'danger')
                    ->send();
            });
    }
}

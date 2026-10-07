<?php

namespace App\Filament\Actions;

use App\CalSync\AccountService;
use App\CalSync\Exceptions\ProviderException;
use App\Models\Account;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;

class DiscoverCalendarsAction extends Action
{
    public const REFRESH_EVENT = 'calendars-discovered';

    public static function getDefaultName(): ?string
    {
        return 'discoverCalendars';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Scopri calendari')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function (Action $action, AccountService $accounts): void {
                $livewire = $action->getLivewire();
                // Record actions and record pages carry the account; relation manager headers use the owner.
                $account = $action->getRecord() ?? ($livewire instanceof RelationManager ? $livewire->getOwnerRecord() : null);

                if (! $account instanceof Account) {
                    return;
                }

                self::discover($account, $accounts);
                $livewire->dispatch(self::REFRESH_EVENT);
            });
    }

    public static function discover(Account $account, AccountService $accounts): bool
    {
        try {
            $summary = $accounts->discoverCalendars($account);
        } catch (ProviderException $e) {
            Notification::make()
                ->title('Scoperta calendari non riuscita')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return false;
        }

        Notification::make()
            ->title('Calendari aggiornati')
            ->body(sprintf(
                'Trovati %d calendari: %d nuovi, %d aggiornati, %d non più presenti.',
                $summary->calendars->count(),
                $summary->created,
                $summary->updated,
                $summary->missing->count(),
            ))
            ->success()
            ->send();

        return true;
    }
}

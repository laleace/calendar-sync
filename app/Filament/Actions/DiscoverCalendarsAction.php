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

        $this->label(__('Discover calendars'))
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
                ->title(__('Calendar discovery failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return false;
        }

        Notification::make()
            ->title(__('Calendars updated'))
            ->body(__('Found :count calendars: :created new, :updated updated, :missing no longer present.', [
                'count' => $summary->calendars->count(),
                'created' => $summary->created,
                'updated' => $summary->updated,
                'missing' => $summary->missing->count(),
            ]))
            ->success()
            ->send();

        return true;
    }
}

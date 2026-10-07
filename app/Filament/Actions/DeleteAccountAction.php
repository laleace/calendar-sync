<?php

namespace App\Filament\Actions;

use App\Models\Account;
use Filament\Actions\DeleteAction;

/**
 * Accounts whose calendars are used by a sync job cannot be deleted.
 */
class DeleteAccountAction extends DeleteAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->disabled(fn (Account $record): bool => $record->isInUse())
            ->tooltip(fn (Account $record): ?string => $record->isInUse()
                ? 'I calendari di questo account sono usati da un job di sincronizzazione.'
                : null)
            ->before(function (Account $record, DeleteAction $action): void {
                if ($record->isInUse()) {
                    $action->cancel();
                }
            });
    }
}

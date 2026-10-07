<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Actions\DeleteAccountAction;
use App\Filament\Actions\DiscoverCalendarsAction;
use App\Filament\Actions\TestConnectionAction;
use App\Filament\Resources\Accounts\AccountResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAccount extends ViewRecord
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TestConnectionAction::make(),
            DiscoverCalendarsAction::make(),
            EditAction::make(),
            DeleteAccountAction::make(),
        ];
    }
}

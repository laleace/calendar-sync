<?php

namespace App\Filament\Resources\SyncJobs\RelationManagers;

use App\Filament\Resources\SyncRuns\Tables\SyncRunsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    protected static ?string $title = 'Storico';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return SyncRunsTable::configure($table, showJob: false);
    }
}

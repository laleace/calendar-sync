<?php

namespace App\Filament\Resources\SyncJobs\RelationManagers;

use App\Filament\Resources\SyncRuns\Tables\SyncRunsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RunsRelationManager extends RelationManager
{
    protected static string $relationship = 'runs';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('History');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return SyncRunsTable::configure($table, showJob: false);
    }
}

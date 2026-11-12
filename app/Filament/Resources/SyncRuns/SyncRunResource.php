<?php

namespace App\Filament\Resources\SyncRuns;

use App\Filament\Resources\SyncRuns\Pages\ListSyncRuns;
use App\Filament\Resources\SyncRuns\Pages\ViewSyncRun;
use App\Filament\Resources\SyncRuns\Schemas\SyncRunInfolist;
use App\Filament\Resources\SyncRuns\Tables\SyncRunsTable;
use App\Models\SyncRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only history of dry-runs, syncs and copy removals.
 */
class SyncRunResource extends Resource
{
    protected static ?string $model = SyncRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 6;

    public static function infolist(Schema $schema): Schema
    {
        return SyncRunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SyncRunsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('run');
    }

    public static function getPluralModelLabel(): string
    {
        return __('runs');
    }

    public static function getNavigationLabel(): string
    {
        return __('Run history');
    }

    public static function getBreadcrumb(): string
    {
        return __('Run history');
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record ? "Run #{$record->getKey()}" : 'Run';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSyncRuns::route('/'),
            'view' => ViewSyncRun::route('/{record}'),
        ];
    }
}

<?php

namespace App\Filament\Resources\SyncJobs;

use App\Filament\Resources\SyncJobs\Pages\CreateSyncJob;
use App\Filament\Resources\SyncJobs\Pages\DryRunSyncJob;
use App\Filament\Resources\SyncJobs\Pages\EditSyncJob;
use App\Filament\Resources\SyncJobs\Pages\ListSyncJobs;
use App\Filament\Resources\SyncJobs\RelationManagers\RunsRelationManager;
use App\Filament\Resources\SyncJobs\Schemas\SyncJobForm;
use App\Filament\Resources\SyncJobs\Tables\SyncJobsTable;
use App\Models\SyncJob;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SyncJobResource extends Resource
{
    protected static ?string $model = SyncJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 5;

    // The Italian label is masculine: Filament builds "Nuovo …" titles.
    public static function getModelLabel(): string
    {
        return __('sync job');
    }

    public static function getPluralModelLabel(): string
    {
        return __('sync jobs');
    }

    public static function getNavigationLabel(): string
    {
        return __('Sync jobs');
    }

    public static function getBreadcrumb(): string
    {
        return __('Sync jobs');
    }

    public static function form(Schema $schema): Schema
    {
        return SyncJobForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SyncJobsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RunsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSyncJobs::route('/'),
            'create' => CreateSyncJob::route('/create'),
            'edit' => EditSyncJob::route('/{record}/edit'),
            'dry-run' => DryRunSyncJob::route('/{record}/dry-run'),
        ];
    }
}

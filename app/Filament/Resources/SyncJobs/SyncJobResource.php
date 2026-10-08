<?php

namespace App\Filament\Resources\SyncJobs;

use App\Filament\Resources\SyncJobs\Pages\CreateSyncJob;
use App\Filament\Resources\SyncJobs\Pages\DryRunSyncJob;
use App\Filament\Resources\SyncJobs\Pages\EditSyncJob;
use App\Filament\Resources\SyncJobs\Pages\ListSyncJobs;
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

    // Masculine label: Filament builds "Nuovo …" titles.
    protected static ?string $modelLabel = 'job di sincronizzazione';

    protected static ?string $pluralModelLabel = 'job di sincronizzazione';

    protected static ?string $navigationLabel = 'Sincronizzazioni';

    protected static ?string $breadcrumb = 'Sincronizzazioni';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return SyncJobForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SyncJobsTable::configure($table);
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

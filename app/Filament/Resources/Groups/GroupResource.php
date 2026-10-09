<?php

namespace App\Filament\Resources\Groups;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Groups\Pages\ManageGroups;
use App\Filament\Resources\SyncJobs\SyncJobResource;
use App\Filament\Support\GroupFields;
use App\Models\Group;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'gruppo';

    protected static ?string $pluralModelLabel = 'gruppi';

    protected static ?string $navigationLabel = 'Gruppi';

    protected static ?int $navigationSort = 15;

    public static function form(Schema $schema): Schema
    {
        return $schema->components(GroupFields::schema());
    }

    public static function table(Table $table): Table
    {
        $filtered = fn (string $resource, Group $record): string => $resource::getUrl('index', ['filters' => ['group' => ['value' => $record->id]]]);

        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['accounts', 'syncJobs']))
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('accounts_count')
                    ->label('Account')
                    ->numeric()
                    ->url(fn (Group $record): string => $filtered(AccountResource::class, $record)),
                TextColumn::make('sync_jobs_count')
                    ->label('Job')
                    ->numeric()
                    ->url(fn (Group $record): string => $filtered(SyncJobResource::class, $record)),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Account e job del gruppo restano come sono, senza gruppo. Le sincronizzazioni non cambiano.'),
            ])
            ->emptyStateHeading('Nessun gruppo')
            ->emptyStateDescription('Crea un gruppo, per esempio "Personale", e assegnalo ad account e job. Puoi crearli anche dai loro form.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGroups::route('/'),
        ];
    }
}

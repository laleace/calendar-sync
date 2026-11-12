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

    protected static ?int $navigationSort = 15;

    public static function getModelLabel(): string
    {
        return __('group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('groups');
    }

    public static function getNavigationLabel(): string
    {
        return __('Groups');
    }

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
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('accounts_count')
                    ->label(__('Accounts'))
                    ->numeric()
                    ->url(fn (Group $record): string => $filtered(AccountResource::class, $record)),
                TextColumn::make('sync_jobs_count')
                    ->label(__('Jobs'))
                    ->numeric()
                    ->url(fn (Group $record): string => $filtered(SyncJobResource::class, $record)),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(__('The group\'s accounts and jobs stay as they are, without a group. Syncs do not change.')),
            ])
            ->emptyStateHeading(__('No group'))
            ->emptyStateDescription(__('Create a group, for example "Personal", and assign it to accounts and jobs. You can also create groups from their forms.'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGroups::route('/'),
        ];
    }
}

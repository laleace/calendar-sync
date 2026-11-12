<?php

namespace App\Filament\Resources\SyncJobs\Tables;

use App\Filament\Actions\DryRunAction;
use App\Filament\Actions\RunSyncNowAction;
use App\Filament\Resources\SyncJobs\Schemas\SyncJobForm;
use App\Filament\Support\GroupFields;
use App\Models\SyncJob;
use App\Support\DateFormat;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class SyncJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['group', 'sources', 'target.account']))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),
                GroupFields::column(),
                TextColumn::make('sources.name')
                    ->label(__('Sources'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('target.name')
                    ->label(__('Target'))
                    ->description(fn (SyncJob $record): string => $record->target->account->name),
                TextColumn::make('privacy_mode')
                    ->label('Privacy')
                    ->badge(),
                TextColumn::make('filters')
                    ->label(__('Filters'))
                    ->state(fn (SyncJob $record): ?string => ($count = $record->eventFilters()->count()) > 0
                        ? trans_choice(':count rule|:count rules', $count)
                        : null)
                    ->badge()
                    ->color('info')
                    ->placeholder('—'),
                TextColumn::make('interval_minutes')
                    ->label(__('Frequency'))
                    ->formatStateUsing(fn (int $state): string => SyncJobForm::intervals()[$state] ?? __('Every :count minutes', ['count' => $state])),
                ToggleColumn::make('enabled')
                    ->label(__('Enabled')),
                TextColumn::make('last_run_at')
                    ->label(__('Last sync'))
                    ->since()
                    ->dateTimeTooltip(DateFormat::dateTime())
                    ->placeholder(__('never')),
                TextColumn::make('next_run_at')
                    ->label(__('Next'))
                    ->state(fn (SyncJob $record) => $record->enabled ? ($record->next_run_at ?? now()) : null)
                    ->since()
                    ->dateTimeTooltip(DateFormat::dateTime())
                    ->placeholder('—'),
            ])
            ->defaultSort('name')
            ->filters([
                GroupFields::filter(),
            ])
            ->persistFiltersInSession()
            ->recordActions([
                ActionGroup::make([
                    RunSyncNowAction::make(),
                    DryRunAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    GroupFields::bulkAction(),
                ]),
            ])
            ->emptyStateHeading(__('No sync jobs'))
            ->emptyStateDescription(__('Create a job: pick one or more source calendars and a target calendar.'));
    }
}

<?php

namespace App\Filament\Resources\SyncJobs\Tables;

use App\Filament\Actions\DryRunAction;
use App\Filament\Actions\RunSyncNowAction;
use App\Filament\Resources\SyncJobs\Schemas\SyncJobForm;
use App\Models\SyncJob;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class SyncJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['sources', 'target.account']))
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sources.name')
                    ->label('Sorgenti')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('target.name')
                    ->label('Destinazione')
                    ->description(fn (SyncJob $record): string => $record->target->account->name),
                TextColumn::make('privacy_mode')
                    ->label('Privacy')
                    ->badge(),
                TextColumn::make('interval_minutes')
                    ->label('Frequenza')
                    ->formatStateUsing(fn (int $state): string => SyncJobForm::INTERVALS[$state] ?? "Ogni {$state} minuti"),
                ToggleColumn::make('enabled')
                    ->label('Attivo'),
                TextColumn::make('last_run_at')
                    ->label('Ultimo sync')
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i')
                    ->placeholder('mai'),
                TextColumn::make('next_run_at')
                    ->label('Prossimo')
                    ->state(fn (SyncJob $record) => $record->enabled ? ($record->next_run_at ?? now()) : null)
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->defaultSort('name')
            ->recordActions([
                ActionGroup::make([
                    RunSyncNowAction::make(),
                    DryRunAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nessuna sincronizzazione')
            ->emptyStateDescription('Crea un job: scegli uno o più calendari sorgente e un calendario di destinazione.');
    }
}

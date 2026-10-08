<?php

namespace App\Filament\Resources\SyncRuns\Tables;

use App\Enums\SyncRunStatus;
use App\Filament\Resources\SyncRuns\SyncRunResource;
use App\Models\SyncRun;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SyncRunsTable
{
    public static function configure(Table $table, bool $showJob = true): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('syncJob'))
            ->poll('5s')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('syncJob.name')
                    ->label('Job')
                    ->visible($showJob),
                TextColumn::make('kind')
                    ->label('Tipo')
                    ->state(fn (SyncRun $record): string => $record->kindLabel())
                    ->badge()
                    ->color('gray'),
                TextColumn::make('triggered_by')
                    ->label('Avviato da')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Stato')
                    ->badge(),
                TextColumn::make('summary')
                    ->label('Copie')
                    ->state(fn (SyncRun $record): string => sprintf('+%d  ~%d  −%d', $record->created_count, $record->updated_count, $record->deleted_count))
                    ->tooltip('create · aggiornate · eliminate'),
                TextColumn::make('error_count')
                    ->label('Errori')
                    ->numeric()
                    ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),
                TextColumn::make('created_at')
                    ->label('Avviato')
                    ->since()
                    ->dateTimeTooltip('d/m/Y H:i:s')
                    ->sortable(),
                TextColumn::make('duration')
                    ->label('Durata')
                    ->state(fn (SyncRun $record): ?string => $record->durationForHumans())
                    ->placeholder('—'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Stato')
                    ->options(SyncRunStatus::class),
                SelectFilter::make('sync_job_id')
                    ->label('Job')
                    ->relationship('syncJob', 'name')
                    ->visible($showJob),
                SelectFilter::make('kind')
                    ->label('Tipo')
                    ->options(['sync' => 'Sync', 'dry_run' => 'Dry-run', 'purge' => 'Rimozione copie'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'sync' => $query->where('dry_run', false)->where('purge', false),
                        'dry_run' => $query->where('dry_run', true),
                        'purge' => $query->where('purge', true),
                        default => $query,
                    }),
            ])
            ->recordUrl(fn (SyncRun $record): string => SyncRunResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                // A plain link: works the same in the resource and in the job relation manager.
                Action::make('open')
                    ->label('Dettagli')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (SyncRun $record): string => SyncRunResource::getUrl('view', ['record' => $record])),
            ])
            ->emptyStateHeading('Nessun run')
            ->emptyStateDescription('I run compaiono qui dopo un dry-run, un "Esegui ora" o un sync pianificato.');
    }
}

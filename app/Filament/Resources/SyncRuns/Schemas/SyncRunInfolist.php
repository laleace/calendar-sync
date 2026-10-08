<?php

namespace App\Filament\Resources\SyncRuns\Schemas;

use App\Filament\Support\PlanFormatter;
use App\Models\SyncRun;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SyncRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Run')
                    ->columns(4)
                    ->schema([
                        TextEntry::make('syncJob.name')
                            ->label('Job'),
                        TextEntry::make('kind')
                            ->label('Tipo')
                            ->state(fn (SyncRun $record): string => $record->kindLabel())
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('triggered_by')
                            ->label('Avviato da')
                            ->badge(),
                        TextEntry::make('status')
                            ->label('Stato')
                            ->badge(),
                        TextEntry::make('created_at')
                            ->label('Accodato')
                            ->dateTime('d/m/Y H:i:s'),
                        TextEntry::make('started_at')
                            ->label('Inizio')
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('—'),
                        TextEntry::make('finished_at')
                            ->label('Fine')
                            ->dateTime('d/m/Y H:i:s')
                            ->placeholder('—'),
                        TextEntry::make('duration')
                            ->label('Durata')
                            ->state(fn (SyncRun $record): ?string => $record->durationForHumans())
                            ->placeholder('—'),
                        TextEntry::make('message')
                            ->label('Esito')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Risultato')
                    ->description(fn (SyncRun $record): ?string => $record->dry_run ? 'Dry-run: operazioni previste, nessuna modifica eseguita.' : null)
                    ->columns(5)
                    ->schema([
                        TextEntry::make('created_count')->label('Create')->numeric(),
                        TextEntry::make('updated_count')->label('Aggiornate')->numeric(),
                        TextEntry::make('deleted_count')->label('Eliminate')->numeric(),
                        TextEntry::make('skipped_count')->label('Saltati')->numeric(),
                        TextEntry::make('error_count')->label('Errori')->numeric()
                            ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),
                    ]),

                Section::make('Errori')
                    ->visible(fn (SyncRun $record): bool => filled($record->errors))
                    ->schema([
                        RepeatableEntry::make('errors')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Operazione'),
                                TableColumn::make('Sorgente'),
                                TableColumn::make('Evento'),
                                TableColumn::make('Messaggio'),
                            ])
                            ->schema([
                                TextEntry::make('op')
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => PlanFormatter::operationLabel($state))
                                    ->color(fn (?string $state): string => PlanFormatter::operationColor($state)),
                                TextEntry::make('source')->placeholder('—'),
                                TextEntry::make('event')
                                    ->state(fn (TextEntry $component): ?string => self::errorEvent($component))
                                    ->placeholder('—'),
                                TextEntry::make('message')->wrap(),
                            ]),
                    ]),

                Section::make('Operazioni')
                    ->description(fn (SyncRun $record): string => $record->dry_run
                        ? 'Operazioni previste dal dry-run (al massimo 500).'
                        : 'Operazioni tentate in questo run (al massimo 500); quelle fallite compaiono anche tra gli errori.')
                    ->visible(fn (SyncRun $record): bool => filled($record->plan))
                    ->collapsible()
                    ->schema([
                        RepeatableEntry::make('plan')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Operazione'),
                                TableColumn::make('Quando'),
                                TableColumn::make('Titolo della copia'),
                                TableColumn::make('Sorgente'),
                                TableColumn::make('Motivo'),
                            ])
                            ->schema([
                                TextEntry::make('op')
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => PlanFormatter::operationLabel($state))
                                    ->color(fn (?string $state): string => PlanFormatter::operationColor($state)),
                                TextEntry::make('start')
                                    ->formatStateUsing(fn (TextEntry $component): string => PlanFormatter::when(self::item($component))),
                                TextEntry::make('title'),
                                TextEntry::make('source')->placeholder('—'),
                                TextEntry::make('reason')->placeholder('—'),
                            ]),
                    ]),
            ]);
    }

    /**
     * The repeatable item (array) a nested entry belongs to.
     *
     * @return array<string, mixed>
     */
    private static function item(TextEntry $component): array
    {
        return (array) $component->getContainer()->getConstantState();
    }

    private static function errorEvent(TextEntry $component): ?string
    {
        $item = self::item($component);

        return $item['title'] ?? $item['uid'] ?? null;
    }
}

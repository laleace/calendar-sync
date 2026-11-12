<?php

namespace App\Filament\Resources\SyncRuns\Schemas;

use App\Filament\Support\PlanFormatter;
use App\Models\SyncRun;
use App\Support\DateFormat;
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
                            ->label(__('Type'))
                            ->state(fn (SyncRun $record): string => $record->kindLabel())
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('triggered_by')
                            ->label(__('Started by'))
                            ->badge(),
                        TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge(),
                        TextEntry::make('created_at')
                            ->label(__('Queued at'))
                            ->dateTime(DateFormat::dateTimeWithSeconds()),
                        TextEntry::make('started_at')
                            ->label(__('Start'))
                            ->dateTime(DateFormat::dateTimeWithSeconds())
                            ->placeholder('—'),
                        TextEntry::make('finished_at')
                            ->label(__('End'))
                            ->dateTime(DateFormat::dateTimeWithSeconds())
                            ->placeholder('—'),
                        TextEntry::make('duration')
                            ->label(__('Duration'))
                            ->state(fn (SyncRun $record): ?string => $record->durationForHumans())
                            ->placeholder('—'),
                        TextEntry::make('message')
                            ->label(__('Outcome'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make(__('Result'))
                    ->description(fn (SyncRun $record): ?string => $record->dry_run ? __('Dry-run: planned operations, nothing was changed.') : null)
                    ->columns(5)
                    ->schema([
                        TextEntry::make('created_count')->label(__('Created'))->numeric(),
                        TextEntry::make('updated_count')->label(__('Updated'))->numeric(),
                        TextEntry::make('deleted_count')->label(__('Deleted'))->numeric(),
                        TextEntry::make('skipped_count')->label(__('Skipped events'))->numeric(),
                        TextEntry::make('error_count')->label(__('Errors'))->numeric()
                            ->color(fn (int $state): ?string => $state > 0 ? 'danger' : null),
                    ]),

                Section::make(__('Errors'))
                    ->visible(fn (SyncRun $record): bool => filled($record->errors))
                    ->schema([
                        RepeatableEntry::make('errors')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make(__('Operation')),
                                TableColumn::make(__('Source')),
                                TableColumn::make(__('Event')),
                                TableColumn::make(__('Message')),
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

                Section::make(__('Operations'))
                    ->description(fn (SyncRun $record): string => $record->dry_run
                        ? __('Operations planned by the dry-run (500 at most).')
                        : __('Operations attempted in this run (500 at most); failed ones are also listed under errors.'))
                    ->visible(fn (SyncRun $record): bool => filled($record->plan))
                    ->collapsible()
                    ->schema([
                        RepeatableEntry::make('plan')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make(__('Operation')),
                                TableColumn::make(__('When')),
                                TableColumn::make(__('Copy title')),
                                TableColumn::make(__('Source')),
                                TableColumn::make(__('Reason')),
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

<?php

namespace App\Filament\Resources\SyncJobs\Pages;

use App\CalSync\Sync\SyncService;
use App\Filament\Actions\RunSyncNowAction;
use App\Filament\Resources\SyncJobs\SyncJobResource;
use App\Filament\Support\PlanFormatter;
use App\Models\SyncJob;
use App\Models\SyncRun;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\ArrayRecord;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;

/**
 * Runs a dry-run when opened and shows the stored plan. Nothing is written to the
 * calendars; the result is kept as a SyncRun.
 *
 * @property SyncJob $record
 */
class DryRunSyncJob extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = SyncJobResource::class;

    protected string $view = 'filament.resources.sync-jobs.pages.dry-run-sync-job';

    public ?int $runId = null;

    public function mount(int|string $record, SyncService $sync): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(static::getResource()::canEdit($this->record), 403);

        $this->runId = $sync->dryRun($this->record)->id;
    }

    public function getTitle(): string|Htmlable
    {
        return "Dry-run: {$this->record->name}";
    }

    #[Computed]
    public function run(): SyncRun
    {
        return SyncRun::findOrFail($this->runId);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('repeat')
                ->label(__('Repeat dry-run'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (SyncService $sync): void {
                    $this->runId = $sync->dryRun($this->record)->id;
                    unset($this->run);
                    $this->resetTable();
                }),
            RunSyncNowAction::make()
                ->record($this->record),
            EditAction::make()
                ->url(fn (): string => SyncJobResource::getUrl('edit', ['record' => $this->record])),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (int $page, int|string $recordsPerPage, array $filters): LengthAwarePaginator => $this->paginatePlan($page, $recordsPerPage, $filters['op']['value'] ?? null))
            ->columns([
                TextColumn::make('op')
                    ->label(__('Operation'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => PlanFormatter::operationLabel($state))
                    ->color(fn (string $state): string => PlanFormatter::operationColor($state)),
                TextColumn::make('when')
                    ->label(__('When')),
                TextColumn::make('title')
                    ->label(__('Copy title'))
                    ->wrap(),
                TextColumn::make('source')
                    ->label(__('Source'))
                    ->placeholder('—'),
                TextColumn::make('reason')
                    ->label(__('Reason'))
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('op')
                    ->label(__('Operation'))
                    ->options(collect(PlanFormatter::operations())->except('read')->all()),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading(__('Nothing to do'))
            ->emptyStateDescription(__('The target calendar is already up to date.'));
    }

    private function paginatePlan(int $page, int|string $perPage, ?string $operation): LengthAwarePaginator
    {
        $rows = collect($this->run->plan ?? [])
            ->when(filled($operation), fn ($rows) => $rows->where('op', $operation))
            ->values()
            ->map(fn (array $row, int $index) => [
                ArrayRecord::getKeyName() => (string) $index,
                ...$row,
                'when' => PlanFormatter::when($row),
            ]);

        $perPage = $perPage === 'all' ? max(1, $rows->count()) : (int) $perPage;

        return new LengthAwarePaginator($rows->forPage($page, $perPage)->values()->all(), $rows->count(), $perPage, $page);
    }
}

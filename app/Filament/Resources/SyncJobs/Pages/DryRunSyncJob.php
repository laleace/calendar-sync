<?php

namespace App\Filament\Resources\SyncJobs\Pages;

use App\CalSync\Sync\SyncService;
use App\Filament\Resources\SyncJobs\SyncJobResource;
use App\Models\SyncJob;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
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

    public const OPERATIONS = [
        'create' => ['Crea', 'success'],
        'update' => ['Aggiorna', 'info'],
        'delete' => ['Elimina', 'danger'],
        'skip' => ['Salta', 'gray'],
    ];

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
                ->label('Ripeti dry-run')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (SyncService $sync): void {
                    $this->runId = $sync->dryRun($this->record)->id;
                    unset($this->run);
                    $this->resetTable();
                }),
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
                    ->label('Operazione')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::OPERATIONS[$state][0] ?? $state)
                    ->color(fn (string $state): string => self::OPERATIONS[$state][1] ?? 'gray'),
                TextColumn::make('when')
                    ->label('Quando'),
                TextColumn::make('title')
                    ->label('Titolo della copia')
                    ->wrap(),
                TextColumn::make('source')
                    ->label('Sorgente')
                    ->placeholder('—'),
                TextColumn::make('reason')
                    ->label('Motivo')
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('op')
                    ->label('Operazione')
                    ->options(collect(self::OPERATIONS)->map(fn (array $op) => $op[0])->all()),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('Niente da fare')
            ->emptyStateDescription('Il calendario di destinazione è già allineato.');
    }

    private function paginatePlan(int $page, int|string $perPage, ?string $operation): LengthAwarePaginator
    {
        $timezone = config('calsync.timezone');
        $rows = collect($this->run->plan ?? [])
            ->when(filled($operation), fn ($rows) => $rows->where('op', $operation))
            ->values()
            ->map(fn (array $row, int $index) => [
                ArrayRecord::getKeyName() => (string) $index,
                ...$row,
                'when' => self::formatWhen($row, $timezone),
            ]);

        $perPage = $perPage === 'all' ? max(1, $rows->count()) : (int) $perPage;

        return new LengthAwarePaginator($rows->forPage($page, $perPage)->values()->all(), $rows->count(), $perPage, $page);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function formatWhen(array $row, string $timezone): string
    {
        if (blank($row['start'] ?? null)) {
            return '—';
        }

        if ($row['all_day'] ?? false) {
            $start = CarbonImmutable::parse($row['start']);
            $last = filled($row['end'] ?? null) ? CarbonImmutable::parse($row['end'])->subDay() : $start;

            return $start->format('d/m/Y').($last->isSameDay($start) ? '' : ' → '.$last->format('d/m/Y'));
        }

        $start = CarbonImmutable::parse($row['start'])->setTimezone($timezone);

        if (blank($row['end'] ?? null)) {
            return $start->format('d/m/Y H:i');
        }

        $end = CarbonImmutable::parse($row['end'])->setTimezone($timezone);

        return $start->format('d/m/Y H:i').' → '.($end->isSameDay($start) ? $end->format('H:i') : $end->format('d/m/Y H:i'));
    }
}

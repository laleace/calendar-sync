<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\SyncRuns\Tables\SyncRunsTable;
use App\Models\SyncRun;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LatestRuns extends TableWidget
{
    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return SyncRunsTable::configure($table)
            ->query(fn (): Builder => SyncRun::query()->latest('id')->limit(10))
            ->heading(__('Latest runs'))
            ->filters([])
            ->paginated(false);
    }
}

<?php

namespace App\Filament\Widgets;

use App\Enums\SyncRunStatus;
use App\Models\SyncJob;
use App\Models\SyncRun;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SyncOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $since = now()->subDay();
        $runs = SyncRun::where('created_at', '>=', $since)->where('dry_run', false);
        $failed = (clone $runs)->whereIn('status', [SyncRunStatus::Failed, SyncRunStatus::Partial])->count();
        $next = SyncJob::where('enabled', true)->min('next_run_at');

        return [
            Stat::make(__('Enabled jobs'), SyncJob::where('enabled', true)->count().' / '.SyncJob::count()),
            Stat::make(__('Syncs in the last 24 hours'), (clone $runs)->count())
                ->description($failed > 0 ? __(':count with errors', ['count' => $failed]) : __('no errors'))
                ->color($failed > 0 ? 'danger' : 'success'),
            Stat::make(__('Next sync'), $next ? now()->parse($next)->diffForHumans() : '—'),
        ];
    }
}

<?php

namespace App\Models;

use App\CalSync\Sync\EventFilters;
use App\Enums\PrivacyMode;
use App\Enums\SyncDirection;
use Carbon\CarbonImmutable;
use Database\Factories\SyncJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'enabled', 'target_calendar_id', 'direction', 'range_past_days', 'range_future_days',
    'interval_minutes', 'privacy_mode', 'busy_title', 'title_prefix', 'allow_duplicates',
    'skip_existing_in_target', 'filters', 'next_run_at', 'last_run_at',
])]
class SyncJob extends Model
{
    /** @use HasFactory<SyncJobFactory> */
    use HasFactory;

    protected $attributes = [
        'enabled' => false,
        'direction' => 'one_way',
        'range_past_days' => 7,
        'range_future_days' => 60,
        'interval_minutes' => 15,
        'privacy_mode' => 'full',
        'busy_title' => 'Occupato',
        'allow_duplicates' => false,
        'skip_existing_in_target' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'direction' => SyncDirection::class,
            'range_past_days' => 'integer',
            'range_future_days' => 'integer',
            'interval_minutes' => 'integer',
            'privacy_mode' => PrivacyMode::class,
            'allow_duplicates' => 'boolean',
            'skip_existing_in_target' => 'boolean',
            'filters' => 'array',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Calendar, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Calendar::class, 'target_calendar_id');
    }

    /**
     * Source calendars, ordered by priority (lowest position first).
     *
     * @return BelongsToMany<Calendar, $this>
     */
    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(Calendar::class, 'sync_job_sources')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /**
     * @return HasMany<EventMapping, $this>
     */
    public function mappings(): HasMany
    {
        return $this->hasMany(EventMapping::class);
    }

    /**
     * @return HasMany<SyncRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    /**
     * The include/exclude rules applied to the source events.
     */
    public function eventFilters(): EventFilters
    {
        return EventFilters::fromArray($this->filters);
    }

    /**
     * Replace the source calendars, keeping the given order as priority.
     *
     * @param  list<int>  $calendarIds
     */
    public function syncSources(array $calendarIds): void
    {
        $this->sources()->sync(
            collect(array_values($calendarIds))
                ->mapWithKeys(fn (int $id, int $position) => [$id => ['position' => $position]])
                ->all()
        );
    }

    /**
     * The sync window: from the start of (today - past days) to the end of (today + future days),
     * computed in the configured timezone.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function syncWindow(?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone(config('calsync.timezone'));

        return [
            $today->subDays($this->range_past_days)->startOfDay(),
            $today->addDays($this->range_future_days)->endOfDay(),
        ];
    }
}

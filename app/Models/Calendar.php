<?php

namespace App\Models;

use Database\Factories\CalendarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['account_id', 'remote_id', 'name', 'color', 'timezone', 'can_write', 'missing_since'])]
class Calendar extends Model
{
    /** @use HasFactory<CalendarFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'can_write' => 'boolean',
            'missing_since' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsToMany<SyncJob, $this>
     */
    public function sourceOfJobs(): BelongsToMany
    {
        return $this->belongsToMany(SyncJob::class, 'sync_job_sources')
            ->withPivot('position')
            ->withTimestamps();
    }

    /**
     * @return HasMany<SyncJob, $this>
     */
    public function targetOfJobs(): HasMany
    {
        return $this->hasMany(SyncJob::class, 'target_calendar_id');
    }

    /**
     * Calendars that can be used as a sync target.
     */
    #[Scope]
    protected function writable(Builder $query): void
    {
        $query->where('can_write', true)->whereNull('missing_since');
    }

    /**
     * Select options grouped by account, prefixed by its group. Calendars sharing a name
     * within an account (common with shared Google calendars) get their timezone and id
     * appended.
     *
     * @param  Collection<int, Calendar>  $calendars  With the account (and its group) loaded.
     * @return array<string, array<int, string>>
     */
    public static function groupedOptions(Collection $calendars): array
    {
        return $calendars
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            // "Personale · iCloud": accounts of the same group stay together.
            ->groupBy(fn (Calendar $calendar) => $calendar->account->group
                ? "{$calendar->account->group->name} · {$calendar->account->name}"
                : $calendar->account->name)
            ->sortKeys()
            ->map(function (Collection $group) {
                $duplicates = $group->countBy('name')->filter(fn (int $count) => $count > 1);

                return $group->mapWithKeys(fn (Calendar $calendar) => [
                    $calendar->id => $duplicates->has($calendar->name)
                        ? "{$calendar->name} ({$calendar->timezone}, #{$calendar->id})"
                        : $calendar->name,
                ])->all();
            })
            ->all();
    }
}

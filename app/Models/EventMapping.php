<?php

namespace App\Models;

use Database\Factories\EventMappingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sync_job_id', 'source_calendar_id', 'source_uid', 'recurrence_id', 'source_key',
    'target_calendar_id', 'target_event_id', 'target_etag', 'content_hash', 'starts_at', 'last_synced_at',
])]
class EventMapping extends Model
{
    /** @use HasFactory<EventMappingFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SyncJob, $this>
     */
    public function syncJob(): BelongsTo
    {
        return $this->belongsTo(SyncJob::class);
    }

    /**
     * @return BelongsTo<Calendar, $this>
     */
    public function sourceCalendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class, 'source_calendar_id');
    }

    /**
     * @return BelongsTo<Calendar, $this>
     */
    public function targetCalendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class, 'target_calendar_id');
    }
}

<?php

namespace App\Models;

use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use Database\Factories\SyncRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sync_job_id', 'triggered_by', 'dry_run', 'purge', 'status', 'started_at', 'finished_at',
    'created_count', 'updated_count', 'deleted_count', 'skipped_count', 'error_count',
    'errors', 'plan', 'message',
])]
class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use HasFactory, MassPrunable;

    protected $attributes = [
        'status' => 'queued',
        'dry_run' => false,
        'purge' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'triggered_by' => SyncTrigger::class,
            'status' => SyncRunStatus::class,
            'dry_run' => 'boolean',
            'purge' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'deleted_count' => 'integer',
            'skipped_count' => 'integer',
            'error_count' => 'integer',
            'errors' => 'array',
            'plan' => 'array',
        ];
    }

    /**
     * @return BelongsTo<SyncJob, $this>
     */
    public function syncJob(): BelongsTo
    {
        return $this->belongsTo(SyncJob::class);
    }

    public function kindLabel(): string
    {
        return match (true) {
            $this->purge => 'Rimozione copie',
            $this->dry_run => 'Dry-run',
            default => 'Sync',
        };
    }

    public function durationForHumans(): ?string
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        $seconds = $this->started_at->diffInSeconds($this->finished_at);

        return match (true) {
            $seconds < 1 => '< 1 s',
            $seconds < 60 => round($seconds).' s',
            default => intdiv((int) $seconds, 60).' min '.((int) $seconds % 60).' s',
        };
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(config('calsync.run_retention_days')));
    }
}

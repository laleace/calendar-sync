<?php

namespace App\Models;

use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A label to keep accounts and jobs apart in the panel (e.g. "Personale", "Utente X").
 * Purely organizational: syncing ignores it.
 */
#[Fillable(['name'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    protected $table = 'sync_groups';

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return HasMany<SyncJob, $this>
     */
    public function syncJobs(): HasMany
    {
        return $this->hasMany(SyncJob::class);
    }
}

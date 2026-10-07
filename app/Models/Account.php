<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type', 'base_url', 'credentials', 'principal_url', 'calendar_home_url', 'status', 'status_message', 'last_checked_at'])]
#[Hidden(['credentials'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'unknown',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'status' => AccountStatus::class,
            // CalDAV: username, password. Google: access_token, refresh_token, expires_at, email.
            'credentials' => 'encrypted:array',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Calendar, $this>
     */
    public function calendars(): HasMany
    {
        return $this->hasMany(Calendar::class);
    }

    public function credential(string $key): mixed
    {
        return $this->credentials[$key] ?? null;
    }

    /**
     * Merge values into the encrypted credentials blob, keeping the other keys.
     *
     * @param  array<string, mixed>  $values
     */
    public function mergeCredentials(#[\SensitiveParameter] array $values): void
    {
        $this->credentials = array_merge($this->credentials ?? [], $values);
    }

    /**
     * Whether calendars of this account are referenced by a sync job (as source or target).
     */
    public function isInUse(): bool
    {
        return $this->calendars()
            ->where(fn ($query) => $query->has('sourceOfJobs')->orHas('targetOfJobs'))
            ->exists();
    }

    /**
     * Accounts whose credentials were rejected or whose token expired.
     */
    #[Scope]
    protected function needingAttention(Builder $query): void
    {
        $query->whereIn('status', array_map(
            fn (AccountStatus $status) => $status->value,
            array_filter(AccountStatus::cases(), fn (AccountStatus $status) => $status->needsAttention()),
        ));
    }
}

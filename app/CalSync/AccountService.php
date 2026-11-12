<?php

namespace App\CalSync;

use App\CalSync\Dto\ConnectionResult;
use App\CalSync\Dto\DiscoverySummary;
use App\CalSync\Exceptions\ProviderException;
use App\CalSync\Support\SecretRedactor;
use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\Calendar;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Account operations shared by the CLI and the GUI: connection test and calendar
 * discovery, with the results persisted on the account and its calendars.
 */
final class AccountService
{
    public function __construct(private readonly ProviderRegistry $providers) {}

    public function testConnection(Account $account): ConnectionResult
    {
        try {
            $result = $this->providers->for($account)->testConnection($account);
        } catch (ProviderException $e) {
            $result = ConnectionResult::failed($e->accountStatus(), $e->getMessage());
        }

        $this->recordStatus($account, $result->status, $result->message);

        return $result;
    }

    /**
     * Discover the account calendars and upsert them. Calendars that disappeared are
     * flagged with missing_since rather than deleted, since jobs may reference them.
     *
     * @throws ProviderException
     */
    public function discoverCalendars(Account $account): DiscoverySummary
    {
        try {
            $result = $this->providers->for($account)->discoverCalendars($account);
        } catch (ProviderException $e) {
            $this->recordStatus($account, $e->accountStatus(), $e->getMessage());

            throw $e;
        }

        return DB::transaction(function () use ($account, $result) {
            $account->forceFill([
                'principal_url' => $result->principalUrl,
                'calendar_home_url' => $result->calendarHomeUrl,
            ]);
            $this->recordStatus($account, AccountStatus::Ok, trans_choice('Found :count calendar.|Found :count calendars.', count($result->calendars)));

            $existing = $account->calendars()->get();
            $found = new Collection;
            $created = $updated = 0;

            foreach ($result->calendars as $dto) {
                $calendar = $this->match($existing, $dto->remoteId) ?? new Calendar(['account_id' => $account->id]);
                $existing = $existing->reject(fn (Calendar $c) => $c->is($calendar));

                $calendar->fill([
                    'remote_id' => $dto->remoteId,
                    'name' => Str::limit($dto->name, 250),
                    'color' => $dto->color,
                    'timezone' => $dto->timezone,
                    'can_write' => $dto->canWrite,
                    'missing_since' => null,
                ]);

                if (! $calendar->exists) {
                    $created++;
                } elseif ($calendar->isDirty()) {
                    $updated++;
                }

                $calendar->save();
                $found->push($calendar);
            }

            $missing = $existing->whereNull('missing_since')->values();
            $missing->each(fn (Calendar $calendar) => $calendar->update(['missing_since' => now()]));

            return new DiscoverySummary($result, $found, $created, $updated, $missing);
        });
    }

    /**
     * Match by full URL first, then by path: iCloud may move an account to another
     * pXX-caldav shard, and the calendars must keep their ids (jobs reference them).
     *
     * @param  Collection<int, Calendar>  $existing
     */
    private function match(Collection $existing, string $remoteId): ?Calendar
    {
        return $existing->firstWhere('remote_id', $remoteId)
            ?? $existing->first(fn (Calendar $c) => str_starts_with($c->remote_id, 'http')
                && str_starts_with($remoteId, 'http')
                && (new Uri($c->remote_id))->getPath() === (new Uri($remoteId))->getPath());
    }

    private function recordStatus(Account $account, AccountStatus $status, string $message): void
    {
        $account->forceFill([
            'status' => $status,
            'status_message' => Str::limit(SecretRedactor::redact($message, SecretRedactor::secretsOf($account)), 490),
            'last_checked_at' => now(),
        ])->save();
    }
}

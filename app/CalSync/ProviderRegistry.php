<?php

namespace App\CalSync;

use App\CalSync\CalDav\CalDavProvider;
use App\CalSync\Contracts\CalendarProvider;
use App\CalSync\Contracts\WritableCalendarProvider;
use App\CalSync\Exceptions\UnsupportedAccountType;
use App\CalSync\Google\GoogleProvider;
use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Contracts\Container\Container;

final class ProviderRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(Account $account): CalendarProvider
    {
        return match ($account->type) {
            AccountType::IcloudCaldav, AccountType::CaldavGeneric => $this->container->make(CalDavProvider::class),
            AccountType::Google => $this->container->make(GoogleProvider::class),
        };
    }

    /**
     * The provider of a sync target.
     *
     * @throws UnsupportedAccountType when the provider cannot write events.
     */
    public function writerFor(Account $account): WritableCalendarProvider
    {
        $provider = $this->for($account);

        if (! $provider instanceof WritableCalendarProvider) {
            throw UnsupportedAccountType::forWriting($account->type);
        }

        return $provider;
    }
}

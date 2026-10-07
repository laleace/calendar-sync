<?php

namespace App\CalSync;

use App\CalSync\CalDav\CalDavProvider;
use App\CalSync\Contracts\CalendarProvider;
use App\CalSync\Exceptions\UnsupportedAccountType;
use App\Models\Account;
use Illuminate\Contracts\Container\Container;

final class ProviderRegistry
{
    public function __construct(private readonly Container $container) {}

    /**
     * @throws UnsupportedAccountType
     */
    public function for(Account $account): CalendarProvider
    {
        if ($account->type->isCalDav()) {
            return $this->container->make(CalDavProvider::class);
        }

        throw UnsupportedAccountType::for($account->type);
    }
}

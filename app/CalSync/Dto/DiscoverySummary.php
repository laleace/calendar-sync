<?php

namespace App\CalSync\Dto;

use App\Models\Calendar;
use Illuminate\Support\Collection;

final readonly class DiscoverySummary
{
    /**
     * @param  Collection<int, Calendar>  $calendars  Calendars found by this discovery, in server order.
     * @param  Collection<int, Calendar>  $missing  Calendars no longer returned by the server.
     */
    public function __construct(
        public DiscoveryResult $result,
        public Collection $calendars,
        public int $created,
        public int $updated,
        public Collection $missing,
    ) {}
}

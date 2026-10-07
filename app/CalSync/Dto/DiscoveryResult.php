<?php

namespace App\CalSync\Dto;

final readonly class DiscoveryResult
{
    /**
     * @param  list<CalendarDto>  $calendars
     */
    public function __construct(
        public array $calendars,
        public ?string $principalUrl = null,
        public ?string $calendarHomeUrl = null,
    ) {}
}

<?php

namespace App\CalSync\Dto;

final readonly class CalendarDto
{
    public function __construct(
        /** CalDAV: absolute collection URL. Google: calendarId. */
        public string $remoteId,
        public string $name,
        public bool $canWrite,
        /** #RRGGBB */
        public ?string $color = null,
        public ?string $timezone = null,
    ) {}
}

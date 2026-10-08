<?php

namespace App\CalSync\Dto;

final readonly class WrittenEvent
{
    public function __construct(
        public string $remoteId,
        public ?string $etag = null,
    ) {}
}

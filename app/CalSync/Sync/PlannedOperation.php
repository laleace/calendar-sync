<?php

namespace App\CalSync\Sync;

use App\CalSync\Dto\EventDto;
use App\Models\Calendar;
use App\Models\EventMapping;

final readonly class PlannedOperation
{
    /**
     * @param  EventDto|null  $source  Create/update: the source occurrence.
     * @param  EventDto|null  $target  Create/update: the copy to write.
     * @param  EventMapping|null  $mapping  Update/delete: the existing copy.
     */
    public function __construct(
        public OperationType $type,
        public string $sourceKey,
        public ?Calendar $sourceCalendar = null,
        public ?EventDto $source = null,
        public ?EventDto $target = null,
        public ?string $contentHash = null,
        public ?EventMapping $mapping = null,
        public ?string $reason = null,
    ) {}

    /**
     * Summary for dry-run previews and run logs (no internal ids).
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        $event = $this->target;

        return [
            'op' => $this->type->value,
            'title' => $event?->title ?? $this->mapping?->source_uid,
            'start' => $event ? ($event->allDay ? $event->start->format('Y-m-d') : $event->start->toIso8601String()) : $this->mapping?->starts_at?->toIso8601String(),
            'end' => $event ? ($event->allDay ? $event->end->format('Y-m-d') : $event->end->toIso8601String()) : null,
            'all_day' => $event?->allDay,
            'source' => $this->sourceCalendar?->name ?? $this->mapping?->sourceCalendar?->name,
            'reason' => $this->reason,
        ];
    }
}

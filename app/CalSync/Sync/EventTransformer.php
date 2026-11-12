<?php

namespace App\CalSync\Sync;

use App\CalSync\Dto\EventDto;
use App\CalSync\Markers;
use App\Enums\EventStatus;
use App\Enums\PrivacyMode;
use App\Models\Calendar;
use App\Models\SyncJob;

/**
 * Turns a source event into the copy a job writes on its target, and decides which
 * source events a job ignores.
 */
final class EventTransformer
{
    /** Bump when the copy payload changes: every copy is then updated once. */
    private const PAYLOAD_VERSION = 1;

    /**
     * Why the event is not copied, or null when it is.
     */
    public function skipReason(SyncJob $job, EventDto $event): ?string
    {
        return match (true) {
            // Copies made by any job: never a source (prevents loops and chains).
            $event->isMarked() => __('created by calendar-sync'),
            $event->status === EventStatus::Cancelled => 'annullato',
            $event->declined => __('invitation declined'),
            $job->privacy_mode === PrivacyMode::BusyOnly && $event->transparent => __('marked as free'),
            default => null,
        };
    }

    /**
     * Identity of a source occurrence within a job. Without duplicates allowed, the same
     * UID found in several sources maps to one key, hence one copy.
     */
    public function sourceKey(SyncJob $job, Calendar $source, EventDto $event): string
    {
        $parts = [$event->uid, $event->recurrenceId ?? ''];

        if ($job->allow_duplicates) {
            array_unshift($parts, (string) $source->id);
        }

        return hash('sha256', implode("\n", $parts));
    }

    public function toTarget(SyncJob $job, EventDto $event, string $sourceKey): EventDto
    {
        $busyOnly = $job->privacy_mode === PrivacyMode::BusyOnly;
        $title = $busyOnly ? $job->busy_title : $job->eventFilters()->copyTitle($event->title);

        if (filled($job->title_prefix)) {
            $title = trim($job->title_prefix.' '.$title);
        }

        return new EventDto(
            uid: Markers::caldavUid((string) $job->id, $sourceKey),
            recurrenceId: null,
            title: $title,
            start: $event->start,
            end: $event->end,
            allDay: $event->allDay,
            timezone: $event->timezone,
            location: $busyOnly ? null : $event->location,
            description: $busyOnly ? null : $event->description,
            status: $busyOnly ? EventStatus::Confirmed : $event->status,
            transparent: ! $busyOnly && $event->transparent,
            markers: ['job' => (string) $job->id, 'source' => $event->uid, 'key' => $sourceKey],
        );
    }

    /**
     * Hash of what is written on the target (not of the source): changing the prefix or
     * the privacy mode updates every copy.
     */
    public function hash(EventDto $target): string
    {
        return hash('sha256', json_encode([
            'v' => self::PAYLOAD_VERSION,
            'title' => $target->title,
            'start' => $target->allDay ? $target->start->format('Y-m-d') : $target->start->utc()->format('Y-m-d\TH:i:s\Z'),
            'end' => $target->allDay ? $target->end->format('Y-m-d') : $target->end->utc()->format('Y-m-d\TH:i:s\Z'),
            'all_day' => $target->allDay,
            'timezone' => $target->timezone,
            'location' => $target->location,
            'description' => $target->description,
            'status' => $target->status->value,
            'transparent' => $target->transparent,
        ], JSON_THROW_ON_ERROR));
    }
}

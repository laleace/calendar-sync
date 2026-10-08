<?php

namespace App\CalSync\Sync;

use App\CalSync\Dto\EventDto;
use App\CalSync\Dto\EventList;
use App\CalSync\Exceptions\AuthenticationFailed;
use App\CalSync\Exceptions\EventNotFound;
use App\CalSync\Exceptions\ProviderException;
use App\CalSync\Exceptions\ServerUnreachable;
use App\CalSync\Exceptions\TokenExpired;
use App\CalSync\ProviderRegistry;
use App\CalSync\Support\SecretRedactor;
use App\Models\Calendar;
use App\Models\EventMapping;
use App\Models\SyncJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One-way, idempotent sync of a job: N source calendars → 1 target calendar.
 *
 * plan() reads and compares, apply() writes. Safety rules:
 * - only events this job created (its event_mappings) are ever updated or deleted;
 * - a source that could not be read completely never causes deletes;
 * - copies of events that simply left the sync window are kept;
 * - copies deleted by hand on the target are restored (the target is read on every run).
 */
final class SyncEngine
{
    /**
     * A source that suddenly returns no events while it had at least this many copies
     * in the window is treated as unreliable: a glitch must not wipe the target.
     */
    private const EMPTY_SOURCE_GUARD = 3;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly EventTransformer $transformer,
    ) {}

    /**
     * @throws ProviderException when the target cannot be used at all.
     */
    public function plan(SyncJob $job, ?CarbonImmutable $now = null): SyncPlan
    {
        $job->loadMissing(['sources.account', 'target.account']);
        [$from, $to] = $job->syncWindow($now);
        $target = $job->target;

        $this->ensureUsableTarget($target);

        $mappings = $job->mappings()->with(['sourceCalendar', 'targetCalendar.account'])->get()->keyBy('source_key');
        $problems = [];
        $targetEvents = $this->readTarget($job, $from, $to, $problems);
        $foreignTargetUids = $this->foreignUids($job, $targetEvents);
        $present = $this->presentCopies($job, $targetEvents);

        /** @var array<string, array{calendar: Calendar, source: EventDto, target: EventDto, hash: string}> $desired */
        $desired = [];
        $skipped = [];
        $unreliable = [];

        foreach ($job->sources as $calendar) {
            $events = $this->readSource($job, $calendar, $target, $from, $to, $mappings, $problems, $unreliable);

            foreach ($events as $event) {
                $reason = $this->transformer->skipReason($job, $event)
                    ?? (isset($foreignTargetUids[$event->uid]) ? 'già presente nel calendario di destinazione' : null);
                $key = $this->transformer->sourceKey($job, $calendar, $event);

                // Sources are ordered by priority: the first one wins on duplicates.
                $reason ??= isset($desired[$key]) ? 'duplicato di un evento di '.$desired[$key]['calendar']->name : null;

                if ($reason !== null) {
                    $skipped[] = ['title' => $event->title, 'start' => $event->start->toIso8601String(), 'source' => $calendar->name, 'reason' => $reason];

                    continue;
                }

                $copy = $this->transformer->toTarget($job, $event, $key);
                $desired[$key] = ['calendar' => $calendar, 'source' => $event, 'target' => $copy, 'hash' => $this->transformer->hash($copy)];
            }
        }

        $operations = [];
        $unchanged = 0;

        foreach ($desired as $key => $item) {
            $mapping = $mappings->get($key);
            $create = new PlannedOperation(OperationType::Create, $key, $item['calendar'], $item['source'], $item['target'], $item['hash']);

            if ($mapping === null) {
                $operations[] = $create;
            } elseif ((int) $mapping->target_calendar_id !== $target->id) {
                // The job target changed: move the copy.
                $operations[] = new PlannedOperation(OperationType::Delete, $key, mapping: $mapping, reason: 'destinazione del job cambiata');
                $operations[] = $create;
            } elseif ($mapping->content_hash !== $item['hash']) {
                $operations[] = new PlannedOperation(OperationType::Update, $key, $item['calendar'], $item['source'], $item['target'], $item['hash'], $mapping);
            } elseif ($present !== null && ! isset($present['keys'][$key]) && ! isset($present['ids'][$mapping->target_event_id])) {
                $operations[] = new PlannedOperation(OperationType::Update, $key, $item['calendar'], $item['source'], $item['target'], $item['hash'], $mapping, 'copia cancellata nella destinazione: la ripristino');
            } else {
                $unchanged++;
            }
        }

        $sourceIds = $job->sources->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($mappings as $key => $mapping) {
            if (isset($desired[$key])) {
                continue;
            }

            $reason = match (true) {
                ! in_array((int) $mapping->source_calendar_id, $sourceIds, true) => 'calendario sorgente rimosso dal job',
                (int) $mapping->target_calendar_id !== $target->id => 'destinazione del job cambiata',
                isset($unreliable[$mapping->source_calendar_id]) => null,
                // Left the window (usually: it is in the past). Not ours to judge any more.
                $mapping->starts_at < $from || $mapping->starts_at >= $to => null,
                default => 'evento non più presente nella sorgente',
            };

            if ($reason !== null) {
                $operations[] = new PlannedOperation(OperationType::Delete, $key, mapping: $mapping, reason: $reason);
            }
        }

        return new SyncPlan($from, $to, $operations, $unchanged, $skipped, $problems);
    }

    /**
     * Removal of every copy the job created, whatever its date.
     */
    public function planPurge(SyncJob $job, ?CarbonImmutable $now = null): SyncPlan
    {
        [$from, $to] = $job->syncWindow($now);

        $operations = $job->mappings()->with(['sourceCalendar', 'targetCalendar.account'])->get()
            ->map(fn (EventMapping $mapping) => new PlannedOperation(OperationType::Delete, $mapping->source_key, mapping: $mapping, reason: 'rimozione delle copie del job'))
            ->values()
            ->all();

        return new SyncPlan($from, $to, $operations);
    }

    public function apply(SyncJob $job, SyncPlan $plan): SyncResult
    {
        $result = new SyncResult;
        $target = $job->target;

        foreach ($plan->operations as $operation) {
            try {
                match ($operation->type) {
                    OperationType::Create => $this->create($job, $target, $operation, $result),
                    OperationType::Update => $this->update($job, $target, $operation, $result),
                    OperationType::Delete => $this->delete($operation, $result),
                };
            } catch (ProviderException $e) {
                $result->errors[] = $this->error($operation, $e);

                // Credentials or connectivity problems: every other write would fail the same way.
                if ($e instanceof AuthenticationFailed || $e instanceof TokenExpired || $e instanceof ServerUnreachable) {
                    $calendar = $operation->type === OperationType::Delete ? $operation->mapping->targetCalendar : $target;
                    $calendar->account->forceFill(['status' => $e->accountStatus(), 'status_message' => $e->getMessage(), 'last_checked_at' => now()])->save();
                    $result->fatal = $e->getMessage();

                    break;
                }
            }
        }

        return $result;
    }

    private function ensureUsableTarget(Calendar $target): void
    {
        if ($target->missing_since !== null) {
            throw new ProviderException("Il calendario di destinazione \"{$target->name}\" non è più presente sul server.");
        }

        if (! $target->can_write) {
            throw new ProviderException("Il calendario di destinazione \"{$target->name}\" è in sola lettura.");
        }

        $this->providers->writerFor($target->account);
    }

    /**
     * @param  Collection<string, EventMapping>  $mappings
     * @param  list<string>  $problems
     * @param  array<int, true>  $unreliable
     * @return list<EventDto>
     */
    private function readSource(SyncJob $job, Calendar $calendar, Calendar $target, CarbonImmutable $from, CarbonImmutable $to, Collection $mappings, array &$problems, array &$unreliable): array
    {
        if ($calendar->is($target)) {
            $problems[] = "Il calendario \"{$calendar->name}\" è sia sorgente sia destinazione: ignorato come sorgente.";
            $unreliable[$calendar->id] = true;

            return [];
        }

        if ($calendar->missing_since !== null) {
            $problems[] = "Il calendario sorgente \"{$calendar->name}\" non è più presente sul server.";
            $unreliable[$calendar->id] = true;

            return [];
        }

        try {
            $list = $this->providers->for($calendar->account)->listEvents($calendar, $from, $to);
        } catch (ProviderException $e) {
            $problems[] = "{$calendar->name}: {$e->getMessage()}";
            $unreliable[$calendar->id] = true;

            return [];
        }

        foreach ($list->problems as $problem) {
            $problems[] = "{$calendar->name}: {$problem}";
            $unreliable[$calendar->id] = true;
        }

        $copiesInWindow = $mappings->filter(fn (EventMapping $m) => (int) $m->source_calendar_id === $calendar->id && $m->starts_at >= $from && $m->starts_at < $to)->count();

        if (count($list) === 0 && $copiesInWindow >= self::EMPTY_SOURCE_GUARD) {
            $problems[] = "{$calendar->name}: il calendario risulta vuoto ma ha {$copiesInWindow} copie nel periodo; per sicurezza non elimino nulla.";
            $unreliable[$calendar->id] = true;
        }

        return $list->events;
    }

    /**
     * Events on the target in the window, null when they cannot be read completely. The
     * target is needed to skip events already there and to restore deleted copies.
     *
     * @param  list<string>  $problems
     *
     * @throws ProviderException when skip_existing_in_target cannot be honoured.
     */
    private function readTarget(SyncJob $job, CarbonImmutable $from, CarbonImmutable $to, array &$problems): ?EventList
    {
        try {
            $list = $this->providers->for($job->target->account)->listEvents($job->target, $from, $to);
        } catch (ProviderException $e) {
            if ($job->skip_existing_in_target) {
                throw new ProviderException("Impossibile leggere la destinazione per saltare gli eventi già presenti: {$e->getMessage()}", previous: $e);
            }

            $problems[] = "Destinazione non leggibile ({$e->getMessage()}): le copie cancellate a mano non verranno ripristinate in questo giro.";

            return null;
        }

        if (! $list->isComplete()) {
            $problems[] = 'Destinazione letta in modo incompleto: le copie cancellate a mano non verranno ripristinate in questo giro.';

            return $job->skip_existing_in_target ? $list : null;
        }

        return $list;
    }

    /**
     * UIDs of target events this job did not create (e.g. the original invitation).
     *
     * @return array<string, true>
     */
    private function foreignUids(SyncJob $job, ?EventList $targetEvents): array
    {
        if (! $job->skip_existing_in_target || $targetEvents === null) {
            return [];
        }

        return collect($targetEvents->events)
            ->reject(fn (EventDto $event) => ($event->markers['job'] ?? null) === (string) $job->id)
            ->mapWithKeys(fn (EventDto $event) => [$event->uid => true])
            ->all();
    }

    /**
     * Copies of this job found on the target, by source key and by remote id (the id
     * covers servers that drop the X- properties carrying the key).
     *
     * @return array{keys: array<string, true>, ids: array<string, true>}|null
     */
    private function presentCopies(SyncJob $job, ?EventList $targetEvents): ?array
    {
        if ($targetEvents === null || ! $targetEvents->isComplete()) {
            return null;
        }

        $present = ['keys' => [], 'ids' => []];

        foreach ($targetEvents->events as $event) {
            if (filled($event->remoteId)) {
                $present['ids'][$event->remoteId] = true;
            }

            if (($event->markers['job'] ?? null) === (string) $job->id && isset($event->markers['key'])) {
                $present['keys'][$event->markers['key']] = true;
            }
        }

        return $present;
    }

    private function create(SyncJob $job, Calendar $target, PlannedOperation $operation, SyncResult $result): void
    {
        $written = $this->providers->writerFor($target->account)->createEvent($target, $operation->target);

        // Saved right after the write: a crash in between is recovered through the
        // deterministic id of the copy (conflict → take over).
        $job->mappings()->updateOrCreate(['source_key' => $operation->sourceKey], [
            ...$this->mappingAttributes($operation),
            'target_calendar_id' => $target->id,
            'target_event_id' => $written->remoteId,
            'target_etag' => $written->etag,
        ]);

        $result->created++;
    }

    private function update(SyncJob $job, Calendar $target, PlannedOperation $operation, SyncResult $result): void
    {
        $writer = $this->providers->writerFor($target->account);

        try {
            $written = $writer->updateEvent($target, $operation->mapping->target_event_id, $operation->target);
        } catch (EventNotFound) {
            // Deleted by hand on the target: the source is the master, restore it.
            $written = $writer->createEvent($target, $operation->target);
        }

        $operation->mapping->update([
            ...$this->mappingAttributes($operation),
            'target_event_id' => $written->remoteId,
            'target_etag' => $written->etag,
        ]);

        $result->updated++;
    }

    private function delete(PlannedOperation $operation, SyncResult $result): void
    {
        $mapping = $operation->mapping;
        $calendar = $mapping->targetCalendar;

        $this->providers->writerFor($calendar->account)->deleteEvent($calendar, $mapping->target_event_id);
        $mapping->delete();

        $result->deleted++;
    }

    /**
     * @return array<string, mixed>
     */
    private function mappingAttributes(PlannedOperation $operation): array
    {
        return [
            'source_calendar_id' => $operation->sourceCalendar->id,
            'source_uid' => $operation->source->uid,
            'recurrence_id' => $operation->source->recurrenceId,
            'content_hash' => $operation->contentHash,
            'starts_at' => $operation->source->start->utc(),
            'last_synced_at' => now(),
        ];
    }

    /**
     * @return array{op: string, source: ?string, uid: ?string, title: ?string, message: string}
     */
    private function error(PlannedOperation $operation, ProviderException $e): array
    {
        return [
            'op' => $operation->type->value,
            'source' => $operation->sourceCalendar?->name ?? $operation->mapping?->sourceCalendar?->name,
            'uid' => $operation->source?->uid ?? $operation->mapping?->source_uid,
            'title' => $operation->target?->title,
            'message' => SecretRedactor::redact($e->getMessage()),
        ];
    }
}

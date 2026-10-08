<?php

use App\CalSync\Contracts\CalendarProvider;
use App\CalSync\Contracts\WritableCalendarProvider;
use App\CalSync\Dto\ConnectionResult;
use App\CalSync\Dto\DiscoveryResult;
use App\CalSync\Dto\EventDto;
use App\CalSync\Dto\EventList;
use App\CalSync\Dto\WrittenEvent;
use App\CalSync\Exceptions\ProviderException;
use App\CalSync\Exceptions\TokenExpired;
use App\CalSync\Google\GoogleProvider;
use App\CalSync\Sync\SyncService;
use App\Enums\AccountStatus;
use App\Enums\EventStatus;
use App\Enums\PrivacyMode;
use App\Enums\SyncRunStatus;
use App\Models\Account;
use App\Models\Calendar;
use App\Models\EventMapping;
use App\Models\SyncJob;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Tests\Support\InMemoryCalendarProvider as Fake;

beforeEach(function () {
    // Window of the default job: 1 Oct → 7 Dec 2026.
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Europe/Rome'));
    $this->fake = Fake::install();

    $icloud = Account::factory()->create(['name' => 'iCloud']);
    $google = Account::factory()->google()->create(['name' => 'Google']);

    $this->home = Calendar::factory()->for($icloud)->create(['name' => 'Casa', 'remote_id' => 'icloud/casa']);
    $this->work = Calendar::factory()->for($icloud)->create(['name' => 'Lavoro', 'remote_id' => 'icloud/lavoro']);
    $this->target = Calendar::factory()->for($google)->create(['name' => 'Agenda', 'remote_id' => 'google/agenda']);

    $this->job = SyncJob::factory()->create(['target_calendar_id' => $this->target->id]);
    $this->job->syncSources([$this->home->id]);
});

function syncNow(SyncJob $job): SyncRun
{
    return app(SyncService::class)->run($job->fresh());
}

function titlesOn(Fake $fake, Calendar $calendar): array
{
    return collect($fake->eventsOn($calendar))->pluck('title')->sort()->values()->all();
}

it('copies new events to the target with the job markers', function () {
    $dinner = $this->fake->seed($this->home, Fake::event('dinner@icloud', 'Cena con Luca', '2026-10-14 18:30', '2026-10-14 20:00', ['location' => 'Trattoria']));
    $this->fake->seed($this->home, Fake::event('dentist@icloud', 'Dentista', '2026-10-21 15:00', '2026-10-21 16:30'));

    $run = syncNow($this->job);

    expect($run->status)->toBe(SyncRunStatus::Success)
        ->and($run->created_count)->toBe(2)
        ->and(titlesOn($this->fake, $this->target))->toBe(['Cena con Luca', 'Dentista']);

    $copy = collect($this->fake->eventsOn($this->target))->firstWhere('title', 'Cena con Luca');
    $mapping = EventMapping::where('source_uid', 'dinner@icloud')->sole();

    expect($copy->markers)->toBe(['job' => (string) $this->job->id, 'source' => 'dinner@icloud', 'key' => $mapping->source_key])
        ->and($copy->location)->toBe('Trattoria')
        ->and($copy->start->eq($dinner->start))->toBeTrue()
        ->and($mapping->target_event_id)->toBe($copy->remoteId)
        ->and($mapping->source_calendar_id)->toBe($this->home->id)
        ->and($mapping->starts_at->eq($dinner->start))->toBeTrue();
});

it('is idempotent', function () {
    $this->fake->seed($this->home, Fake::event('a', 'Uno', '2026-10-10 09:00', '2026-10-10 10:00'));
    $this->fake->seed($this->home, Fake::event('b', 'Due', '2026-10-11 09:00', '2026-10-11 10:00'));

    syncNow($this->job);
    $writes = count($this->fake->writes);
    $second = syncNow($this->job);

    expect($second->created_count + $second->updated_count + $second->deleted_count)->toBe(0)
        ->and($second->message)->toBe('2 copie già allineate.')
        ->and($this->fake->writes)->toHaveCount($writes);
});

it('updates copies when the source event changes', function () {
    $this->fake->seed($this->home, Fake::event('a', 'Riunione', '2026-10-10 09:00', '2026-10-10 10:00'));
    syncNow($this->job);

    $this->fake->remove($this->home, 'a');
    $this->fake->seed($this->home, Fake::event('a', 'Riunione spostata', '2026-10-10 11:00', '2026-10-10 12:00'));
    $run = syncNow($this->job);

    $copy = $this->fake->eventsOn($this->target)[0];

    expect($run->updated_count)->toBe(1)
        ->and($run->created_count)->toBe(0)
        ->and($copy->title)->toBe('Riunione spostata')
        ->and($copy->start->format('H:i'))->toBe('11:00')
        ->and($this->fake->eventsOn($this->target))->toHaveCount(1);
});

it('deletes copies of events removed from the source', function () {
    $this->fake->seed($this->home, Fake::event('a', 'Resta', '2026-10-10 09:00', '2026-10-10 10:00'));
    $this->fake->seed($this->home, Fake::event('b', 'Sparisce', '2026-10-11 09:00', '2026-10-11 10:00'));
    syncNow($this->job);

    $this->fake->remove($this->home, 'b');
    $run = syncNow($this->job);

    expect($run->deleted_count)->toBe(1)
        ->and(titlesOn($this->fake, $this->target))->toBe(['Resta'])
        ->and(EventMapping::count())->toBe(1);
});

it('keeps the copies of events that left the window', function () {
    $this->fake->seed($this->home, Fake::event('past', 'Passato', '2026-10-02 09:00', '2026-10-02 10:00'));
    syncNow($this->job);

    // Two weeks later the window starts on 15 Oct: the event is no longer listed.
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00', 'Europe/Rome'));
    $run = syncNow($this->job);

    expect($run->deleted_count)->toBe(0)
        ->and(titlesOn($this->fake, $this->target))->toBe(['Passato']);
});

it('never deletes copies when a source cannot be read', function () {
    $this->fake->seed($this->home, Fake::event('a', 'Uno', '2026-10-10 09:00', '2026-10-10 10:00'));
    syncNow($this->job);

    $this->fake->readFailures['icloud/casa'] = new ProviderException('Server iCloud non raggiungibile');
    $run = syncNow($this->job);

    expect($run->status)->toBe(SyncRunStatus::Partial)
        ->and($run->deleted_count)->toBe(0)
        ->and($run->errors[0]['message'])->toContain('Server iCloud non raggiungibile')
        ->and(titlesOn($this->fake, $this->target))->toBe(['Uno']);
});

it('never deletes copies when a source has unreadable events', function () {
    $this->fake->seed($this->home, Fake::event('a', 'Uno', '2026-10-10 09:00', '2026-10-10 10:00'));
    syncNow($this->job);

    // The event "disappears" because it can no longer be parsed.
    $this->fake->remove($this->home, 'a');
    $this->fake->readProblems['icloud/casa'] = ['Evento a non leggibile'];
    $run = syncNow($this->job);

    expect($run->deleted_count)->toBe(0)
        ->and($run->status)->toBe(SyncRunStatus::Partial)
        ->and(titlesOn($this->fake, $this->target))->toBe(['Uno']);
});

it('does not wipe the target when a source suddenly looks empty', function () {
    foreach (['a', 'b', 'c'] as $i => $uid) {
        $this->fake->seed($this->home, Fake::event($uid, "Evento {$uid}", '2026-10-1'.$i.' 09:00', '2026-10-1'.$i.' 10:00'));
    }
    syncNow($this->job);

    $this->fake->calendars['icloud/casa'] = [];
    $run = syncNow($this->job);

    expect($run->deleted_count)->toBe(0)
        ->and($run->errors[0]['message'])->toContain('risulta vuoto')
        ->and($this->fake->eventsOn($this->target))->toHaveCount(3);
});

it('merges several sources into one target', function () {
    $this->job->syncSources([$this->home->id, $this->work->id]);
    $this->fake->seed($this->home, Fake::event('h1', 'Spesa', '2026-10-10 09:00', '2026-10-10 10:00'));
    $this->fake->seed($this->work, Fake::event('w1', 'Riunione', '2026-10-12 09:00', '2026-10-12 10:00'));
    $this->fake->seed($this->work, Fake::event('w2', 'Call', '2026-10-13 09:00', '2026-10-13 10:00'));

    $run = syncNow($this->job);

    expect($run->created_count)->toBe(3)
        ->and(titlesOn($this->fake, $this->target))->toBe(['Call', 'Riunione', 'Spesa'])
        ->and(EventMapping::where('source_calendar_id', $this->work->id)->count())->toBe(2);
});

it('creates one copy of an event found in several sources, the first source winning', function () {
    $this->job->syncSources([$this->work->id, $this->home->id]);
    $this->fake->seed($this->home, Fake::event('invite@example.com', 'Riunione (da Casa)', '2026-10-12 09:00', '2026-10-12 10:00'));
    $this->fake->seed($this->work, Fake::event('invite@example.com', 'Riunione (da Lavoro)', '2026-10-12 09:00', '2026-10-12 10:00'));

    $run = syncNow($this->job);

    expect($run->created_count)->toBe(1)
        ->and($run->skipped_count)->toBe(1)
        ->and(titlesOn($this->fake, $this->target))->toBe(['Riunione (da Lavoro)']);
});

it('creates one copy per source when duplicates are allowed', function () {
    $this->job->update(['allow_duplicates' => true]);
    $this->job->syncSources([$this->home->id, $this->work->id]);
    $this->fake->seed($this->home, Fake::event('invite@example.com', 'Riunione (da Casa)', '2026-10-12 09:00', '2026-10-12 10:00'));
    $this->fake->seed($this->work, Fake::event('invite@example.com', 'Riunione (da Lavoro)', '2026-10-12 09:00', '2026-10-12 10:00'));

    expect(syncNow($this->job)->created_count)->toBe(2);
});

it('copies each occurrence of a recurring event', function () {
    $this->fake->seed($this->home, Fake::event('weekly', 'Standup', '2026-10-12 09:00', '2026-10-12 09:15', ['recurrenceId' => '20261012T070000Z']));
    $this->fake->seed($this->home, Fake::event('weekly', 'Standup', '2026-10-19 09:00', '2026-10-19 09:15', ['recurrenceId' => '20261019T070000Z']));

    expect(syncNow($this->job)->created_count)->toBe(2)
        ->and(EventMapping::pluck('recurrence_id')->sort()->values()->all())->toBe(['20261012T070000Z', '20261019T070000Z']);
});

it('skips events already in the target when asked to', function () {
    $this->fake->seed($this->target, Fake::event('invite@example.com', 'Invito originale', '2026-10-12 09:00', '2026-10-12 10:00'));
    $this->fake->seed($this->home, Fake::event('invite@example.com', 'Invito', '2026-10-12 09:00', '2026-10-12 10:00'));

    $this->job->update(['skip_existing_in_target' => true]);
    $skipping = syncNow($this->job);

    expect($skipping->created_count)->toBe(0)
        ->and($skipping->skipped_count)->toBe(1);

    $this->job->update(['skip_existing_in_target' => false]);
    expect(syncNow($this->job)->created_count)->toBe(1);
});

it('writes busy blocks without details in busy-only mode', function () {
    $this->job->update(['privacy_mode' => PrivacyMode::BusyOnly, 'busy_title' => 'Occupato', 'title_prefix' => '[Ale]']);
    $this->fake->seed($this->home, Fake::event('a', 'Visita medica', '2026-10-10 09:00', '2026-10-10 10:00', ['location' => 'Ospedale', 'description' => 'Referti']));
    $this->fake->seed($this->home, Fake::event('free', 'Promemoria', '2026-10-11 09:00', '2026-10-11 09:15', ['transparent' => true]));

    $run = syncNow($this->job);
    $copy = $this->fake->eventsOn($this->target)[0];

    expect($run->created_count)->toBe(1)
        ->and($run->skipped_count)->toBe(1)
        ->and($copy->title)->toBe('[Ale] Occupato')
        ->and($copy->location)->toBeNull()
        ->and($copy->description)->toBeNull()
        ->and($copy->transparent)->toBeFalse();
});

it('applies the title prefix in full mode and updates copies when it changes', function () {
    $this->job->update(['title_prefix' => '[iCloud]']);
    $this->fake->seed($this->home, Fake::event('a', 'Cena', '2026-10-10 20:00', '2026-10-10 22:00'));
    syncNow($this->job);

    expect(titlesOn($this->fake, $this->target))->toBe(['[iCloud] Cena']);

    $this->job->update(['title_prefix' => '[Casa]']);

    expect(syncNow($this->job)->updated_count)->toBe(1)
        ->and(titlesOn($this->fake, $this->target))->toBe(['[Casa] Cena']);
});

it('ignores declined invitations, cancelled events and copies made by calendar-sync', function () {
    $this->fake->seed($this->home, Fake::event('declined', 'Rifiutato', '2026-10-10 09:00', '2026-10-10 10:00', ['declined' => true]));
    $this->fake->seed($this->home, Fake::event('cancelled', 'Annullato', '2026-10-11 09:00', '2026-10-11 10:00', ['status' => EventStatus::Cancelled]));
    $this->fake->seed($this->home, Fake::event('copy', 'Copia', '2026-10-12 09:00', '2026-10-12 10:00', ['markers' => ['job' => '99']]));

    $run = syncNow($this->job);

    expect($run->created_count)->toBe(0)
        ->and($run->skipped_count)->toBe(3)
        ->and($this->fake->eventsOn($this->target))->toBeEmpty();
});

it('does not loop between two calendars of the same Google account', function () {
    $google = $this->target->account;
    $a = Calendar::factory()->for($google)->create(['name' => 'Personale', 'remote_id' => 'google/personale']);
    $b = Calendar::factory()->for($google)->create(['name' => 'Famiglia', 'remote_id' => 'google/famiglia']);

    $aToB = SyncJob::factory()->create(['target_calendar_id' => $b->id]);
    $aToB->syncSources([$a->id]);
    $bToA = SyncJob::factory()->create(['target_calendar_id' => $a->id]);
    $bToA->syncSources([$b->id]);

    $this->fake->seed($a, Fake::event('a1@google.com', 'Palestra', '2026-10-10 18:00', '2026-10-10 19:00'));
    $this->fake->seed($b, Fake::event('b1@google.com', 'Cena dai nonni', '2026-10-11 20:00', '2026-10-11 22:00'));

    foreach (range(1, 3) as $round) {
        $first = syncNow($aToB);
        $second = syncNow($bToA);
    }

    // Each calendar holds its own event plus one copy of the other: no copies of copies.
    expect(titlesOn($this->fake, $a))->toBe(['Cena dai nonni', 'Palestra'])
        ->and(titlesOn($this->fake, $b))->toBe(['Cena dai nonni', 'Palestra'])
        ->and($first->created_count + $first->updated_count + $first->deleted_count)->toBe(0)
        ->and($second->created_count + $second->updated_count + $second->deleted_count)->toBe(0)
        ->and(EventMapping::count())->toBe(2);
});

it('never touches target events it did not create', function () {
    $foreign = $this->fake->seed($this->target, Fake::event('mine@google.com', 'Evento mio su Google', '2026-10-10 09:00', '2026-10-10 10:00'));
    $this->fake->seed($this->home, Fake::event('a', 'Da iCloud', '2026-10-11 09:00', '2026-10-11 10:00'));
    syncNow($this->job);

    $this->fake->remove($this->home, 'a');
    syncNow($this->job);

    expect($this->fake->eventsOn($this->target))->toHaveCount(1)
        ->and($this->fake->eventsOn($this->target)[0]->remoteId)->toBe($foreign->remoteId)
        ->and(collect($this->fake->writes)->pluck('id'))->not->toContain($foreign->remoteId);
});

it('moves the copies when the job target changes', function () {
    $newTarget = Calendar::factory()->for($this->target->account)->create(['remote_id' => 'google/nuova']);
    $this->fake->seed($this->home, Fake::event('a', 'Uno', '2026-10-10 09:00', '2026-10-10 10:00'));
    syncNow($this->job);

    $this->job->update(['target_calendar_id' => $newTarget->id]);
    $run = syncNow($this->job);

    expect($run->deleted_count)->toBe(1)
        ->and($run->created_count)->toBe(1)
        ->and($this->fake->eventsOn($this->target))->toBeEmpty()
        ->and(titlesOn($this->fake, $newTarget))->toBe(['Uno'])
        ->and(EventMapping::sole()->target_calendar_id)->toBe($newTarget->id);
});

it('removes the copies of a source removed from the job', function () {
    $this->job->syncSources([$this->home->id, $this->work->id]);
    $this->fake->seed($this->home, Fake::event('h', 'Da Casa', '2026-10-10 09:00', '2026-10-10 10:00'));
    $this->fake->seed($this->work, Fake::event('w', 'Da Lavoro', '2026-10-11 09:00', '2026-10-11 10:00'));
    syncNow($this->job);

    $this->job->syncSources([$this->home->id]);
    $run = syncNow($this->job);

    expect($run->deleted_count)->toBe(1)
        ->and(titlesOn($this->fake, $this->target))->toBe(['Da Casa']);
});

it('restores a copy deleted by hand when its source changes', function () {
    $this->fake->seed($this->home, Fake::event('a', 'Uno', '2026-10-10 09:00', '2026-10-10 10:00'));
    syncNow($this->job);

    $this->fake->calendars['google/agenda'] = [];
    $this->fake->remove($this->home, 'a');
    $this->fake->seed($this->home, Fake::event('a', 'Uno (modificato)', '2026-10-10 09:00', '2026-10-10 10:00'));
    $run = syncNow($this->job);

    expect($run->updated_count)->toBe(1)
        ->and(titlesOn($this->fake, $this->target))->toBe(['Uno (modificato)']);
});

it('stops at the first credentials error on the target', function () {
    foreach (['a', 'b', 'c'] as $i => $uid) {
        $this->fake->seed($this->home, Fake::event($uid, "Evento {$uid}", '2026-10-1'.$i.' 09:00', '2026-10-1'.$i.' 10:00'));
    }
    $this->fake->writeFailures['google/agenda'] = new TokenExpired('Il collegamento con Google è scaduto: riconnetti l\'account.');

    $run = syncNow($this->job);

    expect($run->status)->toBe(SyncRunStatus::Failed)
        ->and($run->error_count)->toBe(1)
        ->and($run->message)->toContain('Interrotto')
        ->and($this->target->account->fresh()->status)->toBe(AccountStatus::TokenExpired)
        ->and(EventMapping::count())->toBe(0);
});

it('keeps going after a single failed write', function () {
    $this->job->syncSources([$this->home->id, $this->work->id]);
    $this->fake->seed($this->home, Fake::event('a', 'Uno', '2026-10-10 09:00', '2026-10-10 10:00'));
    syncNow($this->job);

    // A delete fails on the target, the create of a new event still happens.
    $this->fake->remove($this->home, 'a');
    $this->fake->seed($this->work, Fake::event('w', 'Nuovo', '2026-10-12 09:00', '2026-10-12 10:00'));
    app()->instance(GoogleProvider::class, new class($this->fake) implements CalendarProvider, WritableCalendarProvider
    {
        public function __construct(private Fake $inner) {}

        public function testConnection(Account $account): ConnectionResult
        {
            return $this->inner->testConnection($account);
        }

        public function discoverCalendars(Account $account): DiscoveryResult
        {
            return $this->inner->discoverCalendars($account);
        }

        public function listEvents(Calendar $calendar, CarbonImmutable $from, CarbonImmutable $to): EventList
        {
            return $this->inner->listEvents($calendar, $from, $to);
        }

        public function createEvent(Calendar $calendar, EventDto $event): WrittenEvent
        {
            return $this->inner->createEvent($calendar, $event);
        }

        public function updateEvent(Calendar $calendar, string $remoteId, EventDto $event): WrittenEvent
        {
            return $this->inner->updateEvent($calendar, $remoteId, $event);
        }

        public function deleteEvent(Calendar $calendar, string $remoteId): void
        {
            throw new ProviderException('Errore Google (HTTP 500): backend error');
        }
    });

    $run = syncNow($this->job);

    expect($run->status)->toBe(SyncRunStatus::Partial)
        ->and($run->created_count)->toBe(1)
        ->and($run->errors[0])->toMatchArray(['op' => 'delete', 'uid' => 'a', 'message' => 'Errore Google (HTTP 500): backend error']);
});

it('refuses a target that is read-only or gone', function (array $state, string $message) {
    $this->target->update($state);
    $this->fake->seed($this->home, Fake::event('a', 'Uno', '2026-10-10 09:00', '2026-10-10 10:00'));

    $run = syncNow($this->job);

    expect($run->status)->toBe(SyncRunStatus::Failed)
        ->and($run->message)->toContain($message)
        ->and($this->fake->writes)->toBeEmpty();
})->with([
    'read-only' => [['can_write' => false], 'sola lettura'],
    'gone' => [['missing_since' => '2026-10-01 00:00:00'], 'non è più presente'],
]);

it('plans without writing anything in a dry-run', function () {
    $this->fake->seed($this->home, Fake::event('a', 'Cena con Luca', '2026-10-14 18:30', '2026-10-14 20:00'));
    $this->fake->seed($this->home, Fake::event('free', 'Libero', '2026-10-15 09:00', '2026-10-15 10:00', ['declined' => true]));

    $run = app(SyncService::class)->dryRun($this->job);

    expect($run->dry_run)->toBeTrue()
        ->and($run->status)->toBe(SyncRunStatus::Success)
        ->and($run->created_count)->toBe(1)
        ->and($run->skipped_count)->toBe(1)
        ->and($this->fake->writes)->toBeEmpty()
        ->and(EventMapping::count())->toBe(0)
        ->and($run->plan)->toHaveCount(2)
        ->and($run->plan[0])->toMatchArray(['op' => 'create', 'title' => 'Cena con Luca', 'source' => 'Casa'])
        ->and($run->plan[1])->toMatchArray(['op' => 'skip', 'title' => 'Libero', 'reason' => 'invito rifiutato'])
        ->and($this->job->fresh()->last_run_at)->toBeNull();
});

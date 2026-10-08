<?php

namespace App\CalSync\CalDav;

use App\CalSync\Contracts\CalendarProvider;
use App\CalSync\Contracts\WritableCalendarProvider;
use App\CalSync\Dto\CalendarDto;
use App\CalSync\Dto\ConnectionResult;
use App\CalSync\Dto\DiscoveryResult;
use App\CalSync\Dto\EventDto;
use App\CalSync\Dto\EventList;
use App\CalSync\Dto\WrittenEvent;
use App\CalSync\Exceptions\AuthenticationFailed;
use App\CalSync\Exceptions\ProviderException;
use App\CalSync\Exceptions\ServerUnreachable;
use App\CalSync\Markers;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Calendar;
use Carbon\CarbonImmutable;
use DateTimeZone;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * CalDAV backend for iCloud and generic servers (Nextcloud, Radicale, Fastmail, ...).
 *
 * Discovery: current-user-principal → calendar-home-set → Depth 1 listing of the home.
 * iCloud answers the home with a shard host (pXX-caldav.icloud.com), used as returned.
 */
final class CalDavProvider implements CalendarProvider, WritableCalendarProvider
{
    private const WRITE_PRIVILEGES = ['all', 'write', 'write-content'];

    private const ICS_CONTENT_TYPE = 'text/calendar; charset=utf-8';

    public function __construct(private readonly ICalendarMapper $mapper) {}

    public function testConnection(Account $account): ConnectionResult
    {
        try {
            $principal = $this->findPrincipal($this->client($account), $this->baseUrl($account));
        } catch (ProviderException $e) {
            return ConnectionResult::failed($e->accountStatus(), $e->getMessage());
        }

        return ConnectionResult::ok("Connessione riuscita. Principal: {$principal}");
    }

    public function discoverCalendars(Account $account): DiscoveryResult
    {
        $client = $this->client($account);

        $principal = $this->findPrincipal($client, $this->baseUrl($account));
        $home = $this->findCalendarHome($client, $principal);

        return new DiscoveryResult($this->listCalendars($client, $home), $principal, $home);
    }

    public function listEvents(Calendar $calendar, CarbonImmutable $from, CarbonImmutable $to): EventList
    {
        $multistatus = $this->client($calendar->account)
            ->report($calendar->remote_id, CalDavRequests::calendarQuery($from, $to));

        $timezone = $this->calendarTimezone($calendar);
        $list = new EventList;

        foreach ($multistatus->responses as $response) {
            $url = $multistatus->resolve($response->href);
            $ics = $response->text(Ns::CALDAV, 'calendar-data');

            if ($ics === null) {
                $list = $list->merge(new EventList(problems: ['Risorsa '.rawurldecode(basename((new Uri($url))->getPath())).' senza calendar-data.']));

                continue;
            }

            $list = $list->merge($this->mapper->parse($ics, $from, $to, $timezone, $url, $response->text(Ns::DAV, 'getetag')));
        }

        return $list->sorted();
    }

    /**
     * PUT with If-None-Match: *. If the resource exists, an earlier run created it (crash
     * before the mapping was saved): take it over only if it carries this job's marker.
     */
    public function createEvent(Calendar $calendar, EventDto $event): WrittenEvent
    {
        $client = $this->client($calendar->account);
        $url = $this->resourceUrl($calendar, $event);
        $ics = $this->mapper->toIcs($event);

        [$finalUrl, $response] = $client->request('PUT', $url, $ics, ['If-None-Match' => '*'], self::ICS_CONTENT_TYPE);

        if ($response->status() === 412) {
            [, $existing] = $client->request('GET', $url, headers: ['Accept' => 'text/calendar']);
            $jobId = $event->markers['job'] ?? null;

            if (! $existing->successful() || $jobId === null || $this->mapper->jobMarker($existing->body()) !== $jobId) {
                throw new ProviderException('Sul calendario esiste già una risorsa '.basename($url).' non creata da questo job.');
            }

            [$finalUrl, $response] = $client->request('PUT', $url, $ics, [], self::ICS_CONTENT_TYPE);
        }

        return $this->written($response, $finalUrl);
    }

    /**
     * PUT without preconditions: the source is the master, a copy edited or deleted on the
     * target is restored.
     */
    public function updateEvent(Calendar $calendar, string $remoteId, EventDto $event): WrittenEvent
    {
        [$finalUrl, $response] = $this->client($calendar->account)
            ->request('PUT', $remoteId, $this->mapper->toIcs($event), [], self::ICS_CONTENT_TYPE);

        return $this->written($response, $finalUrl);
    }

    public function deleteEvent(Calendar $calendar, string $remoteId): void
    {
        [$finalUrl, $response] = $this->client($calendar->account)->request('DELETE', $remoteId);

        if (! $response->successful() && ! in_array($response->status(), [404, 410], true)) {
            throw $this->writeFailure($response, $finalUrl);
        }
    }

    private function resourceUrl(Calendar $calendar, EventDto $event): string
    {
        $name = isset($event->markers['job'], $event->markers['key'])
            ? Markers::UID_PREFIX.Markers::copyHash($event->markers['job'], $event->markers['key'])
            : Markers::UID_PREFIX.Str::uuid();

        return rtrim($calendar->remote_id, '/').'/'.$name.'.ics';
    }

    private function written(Response $response, string $url): WrittenEvent
    {
        if (! $response->successful()) {
            throw $this->writeFailure($response, $url);
        }

        return new WrittenEvent($url, $response->header('ETag') ?: null);
    }

    private function writeFailure(Response $response, string $url): ProviderException
    {
        $resource = basename((new Uri($url))->getPath());

        return match ($response->status()) {
            401 => new AuthenticationFailed('Credenziali rifiutate dal server (HTTP 401) durante la scrittura.'),
            403 => new ProviderException("Scrittura negata (HTTP 403) su {$resource}: il calendario potrebbe essere in sola lettura."),
            default => new ProviderException("Scrittura di {$resource} non riuscita (HTTP {$response->status()})."),
        };
    }

    private function calendarTimezone(Calendar $calendar): DateTimeZone
    {
        try {
            return new DateTimeZone($calendar->timezone ?? config('calsync.timezone'));
        } catch (\Exception) {
            return new DateTimeZone(config('calsync.timezone'));
        }
    }

    private function client(Account $account): CalDavClient
    {
        if ($account->hasUnreadableCredentials()) {
            throw new AuthenticationFailed('Le credenziali salvate non si possono decifrare: la APP_KEY è cambiata da quando sono state inserite. Reinserisci la password dell\'account.');
        }

        $username = $account->credential('username');
        $password = $account->credential('password');

        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            throw new AuthenticationFailed('Username o password non impostati per questo account.');
        }

        return new CalDavClient($username, $password, $this->hostPolicy($account));
    }

    private function baseUrl(Account $account): string
    {
        if ($account->type === AccountType::IcloudCaldav) {
            return config('calsync.icloud.base_url');
        }

        if (blank($account->base_url)) {
            throw new ProviderException('URL del server CalDAV non impostato.');
        }

        return $account->base_url;
    }

    private function hostPolicy(Account $account): HostPolicy
    {
        return $account->type === AccountType::IcloudCaldav
            ? HostPolicy::icloud()
            : HostPolicy::sameHostAs($this->baseUrl($account));
    }

    /**
     * Ask the configured URL for the principal; fall back to /.well-known/caldav (RFC 6764).
     */
    private function findPrincipal(CalDavClient $client, string $baseUrl): string
    {
        $wellKnown = (string) (new Uri($baseUrl))->withPath('/.well-known/caldav')->withQuery('')->withFragment('');
        $lastError = null;

        foreach (array_unique([$baseUrl, $wellKnown]) as $url) {
            try {
                $multistatus = $client->propfind($url, CalDavRequests::CURRENT_USER_PRINCIPAL, 0);
            } catch (AuthenticationFailed|ServerUnreachable $e) {
                throw $e;
            } catch (ProviderException $e) {
                $lastError = $e;

                continue;
            }

            $href = $multistatus->self()?->href(Ns::DAV, 'current-user-principal');

            if ($href !== null) {
                return $multistatus->resolve($href);
            }
        }

        throw new ProviderException(
            'Il server non indica il principal CalDAV (current-user-principal).'.($lastError ? ' Ultimo errore: '.$lastError->getMessage() : ''),
        );
    }

    private function findCalendarHome(CalDavClient $client, string $principalUrl): string
    {
        $multistatus = $client->propfind($principalUrl, CalDavRequests::CALENDAR_HOME_SET, 0);
        $href = $multistatus->self()?->href(Ns::CALDAV, 'calendar-home-set');

        if ($href === null) {
            throw new ProviderException('Il server non indica la calendar home (calendar-home-set).');
        }

        return $multistatus->resolve($href);
    }

    /**
     * @return list<CalendarDto>
     */
    private function listCalendars(CalDavClient $client, string $homeUrl): array
    {
        $multistatus = $client->propfind($homeUrl, CalDavRequests::CALENDAR_COLLECTIONS, 1);
        $calendars = [];

        foreach ($multistatus->responses as $response) {
            $url = $multistatus->resolve($response->href);

            if (Multistatus::sameResource($url, $homeUrl) || ! $this->isEventCalendar($response)) {
                continue;
            }

            $calendars[] = new CalendarDto(
                remoteId: $url,
                name: $response->text(Ns::DAV, 'displayname') ?? $this->lastSegment($url),
                canWrite: $this->canWrite($response),
                color: $this->color($response->text(Ns::APPLE_ICAL, 'calendar-color')),
                timezone: $this->timezoneId($response->text(Ns::CALDAV, 'calendar-timezone')),
            );
        }

        return $calendars;
    }

    /**
     * A calendar collection that accepts VEVENT. Reminders lists (VTODO only), inboxes,
     * outboxes and subscriptions are skipped.
     */
    private function isEventCalendar(DavResponse $response): bool
    {
        if ($response->descendants(Ns::DAV, 'resourcetype', Ns::CALDAV, 'calendar') === []) {
            return false;
        }

        $components = array_map(
            fn ($comp) => strtoupper($comp->getAttribute('name')),
            $response->descendants(Ns::CALDAV, 'supported-calendar-component-set', Ns::CALDAV, 'comp'),
        );

        // No component set advertised means all components are supported (RFC 4791 §5.2.3).
        return $components === [] || in_array('VEVENT', $components, true);
    }

    private function canWrite(DavResponse $response): bool
    {
        // Servers that do not report privileges are assumed writable; a failed write
        // would surface as an error on the sync run.
        if (! $response->has(Ns::DAV, 'current-user-privilege-set')) {
            return true;
        }

        foreach (self::WRITE_PRIVILEGES as $privilege) {
            if ($response->descendants(Ns::DAV, 'current-user-privilege-set', Ns::DAV, $privilege) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apple colors come as #RRGGBBAA: keep #RRGGBB.
     */
    private function color(?string $value): ?string
    {
        if ($value === null || ! str_starts_with($value, '#')) {
            return null;
        }

        $hex = substr($value, 1, 6);

        return strlen($hex) === 6 && ctype_xdigit($hex) ? '#'.strtoupper($hex) : null;
    }

    /**
     * Extract the TZID from the VTIMEZONE in calendar-timezone.
     */
    private function timezoneId(?string $vcalendar): ?string
    {
        if ($vcalendar === null) {
            return null;
        }

        foreach (explode("\n", $vcalendar) as $line) {
            $line = trim($line);

            if (str_starts_with(strtoupper($line), 'TZID:')) {
                return substr($line, 5) ?: null;
            }
        }

        return null;
    }

    private function lastSegment(string $url): string
    {
        $segments = array_filter(explode('/', (new Uri($url))->getPath()));

        return rawurldecode((string) end($segments)) ?: $url;
    }
}

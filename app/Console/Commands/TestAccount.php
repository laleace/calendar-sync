<?php

namespace App\Console\Commands;

use App\CalSync\AccountService;
use App\CalSync\Exceptions\ProviderException;
use App\CalSync\Support\HttpExchangeFormatter;
use App\Models\Account;
use App\Models\Calendar;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;

#[Signature('calsync:test-account
    {id : Account id}
    {--debug : Show every HTTP request and response (credentials masked)}')]
#[Description('Run calendar discovery for an account (principal → calendar home → calendars) and store the calendars found')]
class TestAccount extends Command
{
    public function handle(AccountService $accounts): int
    {
        $account = Account::find($this->argument('id'));

        if ($account === null) {
            $this->components->error("Account #{$this->argument('id')} non trovato.");

            return self::FAILURE;
        }

        $this->components->info("Account #{$account->id} · {$account->name} ({$account->type->getLabel()})");

        if ($this->option('debug')) {
            $this->printHttpExchanges();
        }

        try {
            $summary = $accounts->discoverCalendars($account);
        } catch (ProviderException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Principal', $summary->result->principalUrl ?? '—');
        $this->components->twoColumnDetail('Calendar home', $summary->result->calendarHomeUrl ?? '—');
        $this->newLine();

        if ($summary->calendars->isEmpty()) {
            $this->components->warn('Nessun calendario di eventi trovato.');
        } else {
            $this->table(
                ['ID', 'Nome', 'Scrittura', 'Colore', 'Timezone', 'URL'],
                $summary->calendars->map(fn (Calendar $calendar) => [
                    $calendar->id,
                    $calendar->name,
                    $calendar->can_write ? 'sì' : 'no',
                    $calendar->color ?? '—',
                    $calendar->timezone ?? '—',
                    $calendar->remote_id,
                ])->all(),
            );
        }

        $this->line(sprintf(
            '  Nuovi: %d · Aggiornati: %d · Non più trovati: %d',
            $summary->created,
            $summary->updated,
            $summary->missing->count(),
        ));

        foreach ($summary->missing as $calendar) {
            $this->components->warn("Non più presente sul server: #{$calendar->id} {$calendar->name}");
        }

        return self::SUCCESS;
    }

    private function printHttpExchanges(): void
    {
        Event::listen(ResponseReceived::class, function (ResponseReceived $event) {
            $this->newLine();
            $this->line('<fg=cyan>→ '.HttpExchangeFormatter::summary($event->request, $event->response).'</>');

            foreach (HttpExchangeFormatter::requestHeaders($event->request) as $name => $value) {
                $this->line("<fg=gray>  {$name}: {$value}</>");
            }

            if ($location = $event->response->header('Location')) {
                $this->line("<fg=gray>  ← Location: {$location}</>");
            }

            if ($event->response->body() !== '') {
                $this->line('<fg=gray>'.HttpExchangeFormatter::body($event->response->body()).'</>');
            }
        });

        Event::listen(ConnectionFailed::class, function (ConnectionFailed $event) {
            $this->line('<fg=red>→ '.HttpExchangeFormatter::summary($event->request).'</>');
        });
    }
}

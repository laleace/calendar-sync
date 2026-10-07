<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Models\Account;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('calsync:add-caldav-account
    {--type= : icloud | generic}
    {--name= : Account label}
    {--url= : Server URL (generic CalDAV only)}
    {--username= : Username (iCloud: Apple ID email)}')]
#[Description('Create a CalDAV account from the command line (the password is always asked interactively)')]
class AddCalDavAccount extends Command
{
    public function handle(): int
    {
        $type = match ($this->option('type') ?: select('Tipo di account', ['icloud' => 'iCloud', 'generic' => 'CalDAV generico'])) {
            'icloud' => AccountType::IcloudCaldav,
            'generic' => AccountType::CaldavGeneric,
            default => null,
        };

        if ($type === null) {
            $this->components->error('Tipo non valido: usa "icloud" oppure "generic".');

            return self::FAILURE;
        }

        $baseUrl = config('calsync.icloud.base_url');

        if ($type === AccountType::CaldavGeneric) {
            $baseUrl = $this->option('url') ?: text('URL del server CalDAV', placeholder: 'https://cloud.example.com/remote.php/dav/', required: true);

            if (Validator::make(['url' => $baseUrl], ['url' => 'url:http,https'])->fails()) {
                $this->components->error('URL non valido: deve iniziare con https:// (o http:// per un server in LAN).');

                return self::FAILURE;
            }

            if (str_starts_with(strtolower($baseUrl), 'http://')) {
                $this->components->warn('URL in chiaro (http): le credenziali viaggeranno senza cifratura. Usalo solo in LAN.');
            }
        }

        $name = $this->option('name') ?: text(
            'Nome dell\'account',
            default: $type === AccountType::IcloudCaldav ? 'iCloud' : (new Uri($baseUrl))->getHost(),
            required: true,
        );

        $username = $this->option('username') ?: text(
            $type === AccountType::IcloudCaldav ? 'Apple ID (email)' : 'Username',
            required: true,
        );

        $password = $type === AccountType::IcloudCaldav
            ? password('Password per app', required: true, hint: 'Generala su appleid.apple.com → Accesso e sicurezza → Password per app')
            : password('Password', required: true);

        $account = Account::create([
            'name' => $name,
            'type' => $type,
            'base_url' => $baseUrl,
            'credentials' => ['username' => $username, 'password' => $password],
        ]);

        $this->components->info("Account #{$account->id} \"{$account->name}\" creato.");
        $this->line("  Verifica con: <comment>php artisan calsync:test-account {$account->id}</comment>");

        return self::SUCCESS;
    }
}

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
        $type = match ($this->option('type') ?: select(__('Account type'), ['icloud' => 'iCloud', 'generic' => __('Generic CalDAV')])) {
            'icloud' => AccountType::IcloudCaldav,
            'generic' => AccountType::CaldavGeneric,
            default => null,
        };

        if ($type === null) {
            $this->components->error(__('Invalid type: use "icloud" or "generic".'));

            return self::FAILURE;
        }

        $baseUrl = config('calsync.icloud.base_url');

        if ($type === AccountType::CaldavGeneric) {
            $baseUrl = $this->option('url') ?: text(__('CalDAV server URL'), placeholder: 'https://cloud.example.com/remote.php/dav/', required: true);

            if (Validator::make(['url' => $baseUrl], ['url' => 'url:http,https'])->fails()) {
                $this->components->error(__('Invalid URL: it must start with https:// (or http:// for a server on the LAN).'));

                return self::FAILURE;
            }

            if (str_starts_with(strtolower($baseUrl), 'http://')) {
                $this->components->warn(__('Plain URL (http): credentials will travel unencrypted. Use it on the LAN only.'));
            }
        }

        $name = $this->option('name') ?: text(
            __('Account name'),
            default: $type === AccountType::IcloudCaldav ? 'iCloud' : (new Uri($baseUrl))->getHost(),
            required: true,
        );

        $username = $this->option('username') ?: text(
            $type === AccountType::IcloudCaldav ? 'Apple ID (email)' : 'Username',
            required: true,
        );

        $password = $type === AccountType::IcloudCaldav
            ? password(__('App-specific password'), required: true, hint: __('Create it at appleid.apple.com → Sign-In and Security → App-Specific Passwords'))
            : password('Password', required: true);

        $account = Account::create([
            'name' => $name,
            'type' => $type,
            'base_url' => $baseUrl,
            'credentials' => ['username' => $username, 'password' => $password],
        ]);

        $this->components->info(__('Account #:id ":name" created.', ['id' => $account->id, 'name' => $account->name]));
        $this->line('  '.__('Check it with:')." <comment>php artisan calsync:test-account {$account->id}</comment>");

        return self::SUCCESS;
    }
}

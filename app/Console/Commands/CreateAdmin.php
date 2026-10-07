<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('calsync:create-admin {--email= : Admin email} {--name= : Display name (only when creating)}')]
#[Description('Create the single admin user, or reset its password')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $email = $this->option('email') ?: text(label: 'Email', required: true);

        if (Validator::make(['email' => $email], ['email' => 'email'])->fails()) {
            $this->components->error('Email non valida.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user === null && User::exists()) {
            $this->components->error('Esiste già un amministratore. Questa app supporta un solo utente: usa la sua email per reimpostare la password.');

            return self::FAILURE;
        }

        if ($user !== null && ! confirm("L'utente {$email} esiste già. Reimpostare la password?", default: false)) {
            $this->components->info('Nessuna modifica.');

            return self::SUCCESS;
        }

        $name = $user?->name ?? ($this->option('name') ?: text(label: 'Nome', default: 'Admin', required: true));

        $password = password(label: 'Password (min. 12 caratteri)', required: true);
        $confirmation = password(label: 'Conferma password', required: true);

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'confirmed', Password::defaults()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        if ($user === null) {
            User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $this->components->info("Amministratore {$email} creato.");
        } else {
            $user->update(['password' => $password]);
            $this->components->info("Password di {$email} aggiornata.");
        }

        return self::SUCCESS;
    }
}

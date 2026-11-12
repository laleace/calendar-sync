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
            $this->components->error(__('Invalid email.'));

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user === null && User::exists()) {
            $this->components->error(__('An administrator already exists. This app supports a single user: use their email to reset the password.'));

            return self::FAILURE;
        }

        if ($user !== null && ! confirm(__('The user :email already exists. Reset the password?', ['email' => $email]), default: false)) {
            $this->components->info(__('Nothing changed.'));

            return self::SUCCESS;
        }

        $name = $user?->name ?? ($this->option('name') ?: text(label: __('Name'), default: 'Admin', required: true));

        $password = password(label: __('Password (at least 12 characters)'), required: true);
        $confirmation = password(label: __('Confirm password'), required: true);

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
            $this->components->info(__('Administrator :email created.', ['email' => $email]));
        } else {
            $user->update(['password' => $password]);
            $this->components->info(__('Password of :email updated.', ['email' => $email]));
        }

        return self::SUCCESS;
    }
}

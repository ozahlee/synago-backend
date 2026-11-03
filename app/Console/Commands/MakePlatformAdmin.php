<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Create (or promote) the Synago platform owner who runs the churches console. */
class MakePlatformAdmin extends Command
{
    protected $signature = 'synago:platform-admin {email} {--name=Synago admin} {--password=}';

    protected $description = 'Create or update a platform administrator (not linked to any church)';

    public function handle(): int
    {
        $email = strtolower($this->argument('email'));
        $user = User::where('email', $email)->first();
        if ($user && $user->church_id) {
            $this->error("{$email} belongs to a church. Use a separate email for the platform owner.");

            return self::FAILURE;
        }
        $password = $this->option('password') ?: Str::password(16);
        $user ??= new User(['email' => $email]);
        $user->fill(['name' => $user->name ?? $this->option('name'), 'password' => $password, 'is_active' => true]);
        $user->forceFill(['is_platform_admin' => true, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        $this->info("Platform admin ready: {$email}".($this->option('password') ? '' : "  password: {$password}"));

        return self::SUCCESS;
    }
}

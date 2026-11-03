<?php

namespace Database\Seeders;

use App\Models\Church;
use App\Models\User;
use App\Services\ChurchProvisioner;
use App\Support\Tenant;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        RolesAndPermissionsSeeder::permissions();
        $provisioner = app(ChurchProvisioner::class);

        // The first church (an existing single-church install becomes church #1 in the multi-church migration).
        $church = Church::orderBy('id')->first() ?? Church::create([
            'name' => env('CHURCH_NAME', 'Saint James Wigua'), 'slug' => Church::uniqueSlug(env('CHURCH_NAME', 'Saint James Wigua')),
            'denomination' => env('CHURCH_DENOMINATION', 'anglican'), 'status' => 'active', 'onboarded_at' => now(),
        ]);
        $provisioner->setUp($church);

        Tenant::run($church->id, function () {
            $admin = User::firstOrCreate(
                ['email' => env('ADMIN_EMAIL', 'admin@synago.test')],
                ['name' => 'Administrator', 'password' => env('ADMIN_PASSWORD', 'password')],
            );
            $admin->forceFill(['email_verified_at' => $admin->email_verified_at ?? now()])->save();
            $admin->syncRoles(['admin']);

            if (! app()->isProduction()) {
                $this->call([DemoDataSeeder::class, RegistersDemoSeeder::class]);
            }
        });

        // Platform owner for the churches console (local/dev only; in production use `php artisan synago:platform-admin`).
        if (! app()->isProduction() && ! User::where('email', 'platform@synago.test')->exists()) {
            $this->command?->call('synago:platform-admin', ['email' => 'platform@synago.test', '--name' => 'Synago platform', '--password' => 'password']);
        }
    }
}

<?php

namespace App\Services;

use App\Models\Church;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\ChartOfAccounts;
use App\Support\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/** Creates a church with everything it needs on day one: roles, chart of accounts, settings and its first admin. */
class ChurchProvisioner
{
    /**
     * @param  array{name: string, denomination?: string, diocese?: ?string, address?: ?string, phone?: ?string, email?: ?string, country?: string, status?: string}  $church
     * @param  array{name: string, email: string, phone?: ?string, password: string}  $admin
     * @return array{0: Church, 1: User}
     */
    public function create(array $church, array $admin): array
    {
        return DB::transaction(function () use ($church, $admin) {
            $c = Church::create([
                ...$church,
                'slug' => Church::uniqueSlug($church['name']),
                'status' => $church['status'] ?? 'trial',
                'trial_ends_at' => ($church['status'] ?? 'trial') === 'trial' ? now()->addDays(Church::TRIAL_DAYS) : null,
            ]);

            return Tenant::run($c->id, function () use ($c, $admin) {
                $this->setUp($c);
                $user = new User(['name' => $admin['name'], 'email' => $admin['email'], 'phone' => $admin['phone'] ?? null, 'password' => $admin['password']]);
                $user->church_id = $c->id;
                $user->save();
                $user->assignRole('admin');

                return [$c, $user];
            });
        });
    }

    /** Roles, chart of accounts and starting settings for a church (safe to run again). */
    public function setUp(Church $c): void
    {
        Tenant::run($c->id, function () use ($c) {
            RolesAndPermissionsSeeder::permissions();
            RolesAndPermissionsSeeder::rolesFor($c->id);
            ChartOfAccounts::install($c->id);
            if (! Setting::where('key', 'pledgePrefix')->exists()) {
                Setting::put(['pledgePrefix' => self::initials($c->name)]);
            }
        });
    }

    /** "Saint James Wigua" → "SJW" (pledge number prefix). */
    public static function initials(string $name): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', $name), fn ($w) => $w !== '' && ! preg_match('/^(of|the|and|&)$/i', $w)));
        $initials = count($words) > 1 ? implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 4))) : mb_substr($name, 0, 3);

        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $initials)) ?: 'CH';
    }
}

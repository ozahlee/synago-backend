<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-church (SaaS). Adds `churches` and a `church_id` on every church-owned table, makes "unique"
 * rules unique within a church, gives roles and settings a church, and moves an existing single-church
 * install into church #1 so nothing is lost.
 */
return new class extends Migration
{
    /** Tables holding one church's data. */
    public const TABLES = [
        'members', 'ministries', 'ministry_activities', 'alerts', 'announcements', 'offertories', 'transactions', 'budgets',
        'campaigns', 'donations', 'baptisms', 'attendances', 'marriages', 'visitors', 'visitor_follow_ups', 'welfare_cases',
        'pledges', 'sms_batches', 'sms_messages', 'areas', 'cell_groups', 'sunday_school_classes', 'sunday_school_attendances',
        'care_cases', 'pastoral_visits', 'projects', 'project_milestones', 'outreaches', 'positions', 'events', 'observances',
        'reminders', 'reminder_rules', 'sermon_series', 'sermons', 'households', 'organisations', 'contributions',
        'accounts', 'account_mappings', 'journals',
    ];

    /** old unique index => columns that must now be unique within a church */
    public const UNIQUES = [
        'ministries' => ['ministries_name_unique' => ['name']],
        'budgets' => ['budgets_category_period_unique' => ['category', 'period']],
        'donations' => ['donations_reference_unique' => ['reference']],
        'baptisms' => ['baptisms_certificate_number_unique' => ['certificate_number']],
        'attendances' => ['attendances_date_service_unique' => ['date', 'service']],
        'marriages' => ['marriages_certificate_number_unique' => ['certificate_number']],
        'areas' => ['areas_name_level_parent_id_unique' => ['name', 'level', 'parent_id']],
        'cell_groups' => ['cell_groups_name_unique' => ['name']],
        'offertories' => ['offertories_reference_unique' => ['reference']],
        'sunday_school_classes' => ['sunday_school_classes_name_unique' => ['name']],
        'transactions' => ['transactions_reference_unique' => ['reference']],
        'reminders' => ['reminders_dedupe_key_unique' => ['dedupe_key']],
        'members' => ['members_email_unique' => ['email'], 'members_pledge_number_unique' => ['pledge_number']],
        'organisations' => ['organisations_name_unique' => ['name']],
        'sermon_series' => ['sermon_series_name_unique' => ['name']],
        'contributions' => ['contributions_reference_unique' => ['reference']],
        'accounts' => ['accounts_code_unique' => ['code']],
        'account_mappings' => ['account_mappings_source_key_unique' => ['source', 'key']],
        'journals' => ['journals_reference_unique' => ['reference']],
    ];

    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        $churchColumn = function (Blueprint $t) use ($sqlite) {
            $t->unsignedBigInteger('church_id')->nullable()->after('id')->index();
            if (! $sqlite) {
                $t->foreign('church_id')->references('id')->on('churches')->cascadeOnDelete();
            }
        };

        Schema::create('churches', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug', 80)->unique();
            $t->string('denomination', 20)->default('anglican');
            $t->string('diocese')->nullable();
            $t->string('address')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->string('country', 2)->default('UG');
            $t->string('status', 20)->default('trial')->index(); // trial | active | suspended
            $t->string('plan', 30)->nullable();
            $t->timestamp('trial_ends_at')->nullable();
            $t->timestamp('onboarded_at')->nullable();
            $t->timestamp('last_active_at')->nullable();
            $t->timestamps();
        });

        foreach (self::TABLES as $table) {
            Schema::table($table, $churchColumn);
        }
        Schema::table('users', function (Blueprint $t) use ($churchColumn) {
            $churchColumn($t);
            $t->boolean('is_platform_admin')->default(false);
        });

        // Settings: one row per church and key (was one row per key).
        Schema::create('church_settings', function (Blueprint $t) use ($churchColumn) {
            $t->id();
            $churchColumn($t);
            $t->string('key');
            $t->text('value')->nullable();
            $t->timestamps();
            $t->unique(['church_id', 'key']);
        });

        // Roles belong to a church (spatie "teams", team key = church_id). Fresh installs already have the
        // columns: the permission tables migration reads the teams setting.
        $rolesHadChurch = Schema::hasColumn('roles', 'church_id');
        if (! $rolesHadChurch) {
            Schema::table('roles', fn (Blueprint $t) => $churchColumn($t));
        }
        foreach ($rolesHadChurch ? [] : ['model_has_roles' => 'role_id', 'model_has_permissions' => 'permission_id'] as $pivot => $key) {
            Schema::rename($pivot, "{$pivot}_old");
            Schema::create($pivot, function (Blueprint $t) use ($key, $pivot) {
                $t->unsignedBigInteger($key);
                $t->string('model_type');
                $t->unsignedBigInteger('model_id');
                $t->unsignedBigInteger('church_id')->nullable();
                $t->index(['model_id', 'model_type'], "{$pivot}_model_id_model_type_idx");
                $t->index('church_id', "{$pivot}_church_idx");
                $t->foreign($key)->references('id')->on($key === 'role_id' ? 'roles' : 'permissions')->cascadeOnDelete();
                $t->primary(['church_id', $key, 'model_id', 'model_type'], "{$pivot}_primary");
            });
        }

        // ---- move an existing single-church install into church #1
        $hasData = DB::table('users')->exists() || DB::table('members')->exists() || DB::table('settings')->exists();
        $churchId = null;
        if ($hasData) {
            $s = DB::table('settings')->pluck('value', 'key')->map(fn ($v) => json_decode((string) $v, true));
            $name = $s['churchName'] ?? config('church.defaults.churchName', 'My church');
            $churchId = DB::table('churches')->insertGetId([
                'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) ?: 'church-1',
                'denomination' => $s['denomination'] ?? 'anglican', 'diocese' => $s['diocese'] ?? null, 'address' => $s['address'] ?? null,
                'status' => 'active', 'onboarded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            // staff who signed up before email confirmation existed count as confirmed
            DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
            foreach ([...self::TABLES, 'users', 'roles'] as $table) {  // roles: an upgraded install's existing roles
                DB::table($table)->update(['church_id' => $churchId]);
            }
            foreach (DB::table('settings')->get() as $row) {
                DB::table('church_settings')->insert(['church_id' => $churchId, 'key' => $row->key, 'value' => $row->value, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at]);
            }
        }
        foreach ($rolesHadChurch ? [] : ['model_has_roles' => 'role_id', 'model_has_permissions' => 'permission_id'] as $pivot => $key) {
            DB::statement("INSERT INTO {$pivot} ({$key}, model_type, model_id, church_id) SELECT {$key}, model_type, model_id, ? FROM {$pivot}_old", [$churchId]);
            Schema::drop("{$pivot}_old");
        }
        Schema::drop('settings');

        // ---- unique within a church
        foreach (self::UNIQUES as $table => $indexes) {
            Schema::table($table, function (Blueprint $t) use ($indexes) {
                foreach ($indexes as $old => $cols) {
                    $t->dropUnique($old);
                    $t->unique(['church_id', ...$cols], str_replace('_unique', '_church_unique', $old));
                }
            });
        }
        if (! $rolesHadChurch) {
            Schema::table('roles', function (Blueprint $t) {
                $t->dropUnique('roles_name_guard_name_unique');
                $t->unique(['church_id', 'name', 'guard_name'], 'roles_church_name_guard_unique');
            });
        }

        // church #1 keeps its chart of accounts (installed by the ledger migration before churches existed)
        if (! $hasData) {
            DB::table('account_mappings')->delete();
            DB::table('accounts')->delete();
        } elseif (! DB::table('accounts')->exists()) {
            \App\Services\Accounting\ChartOfAccounts::install($churchId);
        }
    }

    public function down(): void
    {
        // One-way: splitting churches back into a single install would lose data.
        throw new RuntimeException('The multi-church migration cannot be rolled back. Restore the database backup instead.');
    }
};

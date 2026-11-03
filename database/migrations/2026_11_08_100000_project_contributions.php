<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projects become the single place for giving to a cause.
 * Adds households, organisations, project milestones / cell targets and contributions, then moves every
 * fundraising campaign into a project and every donation into a contribution (+ an income transaction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // "The Kato family"
            $table->foreignId('head_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('phone', 30)->nullable();
            $table->string('village')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('household_id')->nullable()->after('cell_group_id')->constrained()->nullOnDelete();
        });

        Schema::create('organisations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type', 20)->default('company'); // company | ngo | church | government | school | other
            $table->string('contact_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('open_for_contributions')->default(false)->after('status');
            $table->decimal('target', 14, 2)->nullable()->after('budget'); // fundraising target (defaults to budget)
            $table->date('deadline')->nullable()->after('end_date');
            $table->decimal('suggested_family_amount', 14, 2)->nullable()->after('deadline');
        });

        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->decimal('amount', 14, 2)->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 12)->default('pending'); // pending | done
            $table->date('done_on')->nullable();
            $table->string('notes')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('project_cell_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cell_group_id')->constrained()->cascadeOnDelete();
            $table->decimal('target', 14, 2);
            $table->unique(['project_id', 'cell_group_id']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('cell_group_id')->constrained()->nullOnDelete();
        });
        Schema::table('pledges', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('campaign_id')->constrained()->nullOnDelete();
        });

        Schema::create('contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('kind', 20); // gift | pledge_payment | thanksgiving | in_kind
            $table->string('contributor_type', 20); // member | household | cell_group | ministry | organisation | named | anonymous
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('household_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cell_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ministry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organisation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contributor_name')->nullable();
            $table->decimal('amount', 14, 2)->default(0); // cash
            $table->string('in_kind_description')->nullable();
            $table->decimal('in_kind_value', 14, 2)->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->string('reference', 50)->unique();
            $table->string('service', 50)->nullable();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pledge_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('offertory_id')->nullable()->constrained('offertories')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('hide_name')->default(false);
            $table->string('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'date']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete(); // where it was moved to
        });

        $this->moveCampaignsIntoProjects();
    }

    private function moveCampaignsIntoProjects(): void
    {
        $now = now();
        foreach (DB::table('campaigns')->get() as $c) {
            $projectId = DB::table('projects')->where('campaign_id', $c->id)->value('id') ?? DB::table('projects')->insertGetId([
                'name' => $c->name, 'description' => $c->description, 'budget' => $c->target,
                'status' => $c->status === 'completed' ? 'completed' : 'active',
                'start_date' => substr((string) $c->created_at, 0, 10) ?: null, 'end_date' => $c->deadline,
                'campaign_id' => $c->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('projects')->where('id', $projectId)->update([
                'open_for_contributions' => $c->status !== 'completed', 'target' => $c->target, 'deadline' => $c->deadline,
            ]);
            DB::table('campaigns')->where('id', $c->id)->update(['project_id' => $projectId]);
            DB::table('pledges')->where('campaign_id', $c->id)->update(['project_id' => $projectId]);

            foreach (DB::table('donations')->where('campaign_id', $c->id)->get() as $d) {
                $name = $d->member_id ? DB::table('members')->where('id', $d->member_id)->value('name') : $d->donor_name;
                $ref = $d->reference ?: 'DN-'.$d->id;
                $txId = DB::table('transactions')->insertGetId([
                    'date' => $d->date, 'type' => 'income', 'category' => 'Project contributions', 'project_id' => $projectId,
                    'amount' => $d->amount, 'payment_method' => $d->payment_method, 'description' => "Contribution to {$c->name}",
                    'payee' => $name ?? 'Anonymous', 'reference' => DB::table('transactions')->where('reference', $ref)->exists() ? "{$ref}-P" : $ref,
                    'recorded_by' => $d->recorded_by, 'created_at' => $d->created_at ?? $now, 'updated_at' => $now,
                ]);
                DB::table('contributions')->insert([
                    'project_id' => $projectId, 'date' => $d->date, 'kind' => 'gift',
                    'contributor_type' => $d->member_id ? 'member' : ($d->donor_name ? 'named' : 'anonymous'),
                    'member_id' => $d->member_id, 'contributor_name' => $d->member_id ? null : $d->donor_name,
                    'amount' => $d->amount, 'payment_method' => $d->payment_method, 'reference' => $ref,
                    'transaction_id' => $txId, 'recorded_by' => $d->recorded_by, 'created_at' => $d->created_at ?? $now, 'updated_at' => $now,
                ]);
            }
        }

        // Pledge payments already made toward those projects become contributions too (their offertory stays as is).
        $pledges = DB::table('pledges')->whereNotNull('project_id')->pluck('project_id', 'id');
        foreach (DB::table('offertories')->whereIn('pledge_id', $pledges->keys())->get() as $o) {
            DB::table('contributions')->insert([
                'project_id' => $pledges[$o->pledge_id], 'date' => $o->date, 'kind' => 'pledge_payment',
                'contributor_type' => $o->member_id ? 'member' : ($o->contributor_name ? 'named' : 'anonymous'),
                'member_id' => $o->member_id, 'contributor_name' => $o->member_id ? null : $o->contributor_name,
                'amount' => $o->amount, 'payment_method' => $o->payment_method, 'reference' => 'CTB-'.$o->reference,
                'service' => $o->service, 'pledge_id' => $o->pledge_id, 'offertory_id' => $o->id,
                'recorded_by' => $o->recorded_by, 'created_at' => $o->created_at ?? $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Contributions copied from donations are removed with their income transactions; donations themselves were never deleted.
        $txIds = DB::table('contributions')->whereNotNull('transaction_id')->pluck('transaction_id');
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropConstrainedForeignId('project_id'));
        Schema::dropIfExists('contributions');
        DB::table('transactions')->whereIn('id', $txIds)->delete();
        Schema::table('pledges', fn (Blueprint $t) => $t->dropConstrainedForeignId('project_id'));
        Schema::table('events', fn (Blueprint $t) => $t->dropConstrainedForeignId('project_id'));
        Schema::dropIfExists('project_cell_targets');
        Schema::dropIfExists('project_milestones');
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn(['open_for_contributions', 'target', 'deadline', 'suggested_family_amount']));
        Schema::dropIfExists('organisations');
        Schema::table('members', fn (Blueprint $t) => $t->dropConstrainedForeignId('household_id'));
        Schema::dropIfExists('households');
    }
};

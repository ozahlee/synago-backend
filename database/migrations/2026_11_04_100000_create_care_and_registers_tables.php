<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance, marriages, pledges, visitors, welfare and cell groups.
 * (SMS tables live in their own migration.)
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Head count per service per day
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('service', 50);
            $table->unsignedInteger('men')->default(0);
            $table->unsignedInteger('women')->default(0);
            $table->unsignedInteger('children')->default(0);
            $table->unsignedInteger('visitors')->default(0);
            $table->string('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['date', 'service']);
        });

        // 2. Marriage register
        Schema::create('marriages', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('scheduled'); // scheduled | completed
            $table->string('groom_name');
            $table->foreignId('groom_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('bride_name');
            $table->foreignId('bride_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->date('date');
            $table->string('place')->nullable();
            $table->string('officiant')->nullable();
            $table->string('witnesses')->nullable();
            $table->unsignedTinyInteger('banns_read')->default(0); // Anglican: read on 3 Sundays
            $table->boolean('counselling_done')->default(false);
            $table->string('certificate_number', 30)->nullable()->unique();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 5. Pledges (paid through offertory records of category "pledge")
        Schema::create('pledges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pledger_name')->nullable();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose');
            $table->decimal('amount', 14, 2);
            $table->date('pledge_date');
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('offertories', function (Blueprint $table) {
            $table->foreignId('pledge_id')->nullable()->after('member_id')->constrained()->nullOnDelete();
        });

        // 6. Visitors and their follow-up
        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('village')->nullable();
            $table->date('first_visit');
            $table->string('service', 50)->nullable();
            $table->string('invited_by')->nullable();
            $table->string('how_heard', 50)->nullable();
            $table->text('prayer_request')->nullable();
            $table->string('status', 20)->default('new'); // new | contacted | returning | joined | lost
            $table->boolean('welcome_sent')->default(false);
            $table->boolean('called')->default(false);
            $table->boolean('visited')->default(false);
            $table->boolean('invited_back')->default(false);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete(); // once they join
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'first_visit']);
        });
        Schema::create('visitor_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visitor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 20); // call | visit | sms | whatsapp | in_person
            $table->text('note')->nullable();
            $table->date('date');
            $table->timestamps();
        });

        // 7. Welfare / benevolence
        Schema::create('welfare_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('beneficiary');
            $table->string('category', 30); // bereavement | medical | school_fees | food | housing | other
            $table->text('description')->nullable();
            $table->decimal('amount_requested', 14, 2)->nullable();
            $table->decimal('amount_approved', 14, 2)->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | paid | declined
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 8. Cell / home groups
        Schema::create('cell_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('leader_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('host')->nullable();
            $table->string('village')->nullable();
            $table->string('parish')->nullable();
            $table->string('meeting_day', 15)->nullable();
            $table->string('meeting_time', 10)->nullable();
            $table->string('location')->nullable();
            $table->timestamps();
        });
        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('cell_group_id')->nullable()->after('sub_county')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('members', fn (Blueprint $t) => $t->dropConstrainedForeignId('cell_group_id'));
        Schema::table('offertories', fn (Blueprint $t) => $t->dropConstrainedForeignId('pledge_id'));
        foreach (['cell_groups', 'welfare_cases', 'visitor_follow_ups', 'visitors', 'pledges', 'marriages', 'attendances'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

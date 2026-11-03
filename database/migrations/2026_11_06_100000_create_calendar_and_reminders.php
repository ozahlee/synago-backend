<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Church calendar events (with repeats), custom church-year observances, reminders and reminder rules.
 * Event and reminder datetimes are local wall-clock times in config('church.timezone').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('type', 20)->default('meeting'); // service | communion | meeting | fundraising | crusade | retreat | celebration | other
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('venue')->nullable();
            $table->foreignId('organiser_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('ministry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cell_group_id')->nullable()->constrained()->nullOnDelete();
            // none | weekly | monthly_nth (e.g. first Sunday) | monthly_day | yearly
            $table->string('repeat', 20)->default('none');
            $table->date('repeat_until')->nullable();
            // Reminder: minutes before each occurrence, who gets it, how
            $table->json('remind_minutes')->nullable();
            $table->json('remind_audience')->nullable();
            $table->string('remind_channel', 10)->default('sms'); // sms | app | both
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('starts_at');
        });

        // Custom church-year days (the Anglican seasons are computed in code)
        Schema::create('observances', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('rule', 10); // fixed (month/day) | easter (offset days from Easter Sunday)
            $table->unsignedTinyInteger('month')->nullable();
            $table->unsignedTinyInteger('day')->nullable();
            $table->smallInteger('easter_offset')->nullable();
            $table->unsignedSmallInteger('duration_days')->default(1);
            $table->string('colour', 10)->default('green'); // liturgical: white | red | purple | green | rose | black | gold
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->dateTime('remind_at');
            $table->string('channel', 10)->default('sms'); // sms | app | both
            $table->json('audience'); // {type: all|staff|leaders|ministry|cell_group|members|users|numbers, ...}
            $table->string('audience_label');
            $table->text('message');
            $table->string('status', 12)->default('pending'); // pending | sent | cancelled | skipped
            $table->string('source', 30)->nullable(); // event | rule:birthdays | personal …
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('dedupe_key')->nullable()->unique(); // stops the same automatic reminder twice
            $table->unsignedInteger('sent_count')->default(0);
            $table->string('note')->nullable(); // why skipped / postponed
            $table->foreignId('sms_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'remind_at']);
        });

        Schema::create('reminder_rules', function (Blueprint $table) {
            $table->string('key')->primary(); // service_eve | birthdays | group_meetings | pledge_due | follow_ups | events_digest
            $table->boolean('enabled')->default(false);
            $table->json('options')->nullable(); // time, audience, message…
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('calendar_token', 64)->nullable()->unique()->after('member_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('calendar_token'));
        Schema::dropIfExists('reminder_rules');
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('observances');
        Schema::dropIfExists('events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Service register: who preached (or will preach) at which service, on what, from which passages. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sermon_series', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('sermons', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('service', 50)->nullable();
            $table->string('status', 12)->default('planned'); // planned | preached
            $table->foreignId('preacher_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('preacher_name')->nullable(); // visiting preacher
            $table->string('preacher_church')->nullable();
            $table->string('title')->nullable();
            $table->string('theme')->nullable();
            $table->foreignId('sermon_series_id')->nullable()->constrained('sermon_series')->nullOnDelete();
            $table->json('scriptures')->nullable(); // ["John 15:1–8", …] — the passages the preacher used
            $table->json('books')->nullable();      // book indexes 0..65, for coverage / search
            $table->text('notes')->nullable();
            $table->string('recording_url')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['date', 'service']);
            $table->index(['status', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sermons');
        Schema::dropIfExists('sermon_series');
    }
};

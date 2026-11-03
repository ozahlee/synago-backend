<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            // burial | wedding | introduction | thanksgiving | fundraising | meeting | general
            $table->string('type', 20)->default('general');
            $table->string('title');
            $table->string('subject')->nullable(); // "The late Mzee Yosefu Kato", "Paul & Ruth"
            $table->text('details')->nullable();
            $table->dateTime('event_at')->nullable();
            $table->string('venue')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('pinned')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'event_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};

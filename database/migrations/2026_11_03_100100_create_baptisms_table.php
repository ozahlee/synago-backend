<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baptisms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20)->default('baptism'); // baptism | confirmation | dedication
            $table->string('status', 20)->default('scheduled'); // scheduled | completed
            $table->string('name');
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('date');
            $table->string('mode', 20)->nullable(); // sprinkling | pouring | immersion
            $table->string('officiant')->nullable();
            $table->string('place')->nullable();
            $table->string('father_name')->nullable();
            $table->string('mother_name')->nullable();
            $table->string('sponsors')->nullable(); // godparents / witnesses
            $table->boolean('class_completed')->default(false);
            $table->string('certificate_number', 30)->nullable()->unique();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kind', 'status', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baptisms');
    }
};

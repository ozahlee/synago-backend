<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offertories', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->enum('category', ['tithe', 'pledge', 'thanksgiving', 'harvest'])->index();
            $table->decimal('amount', 14, 2);
            $table->string('payment_method');
            $table->string('reference')->unique();
            // Contributor is either a registered member or a free-text name (visitor/anonymous).
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contributor_name')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->enum('type', ['income', 'expense'])->index();
            $table->string('category')->index();
            $table->decimal('amount', 14, 2);
            $table->string('description');
            $table->string('reference')->unique();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->decimal('allocated', 14, 2);
            $table->char('period', 7); // YYYY-MM
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['category', 'period']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('target', 14, 2);
            $table->date('deadline')->nullable();
            $table->enum('status', ['active', 'completed'])->default('active');
            $table->timestamps();
        });

        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('donor_name')->nullable();
            $table->decimal('amount', 14, 2);
            $table->date('date');
            $table->string('payment_method');
            $table->string('reference')->unique();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donations');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('offertories');
    }
};

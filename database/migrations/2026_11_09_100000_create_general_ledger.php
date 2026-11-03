<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Double-entry general ledger. Offertory and Accounts transactions keep being recorded as before;
 * they post to the ledger through account mappings (category → income/expense account,
 * payment method → cash/bank/mobile money). Manual journals cover everything else
 * (opening balances, banking, assets, loans, depreciation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->string('type', 20)->index();      // asset | liability | fund | income | expense
            $table->string('subtype', 30)->nullable(); // cash | mobile_money | bank | receivable | fixed_asset | contra_asset | payable | loan
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('account_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);   // payment_method | offertory | income | expense
            $table->string('key', 100);     // e.g. "Mobile Money", "tithe", "Utilities"
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['source', 'key']);
        });

        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->string('reference')->unique();
            $table->string('memo');
            $table->string('kind', 20)->default('general'); // general | opening | transfer | depreciation | adjustment
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            // fund the line belongs to: general | welfare | project (with project_id)
            $table->string('fund', 20)->default('general');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('memo')->nullable();
            $table->index(['account_id', 'fund']);
        });

        // Each church gets its own chart of accounts when it is created (see ChurchProvisioner).
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journals');
        Schema::dropIfExists('account_mappings');
        Schema::dropIfExists('accounts');
    }
};

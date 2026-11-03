<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('village')->nullable()->after('address');
            $table->string('parish')->nullable()->after('village');
            $table->string('sub_county')->nullable()->after('parish');
        });

        Schema::table('ministries', function (Blueprint $table) {
            $table->string('location')->nullable()->after('meeting_time');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('amount');
            $table->string('payee')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('members', fn (Blueprint $t) => $t->dropColumn(['village', 'parish', 'sub_county']));
        Schema::table('ministries', fn (Blueprint $t) => $t->dropColumn('location'));
        Schema::table('transactions', fn (Blueprint $t) => $t->dropColumn(['payment_method', 'payee']));
    }
};

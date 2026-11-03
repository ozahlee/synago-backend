<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('pledge_number', 30)->nullable()->unique()->after('id');
        });

        Schema::table('offertories', function (Blueprint $table) {
            $table->string('service', 50)->nullable()->after('date');
            $table->index(['date', 'service']);
        });

        // Give existing members numbers in joining order: SJW-0001, SJW-0002, …
        $prefix = DB::table('settings')->where('key', 'pledgePrefix')->value('value');
        $prefix = $prefix ? json_decode($prefix) : config('church.defaults.pledgePrefix');
        $n = 0;
        DB::table('members')->orderBy('date_joined')->orderBy('id')->pluck('id')->each(function ($id) use (&$n, $prefix) {
            DB::table('members')->where('id', $id)->update(['pledge_number' => sprintf('%s-%04d', $prefix, ++$n)]);
        });
    }

    public function down(): void
    {
        Schema::table('offertories', function (Blueprint $table) {
            $table->dropIndex(['date', 'service']);
            $table->dropColumn('service');
        });
        Schema::table('members', function (Blueprint $table) {
            $table->dropUnique(['pledge_number']);
            $table->dropColumn('pledge_number');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Areas hierarchy, Sunday school, sacrament requirements, pastoral care, outreach, projects, positions.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Church geography: archdeaconry > parish > (region) > village; cell groups sit in a village or region.
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('level', 20); // archdeaconry | parish | region | village
            $table->foreignId('parent_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->timestamps();
            $table->unique(['name', 'level', 'parent_id']);
        });
        Schema::table('cell_groups', function (Blueprint $table) {
            $table->foreignId('area_id')->nullable()->after('parish')->constrained()->nullOnDelete();
            $table->decimal('harvest_target', 14, 2)->nullable()->after('location');
        });
        // A harvest (or any) gift can come from a cell group as a whole.
        Schema::table('offertories', function (Blueprint $table) {
            $table->foreignId('cell_group_id')->nullable()->after('pledge_id')->constrained()->nullOnDelete();
        });

        // Sunday school
        Schema::create('sunday_school_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedTinyInteger('min_age')->default(0);
            $table->unsignedTinyInteger('max_age')->default(12);
            $table->string('room')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('sunday_school_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sunday_school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->timestamps();
            $table->unique(['sunday_school_class_id', 'member_id', 'date'], 'ss_att_unique');
        });

        // Members: status gains "deceased"; Sunday school class, next of kin, date of death
        Schema::table('members', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->change();
        });
        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('sunday_school_class_id')->nullable()->after('sunday_school')->constrained()->nullOnDelete();
            $table->string('next_of_kin_name')->nullable()->after('cell_group_id');
            $table->string('next_of_kin_relationship', 50)->nullable()->after('next_of_kin_name');
            $table->string('next_of_kin_phone', 30)->nullable()->after('next_of_kin_relationship');
            $table->foreignId('next_of_kin_member_id')->nullable()->after('next_of_kin_phone')->constrained('members')->nullOnDelete();
            $table->date('date_of_death')->nullable()->after('date_joined');
        });

        // Sacrament requirements checklist per candidate: {key: {done, amount, note, by, at}}
        Schema::table('baptisms', function (Blueprint $table) {
            $table->json('requirements')->nullable()->after('class_completed');
            $table->string('override_reason')->nullable()->after('requirements');
        });

        // Pastoral care: bereavements (member died / member lost a relative), sickness, newborns…
        Schema::create('care_cases', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30); // member_death | bereavement | sickness | hospital | newborn | other
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject')->nullable();       // deceased relative / patient / baby name
            $table->string('relationship', 50)->nullable();
            $table->date('date');
            $table->date('burial_date')->nullable();
            $table->string('burial_place')->nullable();
            $table->string('status', 20)->default('open'); // open | closed
            $table->foreignId('welfare_case_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('announcement_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('pastoral_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('care_case_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visitee')->nullable();
            $table->string('type', 20); // home | hospital | sick | bereavement | newborn | prison | other
            $table->date('date');
            $table->string('place')->nullable();
            $table->string('team')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('gift_amount', 14, 2)->nullable();
            $table->date('follow_up_on')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Projects (building, borehole…) — transactions can be tagged to a project
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('budget', 14, 2)->default(0);
            $table->string('status', 20)->default('active'); // planning | active | on_hold | completed
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('category')->constrained()->nullOnDelete();
        });

        // Outreach (babies home, hospital, prison…)
        Schema::create('outreaches', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('type', 30); // babies_home | hospital | prison | school | community | other
            $table->string('place')->nullable();
            $table->date('date');
            $table->unsignedInteger('people_reached')->nullable();
            $table->text('items')->nullable();
            $table->string('team')->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Offices held by members, optionally scoped to a group; a position-holder can be given a login.
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('scope_type', 30)->default('church'); // church | ministry | cell_group | sunday_school_class | project
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
            $table->index(['scope_type', 'scope_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('member_id')->nullable()->after('phone')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('member_id'));
        Schema::dropIfExists('positions');
        Schema::dropIfExists('outreaches');
        Schema::table('transactions', fn (Blueprint $t) => $t->dropConstrainedForeignId('project_id'));
        Schema::dropIfExists('projects');
        Schema::dropIfExists('pastoral_visits');
        Schema::dropIfExists('care_cases');
        Schema::table('baptisms', fn (Blueprint $t) => $t->dropColumn(['requirements', 'override_reason']));
        Schema::table('members', function (Blueprint $t) {
            $t->dropConstrainedForeignId('next_of_kin_member_id');
            $t->dropConstrainedForeignId('sunday_school_class_id');
            $t->dropColumn(['next_of_kin_name', 'next_of_kin_relationship', 'next_of_kin_phone', 'date_of_death']);
        });
        Schema::dropIfExists('sunday_school_attendances');
        Schema::dropIfExists('sunday_school_classes');
        Schema::table('offertories', fn (Blueprint $t) => $t->dropConstrainedForeignId('cell_group_id'));
        Schema::table('cell_groups', function (Blueprint $t) {
            $t->dropConstrainedForeignId('area_id');
            $t->dropColumn('harvest_target');
        });
        Schema::dropIfExists('areas');
    }
};

<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\CellGroup;
use App\Models\Member;
use App\Models\Ministry;
use App\Models\Offertory;
use App\Models\Position;
use App\Models\SundaySchoolClass;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

class ChurchLifeTest extends ApiTestCase
{
    public function test_children_twelve_and_under_join_sunday_school_in_the_right_class(): void
    {
        $this->actingAsRole('secretary');
        $tots = SundaySchoolClass::create(['name' => 'Beginners', 'min_age' => 0, 'max_age' => 5]);
        $juniors = SundaySchoolClass::create(['name' => 'Juniors', 'min_age' => 6, 'max_age' => 12]);

        $kid = $this->postJson('/api/v1/members', ['name' => 'Kid', 'gender' => 'male', 'dateJoined' => '2026-01-01', 'dateOfBirth' => now()->subYears(8)->toDateString()])
            ->assertCreated()->assertJsonPath('data.sundaySchool', true)->assertJsonPath('data.sundaySchoolClassId', (string) $juniors->id)->json('data.id');
        $this->postJson('/api/v1/members', ['name' => 'Adult', 'gender' => 'female', 'dateJoined' => '2026-01-01', 'dateOfBirth' => '1990-01-01'])
            ->assertCreated()->assertJsonPath('data.sundaySchool', false);

        // A 13-year-old graduates; a 4-year-old placed in Beginners
        Member::find($kid)->updateQuietly(['date_of_birth' => now()->subYears(13)->subDay()]);
        $baby = Member::factory()->create(['date_of_birth' => now()->subYears(4)]);
        $this->assertSame($tots->id, $baby->sunday_school_class_id);

        $this->postJson('/api/v1/sunday-school/auto-assign')->assertOk()->assertJsonPath('data.graduated', 1);
        $this->assertFalse(Member::find($kid)->sunday_school);
    }

    public function test_teacher_login_sees_and_marks_only_their_class(): void
    {
        $this->actingAsRole('admin');
        $mine = SundaySchoolClass::create(['name' => 'Juniors', 'min_age' => 6, 'max_age' => 12]);
        $other = SundaySchoolClass::create(['name' => 'Teens', 'min_age' => 0, 'max_age' => 5]);
        $teacher = Member::factory()->create(['name' => 'Teacher Ruth', 'date_of_birth' => '1990-01-01']);
        $pupil = Member::factory()->create(['date_of_birth' => now()->subYears(9), 'status' => 'active']);
        $pos = Position::create(['member_id' => $teacher->id, 'title' => 'Sunday school teacher', 'scope_type' => 'sunday_school_class', 'scope_id' => $mine->id]);

        $this->postJson("/api/v1/positions/{$pos->id}/login", ['email' => 'ruth@church.test', 'password' => 'password123'])
            ->assertCreated()->assertJsonPath('data.created', true);

        Sanctum::actingAs(User::where('email', 'ruth@church.test')->first());
        $this->getJson('/api/v1/me/groups')->assertOk()
            ->assertJsonPath('data.groups.0.name', 'Juniors')->assertJsonPath('data.groups.0.people.0.id', (string) $pupil->id);
        $this->putJson("/api/v1/sunday-school/classes/{$mine->id}/attendance", ['date' => today()->toDateString(), 'present' => [$pupil->id]])
            ->assertOk()->assertJsonPath('data.present.0', (string) $pupil->id);
        $this->getJson("/api/v1/sunday-school/classes/{$other->id}")->assertForbidden();
        $this->getJson('/api/v1/members')->assertForbidden();
        $this->getJson('/api/v1/offertory')->assertForbidden();
    }

    public function test_record_death_marks_deceased_and_sets_up_care(): void
    {
        $this->actingAsRole('pastor');
        $son = Member::factory()->create(['name' => 'Paul Kato', 'phone' => '0772000111']);
        $m = Member::factory()->create(['name' => 'Yosefu Kato', 'next_of_kin_member_id' => $son->id, 'next_of_kin_relationship' => 'Son', 'status' => 'active']);

        $this->postJson("/api/v1/members/{$m->id}/death", [
            'dateOfDeath' => today()->toDateString(), 'burialDate' => now()->addDays(3)->toDateString(), 'burialPlace' => 'Kireka',
            'announce' => true, 'openWelfare' => true, 'smsNextOfKin' => true,
        ])->assertCreated()->assertJsonPath('data.type', 'member_death')->assertJsonPath('data.smsSent', true);

        $this->assertSame('deceased', $m->fresh()->status);
        $this->assertDatabaseHas('announcements', ['type' => 'burial', 'member_id' => $m->id]);
        $this->assertDatabaseHas('welfare_cases', ['category' => 'bereavement', 'member_id' => $son->id]);
        $this->assertDatabaseHas('sms_messages', ['phone' => '+256772000111', 'status' => 'sent']);
        $this->assertSame(0, Member::active()->whereKey($m->id)->count());
        $this->postJson("/api/v1/members/{$m->id}/death", ['dateOfDeath' => today()->toDateString()])->assertStatus(422);

        $this->postJson('/api/v1/care/visits', ['memberId' => $son->id, 'type' => 'bereavement', 'date' => today()->toDateString(), 'followUpOn' => now()->addDays(5)->toDateString()])
            ->assertCreated();
        $this->getJson('/api/v1/care/stats')->assertOk()->assertJsonPath('data.followUpsDue', 1)->assertJsonPath('data.deathsThisYear', 1);
    }

    public function test_harvest_rolls_up_cell_village_parish(): void
    {
        $this->actingAsRole('treasurer');
        $parish = Area::create(['name' => 'Kireka Parish', 'level' => 'parish']);
        $village = Area::create(['name' => 'Kireka', 'level' => 'village', 'parent_id' => $parish->id]);
        $cell = CellGroup::create(['name' => 'Kireka Cell', 'area_id' => $village->id, 'harvest_target' => 500]);
        $m = Member::factory()->create(['cell_group_id' => $cell->id]);
        Offertory::factory()->create(['category' => 'harvest', 'member_id' => $m->id, 'amount' => 100, 'date' => now()->toDateString()]);
        Offertory::factory()->create(['category' => 'harvest', 'member_id' => null, 'cell_group_id' => $cell->id, 'amount' => 250, 'date' => now()->toDateString()]);
        Offertory::factory()->create(['category' => 'harvest', 'member_id' => null, 'amount' => 40, 'date' => now()->toDateString()]);

        $this->getJson('/api/v1/reports/harvest')->assertOk()
            ->assertJsonPath('data.total', 390)
            ->assertJsonPath('data.cells.0.total', 350)
            ->assertJsonPath('data.cells.0.target', 500)
            ->assertJsonPath('data.areas.parish.0.total', 350)
            ->assertJsonPath('data.areas.village.0.name', 'Kireka')
            ->assertJsonPath('data.unassigned.total', 40);
    }

    public function test_area_parent_must_be_higher_level(): void
    {
        $this->actingAsRole('secretary');
        $village = Area::create(['name' => 'Kireka', 'level' => 'village']);
        $this->postJson('/api/v1/areas', ['name' => 'X', 'level' => 'parish', 'parentId' => $village->id])->assertStatus(422)->assertJsonValidationErrors('parentId');
    }

    public function test_project_spending_and_outreach_expense(): void
    {
        $this->actingAsRole('treasurer');
        $id = $this->postJson('/api/v1/projects', ['name' => 'Borehole', 'budget' => 1000, 'openForContributions' => true])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/projects/{$id}/contributions", ['kind' => 'gift', 'contributorType' => 'anonymous', 'amount' => 300, 'date' => today()->toDateString(), 'paymentMethod' => 'Cash'])->assertCreated();

        $this->postJson("/api/v1/projects/{$id}/transactions", ['type' => 'expense', 'category' => 'Maintenance', 'amount' => 400, 'date' => today()->toDateString(), 'description' => 'Drilling'])
            ->assertCreated()->assertJsonPath('data.spent', 400)->assertJsonPath('data.raised', 300)->assertJsonPath('data.remaining', 600);

        $this->actingAsRole('secretary');
        $o = $this->postJson('/api/v1/outreach', ['title' => 'Sanyu Babies Home', 'type' => 'babies_home', 'date' => today()->toDateString(), 'cost' => 150, 'projectId' => $id, 'recordExpense' => true])
            ->assertCreated()->json('data');
        $this->patchJson("/api/v1/outreach/{$o['id']}", ['cost' => 175, 'recordExpense' => true])->assertOk();
        $this->assertDatabaseCount('transactions', 3); // gift income + drilling + outreach
        $this->assertDatabaseHas('transactions', ['category' => 'Outreach', 'amount' => 175, 'project_id' => $id]);
    }

    public function test_role_editor(): void
    {
        $this->actingAsRole('admin');
        $this->postJson('/api/v1/roles', ['name' => 'sunday school coordinator', 'permissions' => ['members.view', 'members.manage']])->assertCreated();
        $this->putJson('/api/v1/roles/sunday%20school%20coordinator', ['permissions' => ['members.view']])->assertOk()->assertJsonPath('data.permissions', ['members.view']);
        $this->putJson('/api/v1/roles/admin', ['permissions' => []])->assertStatus(422);
        $this->deleteJson('/api/v1/roles/pastor')->assertStatus(422);
        $this->deleteJson('/api/v1/roles/sunday%20school%20coordinator')->assertNoContent();

        $this->actingAsRole('pastor');
        $this->postJson('/api/v1/roles', ['name' => 'x', 'permissions' => []])->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Baptism;
use App\Models\Member;

class BaptismTest extends ApiTestCase
{
    public function test_secretary_registers_candidate_then_completes_with_certificate_number(): void
    {
        $this->actingAsRole('secretary');

        $id = $this->postJson('/api/v1/baptisms', [
            'kind' => 'baptism', 'name' => 'Baby Nakato', 'date' => '2026-12-06', 'mode' => 'sprinkling',
            'fatherName' => 'Paul Mukasa', 'motherName' => 'Ruth Nalwoga', 'sponsors' => 'Jane Akello',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.certificateNumber', null)
            ->json('data.id');

        // Requirements block completion…
        $this->patchJson("/api/v1/baptisms/{$id}", ['status' => 'completed'])
            ->assertStatus(422)->assertJsonValidationErrors('requirements');

        // …until every item on the checklist is ticked
        $keys = collect($this->getJson("/api/v1/baptisms/{$id}")->json('data.requirements'))->pluck('key');
        foreach ($keys as $key) {
            $this->putJson("/api/v1/baptisms/{$id}/requirements/{$key}", ['done' => true, 'amount' => $key === 'fee' ? 10000 : null])->assertOk();
        }
        $this->assertDatabaseHas('transactions', ['category' => 'Sacrament fees', 'amount' => 10000, 'type' => 'income']);

        $this->patchJson("/api/v1/baptisms/{$id}", ['status' => 'completed', 'officiant' => 'Rev. Canon Okello'])
            ->assertOk()
            ->assertJsonPath('data.certificateNumber', 'BAP-2026-0001')
            ->assertJsonPath('data.requirementsDone', $keys->count());

        $second = Baptism::factory()->create(['date' => '2026-03-01', 'kind' => 'baptism', 'status' => 'completed']);
        $this->assertSame('BAP-2026-0002', $second->certificate_number);

        $confirmation = Baptism::factory()->create(['date' => '2026-03-01', 'kind' => 'confirmation']);
        $this->assertSame('CON-2026-0001', $confirmation->certificate_number);
    }

    public function test_pastor_can_override_requirements_with_reason_but_secretary_cannot(): void
    {
        $this->actingAsRole('secretary');
        $b = Baptism::factory()->scheduled()->create(['kind' => 'confirmation']);
        $this->patchJson("/api/v1/baptisms/{$b->id}", ['status' => 'completed', 'overrideReason' => 'Bishop visit'])->assertStatus(422);

        $this->actingAsRole('pastor');
        $this->patchJson("/api/v1/baptisms/{$b->id}", ['status' => 'completed', 'overrideReason' => 'Bishop visit; books to follow'])
            ->assertOk()->assertJsonPath('data.overrideReason', 'Bishop visit; books to follow')
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_requirements_are_per_rite(): void
    {
        $this->actingAsRole('secretary');
        $b = Baptism::factory()->scheduled()->create(['kind' => 'confirmation']);
        $keys = collect($this->getJson("/api/v1/baptisms/{$b->id}")->json('data.requirements'))->pluck('key');
        $this->assertContains('prayer_book', $keys);
        $this->assertNotContains('godparents', $keys);
        $this->putJson("/api/v1/baptisms/{$b->id}/requirements/godparents", ['done' => true])->assertStatus(422);
    }

    public function test_linking_a_member_fills_name_gender_and_birth_date(): void
    {
        $this->actingAsRole('pastor');
        $member = Member::factory()->create(['name' => 'Peter Ssali', 'gender' => 'male', 'date_of_birth' => '1999-04-02']);

        $this->postJson('/api/v1/baptisms', ['kind' => 'confirmation', 'memberId' => $member->id, 'date' => '2026-11-29'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Peter Ssali')
            ->assertJsonPath('data.gender', 'male')
            ->assertJsonPath('data.dateOfBirth', '1999-04-02')
            ->assertJsonPath('data.memberId', (string) $member->id);
    }

    public function test_name_is_required_without_member(): void
    {
        $this->actingAsRole('secretary');

        $this->postJson('/api/v1/baptisms', ['kind' => 'baptism', 'date' => '2026-11-29'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_filters_and_stats(): void
    {
        $this->actingAsRole('treasurer'); // members.view only
        Baptism::factory()->count(2)->create(['date' => now()->startOfYear()->addDays(3)]);
        Baptism::factory()->create(['kind' => 'dedication', 'date' => now()->startOfYear()->addDays(3)]);
        Baptism::factory()->scheduled()->count(3)->create(['class_completed' => false]);

        $this->getJson('/api/v1/baptisms?status=scheduled')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/baptisms?kind=dedication')->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/baptisms/stats')
            ->assertOk()
            ->assertJsonPath('data.completedThisYear.baptism', 2)
            ->assertJsonPath('data.completedThisYear.dedication', 1)
            ->assertJsonPath('data.candidates', 3)
            ->assertJsonPath('data.awaitingClass', 3);
    }

    public function test_treasurer_cannot_write_register(): void
    {
        $this->actingAsRole('treasurer');

        $this->postJson('/api/v1/baptisms', ['kind' => 'baptism', 'name' => 'X', 'date' => '2026-11-29'])->assertForbidden();
    }

    public function test_settings_defaults_and_admin_only_update(): void
    {
        $this->actingAsRole('secretary');
        $this->getJson('/api/v1/settings')->assertOk()->assertJsonPath('data.denomination', 'anglican');
        $this->putJson('/api/v1/settings', ['denomination' => 'pentecostal'])->assertForbidden();

        $this->actingAsRole('admin');
        $this->putJson('/api/v1/settings', ['denomination' => 'pentecostal', 'services' => ['Main Service', 'Evening Service']])
            ->assertOk()
            ->assertJsonPath('data.denomination', 'pentecostal')
            ->assertJsonPath('data.services.1', 'Evening Service')
            ->assertJsonPath('data.churchName', $this->church->name);

        $this->putJson('/api/v1/settings', ['denomination' => 'baptist'])->assertStatus(422);
    }
}

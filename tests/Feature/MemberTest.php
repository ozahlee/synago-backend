<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Ministry;

class MemberTest extends ApiTestCase
{
    public function test_create_member_with_ministries(): void
    {
        $this->actingAsRole('secretary');
        [$choir, $youth] = Ministry::factory()->count(2)->create();

        $this->postJson('/api/v1/members', [
            'name' => 'Ama Serwaa',
            'gender' => 'female',
            'dateJoined' => '2026-01-04',
            'sundaySchool' => true,
            'ministryIds' => [$choir->id, $youth->id],
            'primaryMinistryId' => $youth->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ama Serwaa')
            ->assertJsonPath('data.sundaySchool', true)
            ->assertJsonPath('data.ministry', $youth->name)
            ->assertJsonCount(2, 'data.ministries');
    }

    public function test_list_filters_and_search(): void
    {
        $this->actingAsRole('secretary');
        Member::factory()->create(['name' => 'Kofi Annan', 'status' => 'active']);
        Member::factory()->inactive()->create(['name' => 'Yaa Asantewaa']);

        $this->getJson('/api/v1/members?search=kofi')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Kofi Annan');
        $this->getJson('/api/v1/members?status=inactive')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Yaa Asantewaa');
    }

    public function test_update_and_soft_delete(): void
    {
        $this->actingAsRole('secretary');
        $member = Member::factory()->create();

        $this->patchJson("/api/v1/members/{$member->id}", ['status' => 'inactive'])
            ->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->deleteJson("/api/v1/members/{$member->id}")->assertNoContent();
        $this->assertSoftDeleted($member);
        $this->getJson("/api/v1/members/{$member->id}")->assertNotFound();
    }
}

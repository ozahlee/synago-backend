<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Baptism;
use App\Models\Member;
use App\Models\Pledge;
use App\Models\Visitor;
use App\Models\WelfareCase;

class SearchTest extends ApiTestCase
{
    private function seedAcrossModules(): Member
    {
        $m = Member::factory()->create(['name' => 'Ruth Nalwoga']);
        Visitor::create(['name' => 'Ruth Akello', 'first_visit' => '2026-11-01']);
        Baptism::factory()->create(['name' => 'Baby Ruth Kato']);
        Pledge::create(['member_id' => $m->id, 'purpose' => 'Building Fund', 'amount' => 500, 'pledge_date' => '2026-01-01']);
        WelfareCase::create(['beneficiary' => 'Ruth Nalwoga', 'category' => 'medical']);
        Announcement::create(['type' => 'wedding', 'title' => 'Holy Matrimony', 'subject' => 'Paul & Ruth']);

        return $m;
    }

    public function test_admin_sees_every_module(): void
    {
        $this->actingAsRole('admin');
        $m = $this->seedAcrossModules();

        $res = $this->getJson('/api/v1/search?q=ruth')->assertOk();
        foreach (['members', 'visitors', 'baptisms', 'pledges', 'welfare', 'announcements'] as $group) {
            $this->assertNotEmpty($res->json("data.{$group}"), "missing {$group}");
        }
        $this->assertSame((string) $m->id, $res->json('data.members.0.id'));

        // pledge number lookup
        $this->getJson('/api/v1/search?q='.$m->pledge_number)->assertOk()->assertJsonPath('data.members.0.title', 'Ruth Nalwoga');
    }

    public function test_results_respect_role_permissions(): void
    {
        $this->seedAcrossModules();

        $this->actingAsRole('secretary'); // no finance, no welfare
        $data = $this->getJson('/api/v1/search?q=ruth')->assertOk()->json('data');
        $this->assertArrayHasKey('members', $data);
        $this->assertArrayNotHasKey('pledges', $data);
        $this->assertArrayNotHasKey('welfare', $data);
        $this->assertArrayNotHasKey('staff', $data);

        $this->actingAsRole('treasurer'); // finance + welfare, but still members.view
        $data = $this->getJson('/api/v1/search?q=ruth')->assertOk()->json('data');
        $this->assertArrayHasKey('pledges', $data);
        $this->assertArrayHasKey('welfare', $data);
    }

    public function test_short_queries_are_rejected_and_wildcards_are_literal(): void
    {
        $this->actingAsRole('admin');
        Member::factory()->create(['name' => 'Anyone']);

        $this->getJson('/api/v1/search?q=a')->assertStatus(422);
        $this->getJson('/api/v1/search?q=%25%25')->assertOk()->assertJsonMissingPath('data.members');
    }
}

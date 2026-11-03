<?php

namespace Tests\Feature;

use App\Models\CellGroup;
use App\Models\Contribution;
use App\Models\Household;
use App\Models\Member;
use App\Models\Ministry;
use App\Models\Offertory;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\Transaction;

class ProjectContributionTest extends ApiTestCase
{
    private function project(array $extra = []): Project
    {
        return Project::create(['name' => 'Land acquisition', 'budget' => 10000, 'target' => 8000, 'open_for_contributions' => true, ...$extra]);
    }

    public function test_every_kind_of_contributor_and_gift_lands_once_in_the_books(): void
    {
        $this->actingAsRole('treasurer');
        $p = $this->project();
        $cell = CellGroup::create(['name' => 'Kireka Cell']);
        $member = Member::factory()->create(['name' => 'Ruth Nalwoga', 'cell_group_id' => $cell->id]);
        $head = Member::factory()->create(['name' => 'Paul Kato', 'cell_group_id' => $cell->id]);
        $family = Household::create(['name' => 'The Kato family', 'head_member_id' => $head->id]);
        $org = Organisation::create(['name' => 'Kireka Hardware Ltd', 'phone' => '0772999000']);
        $mu = Ministry::factory()->create(['name' => "Mothers' Union"]);
        $url = "/api/v1/projects/{$p->id}/contributions";
        $d = today()->toDateString();

        $this->postJson($url, ['kind' => 'gift', 'contributorType' => 'member', 'memberId' => $member->id, 'amount' => 1000, 'date' => $d, 'paymentMethod' => 'Mobile Money'])->assertCreated();
        $this->postJson($url, ['kind' => 'thanksgiving', 'contributorType' => 'household', 'householdId' => $family->id, 'amount' => 500, 'date' => $d, 'service' => 'Second Service'])
            ->assertCreated()->assertJsonPath('data.contributor', 'The Kato family');
        $this->postJson($url, ['kind' => 'gift', 'contributorType' => 'cell_group', 'cellGroupId' => $cell->id, 'amount' => 300, 'date' => $d])->assertCreated();
        $this->postJson($url, ['kind' => 'gift', 'contributorType' => 'ministry', 'ministryId' => $mu->id, 'amount' => 200, 'date' => $d])->assertCreated();
        $this->postJson($url, ['kind' => 'in_kind', 'contributorType' => 'organisation', 'organisationId' => $org->id, 'inKindDescription' => '50 bags of cement', 'inKindValue' => 1750, 'date' => $d])
            ->assertCreated()->assertJsonPath('data.amount', 0)->assertJsonPath('data.inKindValue', 1750);
        $this->postJson($url, ['kind' => 'gift', 'contributorType' => 'named', 'contributorName' => 'Well-wisher from Mukono', 'amount' => 100, 'date' => $d, 'hideName' => true])->assertCreated();
        $this->postJson($url, ['kind' => 'gift', 'contributorType' => 'anonymous', 'amount' => 50, 'date' => $d])->assertCreated();

        $this->getJson("/api/v1/projects/{$p->id}")->assertOk()
            ->assertJsonPath('data.raised', 2150)          // cash only
            ->assertJsonPath('data.inKindValue', 1750)
            ->assertJsonPath('data.goal', 8000);

        // books: gifts → income tagged to the project; thanksgiving → offertory; in kind → nothing
        $this->assertSame(1650.0, (float) Transaction::where('project_id', $p->id)->where('type', 'income')->sum('amount'));
        $this->assertDatabaseHas('offertories', ['category' => 'thanksgiving', 'amount' => 500, 'service' => 'Second Service', 'contributor_name' => 'The Kato family']);

        $sum = $this->getJson("/api/v1/projects/{$p->id}/summary")->assertOk()->json('data');
        $this->assertSame(1800.0, (float) collect($sum['cells'])->firstWhere('name', 'Kireka Cell')['total']); // member + family head + cell
        $this->assertSame('Well-wisher', collect($sum['honourRoll'])->firstWhere('type', 'named')['name']);
        $this->assertFalse(collect($sum['honourRoll'])->contains('type', 'anonymous'));
        $this->assertSame(1750.0, (float) $sum['byKind']['in_kind']);

        // deleting a contribution removes its money record too
        $gift = Contribution::where('kind', 'gift')->where('contributor_type', 'member')->first();
        $this->deleteJson("/api/v1/contributions/{$gift->id}")->assertNoContent();
        $this->assertDatabaseMissing('transactions', ['id' => $gift->transaction_id]);
    }

    public function test_project_pledge_payments_count_toward_the_project(): void
    {
        $this->actingAsRole('treasurer');
        $p = $this->project();
        $m = Member::factory()->create();
        $pledge = $this->postJson('/api/v1/pledges', ['memberId' => $m->id, 'purpose' => 'Land', 'projectId' => $p->id, 'amount' => 600, 'pledgeDate' => today()->toDateString()])
            ->assertCreated()->assertJsonPath('data.project', 'Land acquisition')->json('data.id');

        $this->postJson("/api/v1/pledges/{$pledge}/payments", ['amount' => 250, 'date' => today()->toDateString(), 'paymentMethod' => 'Cash'])
            ->assertCreated()->assertJsonPath('data.paid', 250);

        $this->getJson("/api/v1/projects/{$p->id}")->assertJsonPath('data.raised', 250)->assertJsonPath('data.pledged', 600);
        $this->assertSame(1, Offertory::where('category', 'pledge')->where('pledge_id', $pledge)->count()); // in the offertory once
        $this->assertSame(0, Transaction::where('project_id', $p->id)->count());                           // not double counted as income
    }

    public function test_family_progress_targets_and_statement(): void
    {
        $this->actingAsRole('treasurer');
        $p = $this->project(['suggested_family_amount' => 400]);
        $cell = CellGroup::create(['name' => 'Nsasa Cell']);
        $head = Member::factory()->create();
        $child = Member::factory()->create();
        $h = Household::create(['name' => 'The Okello family', 'head_member_id' => $head->id]);
        $head->update(['household_id' => $h->id]);
        $child->update(['household_id' => $h->id]);
        $url = "/api/v1/projects/{$p->id}/contributions";
        $this->postJson($url, ['kind' => 'gift', 'contributorType' => 'household', 'householdId' => $h->id, 'amount' => 200, 'date' => '2026-11-01'])->assertCreated();
        $this->postJson($url, ['kind' => 'gift', 'contributorType' => 'member', 'memberId' => $child->id, 'amount' => 150, 'date' => '2026-11-01'])->assertCreated();

        $fam = collect($this->getJson("/api/v1/projects/{$p->id}/summary")->json('data.families'))->firstWhere('name', 'The Okello family');
        $this->assertEquals(350, $fam['total']);
        $this->assertEquals(400, $fam['suggested']);

        $this->putJson("/api/v1/projects/{$p->id}/targets", ['cells' => [['cellGroupId' => $cell->id, 'target' => 1000]]])->assertOk()
            ->assertJsonFragment(['name' => 'Nsasa Cell', 'target' => 1000]);

        $this->getJson("/api/v1/reports/statement?memberId={$child->id}&year=2026")->assertOk()
            ->assertJsonPath('data.donationTotal', 150)->assertJsonPath('data.donations.0.campaign', 'Land acquisition');
    }

    public function test_milestones_households_organisations_and_permissions(): void
    {
        $this->actingAsRole('treasurer');
        $p = $this->project();
        $mid = $this->postJson("/api/v1/projects/{$p->id}/milestones", ['title' => 'Deposit (30%)', 'amount' => 3000, 'dueDate' => '2026-12-01'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/milestones/{$mid}", ['status' => 'done'])->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.doneOn', today()->toDateString());
        $this->getJson("/api/v1/projects/{$p->id}")->assertJsonPath('data.milestones.0.title', 'Deposit (30%)');

        $org = $this->postJson('/api/v1/organisations', ['name' => 'Diocese of Kampala', 'type' => 'church'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/projects/{$p->id}/contributions", ['kind' => 'gift', 'contributorType' => 'organisation', 'organisationId' => $org, 'amount' => 5000, 'date' => today()->toDateString()])->assertCreated();
        $this->deleteJson("/api/v1/organisations/{$org}")->assertStatus(422);
        $this->deleteJson("/api/v1/projects/{$p->id}")->assertStatus(422); // has contributions

        $this->actingAsRole('secretary');
        $head = Member::factory()->create(['name' => 'Sarah Achieng']);
        $hid = $this->postJson('/api/v1/households', ['name' => 'The Achieng family', 'headMemberId' => $head->id])->assertCreated()->assertJsonPath('data.members', 1)->json('data.id');
        $kid = Member::factory()->create();
        $this->postJson("/api/v1/households/{$hid}/members", ['memberIds' => [$kid->id]])->assertOk()->assertJsonCount(2, 'data.memberList');
        $this->getJson("/api/v1/members/{$kid->id}")->assertJsonPath('data.household', 'The Achieng family');
        $this->postJson("/api/v1/projects/{$p->id}/contributions", ['kind' => 'gift', 'contributorType' => 'anonymous', 'amount' => 1, 'date' => today()->toDateString()])->assertForbidden();
    }

    public function test_thank_you_sms_with_receipt(): void
    {
        $this->actingAsRole('treasurer');
        $p = $this->project();
        $m = Member::factory()->create(['name' => 'Grace Achieng', 'phone' => '0701234567']);
        $ref = $this->postJson("/api/v1/projects/{$p->id}/contributions", ['kind' => 'gift', 'contributorType' => 'member', 'memberId' => $m->id, 'amount' => 20000, 'date' => today()->toDateString(), 'sendThanks' => true])
            ->assertCreated()->json('data.reference');
        $this->assertDatabaseHas('sms_messages', ['phone' => '+256701234567', 'status' => 'sent']);
        $this->assertStringContainsString($ref, \App\Models\SmsBatch::latest('id')->first()->message);
    }

    public function test_pledge_balance_and_global_search_cover_projects_and_families(): void
    {
        $this->actingAsRole('treasurer');
        $p = $this->project(['name' => 'Church school land']);
        $member = Member::factory()->create(['name' => 'Grace Achieng']);
        $pledge = $this->postJson('/api/v1/pledges', ['memberId' => $member->id, 'projectId' => $p->id, 'purpose' => 'Land', 'amount' => 3000, 'pledgeDate' => today()->toDateString()])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/projects/{$p->id}/contributions", ['kind' => 'pledge_payment', 'pledgeId' => $pledge, 'amount' => 1000, 'date' => today()->toDateString()])->assertCreated();

        $this->getJson("/api/v1/projects/{$p->id}")->assertOk()
            ->assertJsonPath('data.raised', 1000)
            ->assertJsonPath('data.pledged', 3000)
            ->assertJsonPath('data.pledgeBalance', 2000); // paid part is already in "raised"

        Household::create(['name' => 'The Achieng family', 'head_member_id' => $member->id]);
        $hits = $this->getJson('/api/v1/search?q=achieng')->assertOk()->json('data');
        $this->assertSame('The Achieng family', $hits['households'][0]['title']);
        $this->assertSame('project', $this->getJson('/api/v1/search?q=school land')->json('data.projects.0.type'));
    }

    public function test_today_means_the_church_local_date_just_after_midnight(): void
    {
        // 00:30 in Kampala on 3 Nov is still 2 Nov in UTC
        $this->travelTo(\Carbon\Carbon::parse('2026-11-02 21:30:00', 'UTC'));
        $this->actingAsRole('treasurer');
        $p = $this->project();
        $this->postJson("/api/v1/projects/{$p->id}/contributions", ['kind' => 'gift', 'contributorType' => 'anonymous', 'amount' => 100, 'date' => '2026-11-03'])->assertCreated();
        $this->postJson("/api/v1/projects/{$p->id}/contributions", ['kind' => 'gift', 'contributorType' => 'anonymous', 'amount' => 100, 'date' => '2026-11-04'])->assertJsonValidationErrors('date');
    }
}

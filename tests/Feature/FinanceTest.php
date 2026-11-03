<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Member;
use App\Models\Offertory;
use App\Models\Transaction;

class FinanceTest extends ApiTestCase
{
    public function test_record_offertory_generates_reference_and_links_member(): void
    {
        $user = $this->actingAsRole('treasurer');
        $member = Member::factory()->create(['name' => 'Kwame Nkrumah']);

        $this->postJson('/api/v1/offertory', [
            'date' => '2026-11-01', 'category' => 'tithe', 'amount' => 150.5,
            'paymentMethod' => 'Mobile Money', 'memberId' => $member->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.contributor', 'Kwame Nkrumah')
            ->assertJsonPath('data.amount', 150.5)
            ->assertJson(fn ($json) => $json->where('data.reference', fn ($ref) => str_starts_with($ref, 'TH-'))->etc());

        $this->assertDatabaseHas('offertories', ['member_id' => $member->id, 'recorded_by' => $user->id]);
    }

    public function test_offertory_summary_respects_date_filter(): void
    {
        $this->actingAsRole('treasurer');
        Offertory::factory()->create(['category' => 'tithe', 'amount' => 100, 'date' => '2026-10-04']);
        Offertory::factory()->create(['category' => 'tithe', 'amount' => 50, 'date' => '2026-10-31']);
        Offertory::factory()->create(['category' => 'harvest', 'amount' => 999, 'date' => '2026-09-01']);

        $this->getJson('/api/v1/offertory/summary?from=2026-10-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('data.total', 150)
            ->assertJsonPath('data.byCategory.tithe.count', 2)
            ->assertJsonPath('data.byCategory.harvest.total', 0);
    }

    public function test_budget_spent_and_status_derive_from_expenses_including_month_end(): void
    {
        $this->actingAsRole('treasurer');
        Budget::create(['category' => 'Utilities', 'allocated' => 1000, 'period' => '2026-10']);
        Budget::create(['category' => 'Outreach', 'allocated' => 100, 'period' => '2026-10']);
        Transaction::factory()->expense('Utilities')->create(['amount' => 500, 'date' => '2026-10-01']);
        Transaction::factory()->expense('Utilities')->create(['amount' => 400, 'date' => '2026-10-31']); // last day counts
        Transaction::factory()->expense('Utilities')->create(['amount' => 999, 'date' => '2026-11-01']); // next month doesn't
        Transaction::factory()->expense('Outreach')->create(['amount' => 150, 'date' => '2026-10-15']);

        $budgets = collect($this->getJson('/api/v1/budgets?period=2026-10')->assertOk()->json('data'))->keyBy('category');

        $this->assertEquals(900, $budgets['Utilities']['spent']);
        $this->assertSame('warning', $budgets['Utilities']['status']);
        $this->assertSame('exceeded', $budgets['Outreach']['status']);
    }

    public function test_duplicate_budget_category_in_same_period_is_rejected(): void
    {
        $this->actingAsRole('treasurer');
        Budget::create(['category' => 'Utilities', 'allocated' => 1000, 'period' => '2026-10']);

        $this->postJson('/api/v1/budgets', ['category' => 'Utilities', 'allocated' => 5, 'period' => '2026-10'])
            ->assertStatus(422)->assertJsonValidationErrors('category');
        $this->postJson('/api/v1/budgets', ['category' => 'Utilities', 'allocated' => 5, 'period' => '2026-11'])
            ->assertCreated();
    }

    public function test_accounts_summary(): void
    {
        $this->actingAsRole('treasurer');
        Transaction::factory()->create(['type' => 'income', 'amount' => 1000]);
        Transaction::factory()->expense('Utilities')->create(['amount' => 250.5]);

        $this->getJson('/api/v1/accounts/summary')->assertJsonPath('data', ['income' => 1000, 'expense' => 250.5, 'balance' => 749.5]);
    }

    public function test_accounts_trends(): void
    {
        $this->actingAsRole('treasurer');
        Transaction::factory()->create(['type' => 'income', 'amount' => 300, 'date' => now()]);
        Transaction::factory()->expense('Utilities')->create(['amount' => 120, 'date' => now()]);

        $this->getJson('/api/v1/accounts/trends')
            ->assertJsonCount(6, 'data')
            ->assertJsonPath('data.5.income', 300)
            ->assertJsonPath('data.5.expenses', 120);

    }
}

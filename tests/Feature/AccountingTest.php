<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Member;
use App\Models\Offertory;
use App\Models\Pledge;
use App\Models\Project;
use App\Models\Transaction;

class AccountingTest extends ApiTestCase
{
    private function acc(string $code): int
    {
        return Account::where('code', $code)->value('id');
    }

    /** A small church year: opening balances, offertory, a project, welfare and spending. */
    private function books(): Project
    {
        $p = Project::create(['name' => 'Church roof', 'budget' => 5000, 'open_for_contributions' => true]);
        $m = Member::factory()->create();
        $pledge = Pledge::create(['member_id' => $m->id, 'project_id' => $p->id, 'purpose' => 'Roof', 'amount' => 900, 'pledge_date' => '2026-02-01']);

        // opening balances at the start of the year: cash 100, bank 1,000, land 20,000 → general fund 21,100
        $this->postJson('/api/v1/accounting/journals', ['date' => '2026-01-01', 'memo' => 'Opening balances', 'kind' => 'opening', 'lines' => [
            ['accountId' => $this->acc('1000'), 'debit' => 100], ['accountId' => $this->acc('1020'), 'debit' => 1000],
            ['accountId' => $this->acc('1500'), 'debit' => 20000], ['accountId' => $this->acc('3000'), 'credit' => 21100],
        ]])->assertCreated()->assertJsonPath('data.total', 21100);

        Offertory::create(['date' => '2026-03-01', 'category' => 'tithe', 'amount' => 500, 'payment_method' => 'Mobile Money', 'reference' => 'T1']);
        Offertory::create(['date' => '2026-03-01', 'category' => 'pledge', 'amount' => 300, 'payment_method' => 'Cash', 'reference' => 'T2', 'pledge_id' => $pledge->id]);
        Transaction::create(['date' => '2026-03-02', 'type' => 'income', 'category' => 'Welfare', 'amount' => 200, 'payment_method' => 'Cash', 'description' => 'Welfare collection', 'reference' => 'T3']);
        Transaction::create(['date' => '2026-03-03', 'type' => 'expense', 'category' => 'Welfare', 'amount' => 50, 'payment_method' => 'Cash', 'description' => 'Hospital bill', 'reference' => 'T4']);
        Transaction::create(['date' => '2026-03-04', 'type' => 'expense', 'category' => 'Utilities', 'amount' => 120, 'payment_method' => 'Bank Transfer', 'description' => 'Power', 'reference' => 'T5']);
        Transaction::create(['date' => '2026-03-05', 'type' => 'expense', 'category' => 'Building materials', 'amount' => 250, 'payment_method' => 'Cash', 'description' => 'Iron sheets', 'reference' => 'T6', 'project_id' => $p->id]);

        return $p;
    }

    public function test_statements_balance_and_split_funds(): void
    {
        $this->actingAsRole('treasurer');
        $p = $this->books();

        $tb = $this->getJson('/api/v1/accounting/trial-balance?asOf=2026-03-31')->assertOk()->json('data');
        $this->assertTrue($tb['balanced']);
        $row = fn ($code) => collect($tb['rows'])->firstWhere('code', $code);
        $this->assertSame(300.0, (float) $row('1000')['debit']);   // 100 + 300 + 200 − 50 − 250
        $this->assertSame(500.0, (float) $row('1010')['debit']);
        $this->assertSame(880.0, (float) $row('1020')['debit']);   // 1,000 − 120
        $this->assertSame(21100.0, (float) $row('3000')['credit']);
        $this->assertSame(500.0, (float) $row('4000')['credit']);

        $is = $this->getJson('/api/v1/accounting/income-statement?from=2026-01-01&to=2026-03-31')->assertOk()->json('data');
        $this->assertSame(1000.0, (float) $is['income']['total']['total']);       // 500 + 300 + 200
        $this->assertSame(420.0, (float) $is['expenses']['total']['total']);      // 50 + 120 + 250
        $this->assertSame(580.0, (float) $is['surplus']['total']);
        $this->assertSame(380.0, (float) $is['surplus']['general']);              // 500 − 120
        $this->assertSame(200.0, (float) $is['surplus']['restricted']);           // roof 300 − 250, welfare 200 − 50

        $bs = $this->getJson('/api/v1/accounting/balance-sheet?asOf=2026-03-31')->assertOk()->json('data');
        $this->assertTrue($bs['balanced']);
        $this->assertSame(21680.0, (float) $bs['assets']['total']);
        $this->assertSame(20000.0, (float) $bs['assets']['fixedTotal']);
        $funds = collect($bs['funds']['rows'])->keyBy('key');
        $this->assertSame(21480.0, (float) $funds['general']['amount']);
        $this->assertSame(150.0, (float) $funds['welfare']['amount']);
        $this->assertSame(50.0, (float) $funds["p:{$p->id}"]['amount']);
        $this->assertSame('Church roof fund', $funds["p:{$p->id}"]['name']);
        $this->assertSame(200.0, (float) $bs['funds']['restricted']);
        // the 1 January opening journal is where the general fund started, not part of this year's surplus
        $this->assertSame(21100.0, (float) $funds['general']['opening']);
        $this->assertSame(380.0, (float) $funds['general']['movement']);
    }

    public function test_last_years_surplus_moves_into_the_funds(): void
    {
        $this->actingAsRole('treasurer');
        $this->books();
        Offertory::create(['date' => '2027-01-10', 'category' => 'thanksgiving', 'amount' => 70, 'payment_method' => 'Cash', 'reference' => 'N1']);

        $tb = $this->getJson('/api/v1/accounting/trial-balance?asOf=2027-01-31')->assertOk()->json('data');
        $this->assertTrue($tb['balanced']);
        $this->assertSame('2027-01-01', $tb['yearStart']);
        $row = fn ($code) => collect($tb['rows'])->firstWhere('code', $code);
        $this->assertNull($row('4000'));                                   // 2026 income closed off
        $this->assertSame(70.0, (float) $row('4020')['credit']);
        $this->assertSame(21480.0, (float) $row('3000')['credit']);       // 21,100 + 380 general surplus
        $this->assertSame(200.0, (float) $row('3100')['credit']);         // restricted surplus

        $is = $this->getJson('/api/v1/accounting/income-statement?from=2027-01-01&to=2027-03-31')->json('data');
        $this->assertSame(1000.0, (float) $is['income']['total']['previous']); // same period last year
        $this->assertSame(70.0, (float) $is['income']['total']['total']);

        $bs = $this->getJson('/api/v1/accounting/balance-sheet?asOf=2027-01-31')->json('data');
        $general = collect($bs['funds']['rows'])->firstWhere('key', 'general');
        $this->assertSame(21480.0, (float) $general['opening']);
        $this->assertSame(70.0, (float) $general['movement']);
    }

    public function test_financial_year_can_start_in_july(): void
    {
        $this->actingAsRole('admin');
        $this->putJson('/api/v1/settings', ['financialYearStartMonth' => 7])->assertOk();
        $this->getJson('/api/v1/accounting/trial-balance?asOf=2026-03-31')->assertOk()->assertJsonPath('data.yearStart', '2025-07-01');
        $this->getJson('/api/v1/accounting/trial-balance?asOf=2026-08-01')->assertJsonPath('data.yearStart', '2026-07-01');
    }

    public function test_capital_spending_mapped_to_an_asset_stays_in_the_fund(): void
    {
        $this->actingAsRole('treasurer');
        $p = $this->books();
        // the iron sheets become part of the building (capitalised)
        $this->putJson('/api/v1/accounting/mappings', ['mappings' => [['source' => 'expense', 'key' => 'Building materials', 'accountId' => $this->acc('1500')]]])->assertOk();

        $bs = $this->getJson('/api/v1/accounting/balance-sheet?asOf=2026-03-31')->json('data');
        $this->assertTrue($bs['balanced']);
        $this->assertSame(20250.0, (float) $bs['assets']['fixedTotal']);
        $this->assertSame(300.0, (float) collect($bs['funds']['rows'])->firstWhere('key', "p:{$p->id}")['amount']);

        // income can't be mapped to an expense account
        $this->putJson('/api/v1/accounting/mappings', ['mappings' => [['source' => 'income', 'key' => 'Tithes', 'accountId' => $this->acc('5100')]]])
            ->assertJsonValidationErrors('mappings.0.accountId');
    }

    public function test_general_ledger_runs_a_balance(): void
    {
        $this->actingAsRole('treasurer');
        $this->books();
        $gl = $this->getJson('/api/v1/accounting/ledger/'.$this->acc('1000').'?from=2026-03-02&to=2026-03-31')->assertOk()->json('data');
        $this->assertSame(400.0, (float) $gl['opening']);   // opening 100 + pledge 300 on 1 March
        $this->assertCount(3, $gl['rows']);
        $this->assertSame(300.0, (float) $gl['closing']);
        $this->assertSame('Welfare', $gl['rows'][0]['fund']);
        $this->assertSame('Church roof', $gl['rows'][2]['fund']);
    }

    public function test_journals_must_balance_and_respect_permissions(): void
    {
        $this->actingAsRole('treasurer');
        $base = ['date' => '2026-03-01', 'memo' => 'Banked Sunday cash'];
        $this->postJson('/api/v1/accounting/journals', $base + ['lines' => [
            ['accountId' => $this->acc('1020'), 'debit' => 100], ['accountId' => $this->acc('1000'), 'credit' => 90],
        ]])->assertJsonValidationErrors('lines');
        $this->postJson('/api/v1/accounting/journals', $base + ['lines' => [
            ['accountId' => $this->acc('1020'), 'debit' => 100, 'credit' => 100], ['accountId' => $this->acc('1000'), 'credit' => 0],
        ]])->assertJsonValidationErrors('lines.0.debit');
        $id = $this->postJson('/api/v1/accounting/journals', $base + ['kind' => 'transfer', 'lines' => [
            ['accountId' => $this->acc('1020'), 'debit' => 100], ['accountId' => $this->acc('1000'), 'credit' => 100],
        ]])->assertCreated()->assertJsonPath('data.reference', fn ($r) => str_starts_with($r, 'JV-'))->json('data.id');

        $this->deleteJson('/api/v1/accounting/accounts/'.$this->acc('1000'))->assertStatus(422); // system account
        $this->deleteJson('/api/v1/accounting/accounts/'.$this->acc('1520'))->assertNoContent(); // unused

        $this->actingAsRole('pastor'); // can view, not post
        $this->getJson('/api/v1/accounting/journals')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/accounting/journals/{$id}")->assertForbidden();
        $this->actingAsRole('secretary');
        $this->getJson('/api/v1/accounting/balance-sheet')->assertForbidden();
    }
}

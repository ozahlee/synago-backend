<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Offertory;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\Visitor;

class PeriodFilterTest extends ApiTestCase
{
    public function test_lists_and_reports_honour_from_and_to(): void
    {
        $this->actingAsRole('admin');
        $m = Member::factory()->create(['name' => 'Agnes Akello']);
        foreach (['2025-12-28' => 100, '2026-01-04' => 200, '2026-02-15' => 300] as $d => $amt) {
            Offertory::create(['date' => $d, 'category' => 'tithe', 'amount' => $amt, 'payment_method' => 'Cash', 'member_id' => $m->id]);
            Transaction::create(['date' => $d, 'type' => 'expense', 'category' => 'Utilities', 'amount' => $amt, 'description' => 'x']);
        }
        Pledge::create(['member_id' => $m->id, 'purpose' => 'Roof', 'amount' => 500, 'pledge_date' => '2026-01-10']);
        Visitor::create(['name' => 'New friend', 'first_visit' => '2026-02-01', 'status' => 'new']);

        $q = '?from=2026-01-01&to=2026-01-31';
        $this->getJson("/api/v1/offertory{$q}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', 200);
        $this->assertSame(200.0, (float) $this->getJson("/api/v1/accounts/summary{$q}")->json('data.expense'));
        $this->getJson("/api/v1/pledges{$q}")->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/pledges?from=2026-02-01')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/visitors{$q}")->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/visitors?from=2026-02-01&to=2026-02-07')->assertJsonCount(1, 'data');

        // a period across the new year: tithe columns follow the months in it
        $t = $this->getJson('/api/v1/reports/tithes?from=2025-12-01&to=2026-02-28')->assertOk()->json('data');
        $this->assertSame(['Dec 2025', 'Jan 2026', 'Feb 2026'], array_column($t['months'], 'label'));
        $this->assertSame([100, 200, 300], array_map('intval', $t['rows'][0]['months']));
        $this->assertSame(600.0, (float) $t['total']);
        // the old ?year= still works
        $this->assertCount(12, $this->getJson('/api/v1/reports/tithes?year=2026')->json('data.months'));

        $s = $this->getJson("/api/v1/reports/statement?memberId={$m->id}&from=2026-01-01&to=2026-02-28")->assertOk()->json('data');
        $this->assertSame(500.0, (float) $s['offertoryTotal']);

        $this->getJson('/api/v1/offertory?from=2026-02-01&to=2026-01-01')->assertJsonValidationErrors('to');
    }
}

<?php

namespace Tests\Feature;

use App\Models\CellGroup;
use App\Models\Marriage;
use App\Models\Member;
use App\Models\Ministry;
use App\Models\Offertory;
use App\Models\Pledge;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\Visitor;
use App\Models\WelfareCase;
use Illuminate\Support\Facades\Http;

class CareAndOutreachTest extends ApiTestCase
{
    // 1. Attendance
    public function test_attendance_day_save_and_dashboard_rate(): void
    {
        $this->actingAsRole('secretary');
        Setting::put(['services' => ['First Service', 'Second Service']]);
        Member::factory()->count(10)->create(['status' => 'active']);

        $this->getJson('/api/v1/attendance/day?date=2026-11-01')->assertOk()
            ->assertJsonCount(2, 'data.services')->assertJsonPath('data.services.0.recorded', false);

        $this->putJson('/api/v1/attendance/day', ['date' => '2026-11-01', 'services' => [
            ['service' => 'First Service', 'men' => 3, 'women' => 4, 'children' => 6, 'visitors' => 1],
            ['service' => 'Second Service', 'men' => 0, 'women' => 0, 'children' => 0, 'visitors' => 0],
        ]])->assertOk()
            ->assertJsonPath('data.total', 14)
            ->assertJsonPath('data.services.0.total', 14)
            ->assertJsonPath('data.services.1.recorded', false); // all zeros = not saved

        // re-saving the same day updates, not duplicates
        $this->putJson('/api/v1/attendance/day', ['date' => '2026-11-01', 'services' => [
            ['service' => 'First Service', 'men' => 2, 'women' => 3, 'children' => 0, 'visitors' => 0],
        ]])->assertOk()->assertJsonPath('data.total', 5);
        $this->assertDatabaseCount('attendances', 1);

        // 5 adults / 10 active members = 50%
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.attendanceRate', 50);
        $this->getJson('/api/v1/attendance/trends?weeks=52')->assertOk()->assertJsonPath('data.averageAdults', 5);

        $this->actingAsRole('treasurer');
        $this->putJson('/api/v1/attendance/day', ['date' => '2026-11-01', 'services' => [['service' => 'X', 'men' => 1, 'women' => 0, 'children' => 0, 'visitors' => 0]]])
            ->assertForbidden();
    }

    // 2. Marriages
    public function test_marriage_register_with_member_names_and_certificate(): void
    {
        $this->actingAsRole('pastor');
        $groom = Member::factory()->create(['name' => 'Daniel Ssemanda', 'gender' => 'male']);

        $id = $this->postJson('/api/v1/marriages', ['groomMemberId' => $groom->id, 'brideName' => 'Grace Achieng', 'date' => '2026-12-12'])
            ->assertCreated()
            ->assertJsonPath('data.groomName', 'Daniel Ssemanda')
            ->assertJsonPath('data.bannsRead', 0)
            ->assertJsonPath('data.certificateNumber', null)
            ->json('data.id');

        $this->patchJson("/api/v1/marriages/{$id}", ['bannsRead' => 3, 'status' => 'completed'])
            ->assertOk()->assertJsonPath('data.certificateNumber', 'MAR-2026-0001');

        $this->getJson('/api/v1/marriages/stats')->assertOk()->assertJsonPath('data.totalRegistered', 1);
        $this->postJson('/api/v1/marriages', ['date' => '2026-12-12'])->assertStatus(422)->assertJsonValidationErrors(['groomName', 'brideName']);
    }

    // 3. SMS
    public function test_sms_preview_and_send_with_log_driver_and_personalisation(): void
    {
        $this->actingAsRole('secretary');
        $choir = Ministry::factory()->create(['name' => 'Choir']);
        $a = Member::factory()->create(['name' => 'Sarah Osei', 'phone' => '0772 123456', 'status' => 'active']);
        $b = Member::factory()->create(['name' => 'Grace Mensah', 'phone' => '+256701000111', 'status' => 'active']);
        $c = Member::factory()->create(['name' => 'No Phone', 'phone' => null, 'status' => 'active']);
        foreach ([$a, $b, $c] as $m) {
            $m->ministries()->attach($choir->id);
        }

        $this->postJson('/api/v1/sms/preview', ['audience' => ['type' => 'ministry', 'ministryId' => $choir->id], 'message' => 'Hi {name}, practice at 5pm'])
            ->assertOk()
            ->assertJsonPath('data.recipients', 2)
            ->assertJsonPath('data.withoutPhone', 1)
            ->assertJsonPath('data.example', 'Hi Sarah, practice at 5pm')
            ->assertJsonPath('data.driver', 'log');

        $this->postJson('/api/v1/sms', ['audience' => ['type' => 'ministry', 'ministryId' => $choir->id], 'message' => 'Hi {name}'])
            ->assertCreated()->assertJsonPath('data.sent', 2)->assertJsonPath('data.failed', 0);
        $this->assertDatabaseHas('sms_messages', ['phone' => '+256772123456', 'status' => 'sent']);

        $this->actingAsRole('treasurer');
        $this->postJson('/api/v1/sms/preview', ['audience' => ['type' => 'all']])->assertForbidden();
    }

    public function test_sms_africastalking_driver_marks_rejected_numbers_failed(): void
    {
        config(['services.sms.driver' => 'africastalking', 'services.sms.africastalking.api_key' => 'k', 'services.sms.africastalking.username' => 'sandbox', 'services.sms.africastalking.sandbox' => true]);
        Http::fake(['api.sandbox.africastalking.com/*' => Http::response(['SMSMessageData' => ['Recipients' => [
            ['number' => '+256772000001', 'status' => 'Success', 'statusCode' => 101, 'messageId' => 'ATXid_1'],
            ['number' => '+256772000002', 'status' => 'InvalidPhoneNumber', 'statusCode' => 403, 'messageId' => 'None'],
        ]]])]);
        $this->actingAsRole('admin');

        $this->postJson('/api/v1/sms', ['audience' => ['type' => 'numbers', 'numbers' => '0772000001, 0772000002'], 'message' => 'Service moved to 9am'])
            ->assertCreated()->assertJsonPath('data.sent', 1)->assertJsonPath('data.failed', 1)->assertJsonPath('data.driver', 'africastalking');

        Http::assertSent(fn ($r) => $r->hasHeader('apiKey', 'k') && $r['to'] === '+256772000001,+256772000002');
    }

    // 4. Statements
    public function test_member_giving_statement(): void
    {
        $this->actingAsRole('treasurer');
        $m = Member::factory()->create();
        Offertory::factory()->create(['member_id' => $m->id, 'category' => 'tithe', 'amount' => 100, 'date' => '2026-02-01']);
        Offertory::factory()->create(['member_id' => $m->id, 'category' => 'harvest', 'amount' => 40, 'date' => '2026-08-01']);
        Offertory::factory()->create(['member_id' => $m->id, 'category' => 'tithe', 'amount' => 999, 'date' => '2025-12-01']);

        $this->getJson("/api/v1/reports/statement?memberId={$m->id}&year=2026")->assertOk()
            ->assertJsonPath('data.offertoryTotal', 140)
            ->assertJsonPath('data.byCategory.tithe', 100)
            ->assertJsonCount(2, 'data.gifts')
            ->assertJsonPath('data.member.pledgeNumber', $m->pledge_number);

        $this->getJson('/api/v1/reports/statements?year=2026')->assertOk()->assertJsonCount(1, 'data');
    }

    // 5. Pledges
    public function test_pledge_payments_flow_into_offertory_and_status(): void
    {
        $this->actingAsRole('treasurer');
        $m = Member::factory()->create();
        $id = $this->postJson('/api/v1/pledges', ['memberId' => $m->id, 'purpose' => 'Building fund', 'amount' => 500, 'pledgeDate' => '2026-01-10', 'dueDate' => '2026-12-31'])
            ->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.balance', 500)->json('data.id');

        $this->postJson("/api/v1/pledges/{$id}/payments", ['amount' => 200, 'date' => '2026-03-01', 'paymentMethod' => 'Cash', 'service' => 'First Service'])
            ->assertCreated()->assertJsonPath('data.paid', 200)->assertJsonCount(1, 'data.payments');
        $this->assertDatabaseHas('offertories', ['pledge_id' => $id, 'category' => 'pledge', 'member_id' => $m->id, 'amount' => 200]);

        $this->postJson("/api/v1/pledges/{$id}/payments", ['amount' => 300, 'date' => '2026-04-01', 'paymentMethod' => 'Cash'])
            ->assertCreated()->assertJsonPath('data.status', 'fulfilled');

        Pledge::create(['pledger_name' => 'Late', 'purpose' => 'Harvest', 'amount' => 100, 'pledge_date' => '2025-01-01', 'due_date' => '2025-06-01']);
        $this->getJson('/api/v1/pledges?status=overdue')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/pledges/stats')->assertOk()
            ->assertJsonPath('data.promised', 600)->assertJsonPath('data.paid', 500)->assertJsonPath('data.overdue', 1);

        // offertory summary still counts pledge payments
        $this->getJson('/api/v1/offertory/summary?from=2026-01-01&to=2026-12-31')->assertJsonPath('data.byCategory.pledge.total', 500);
    }

    // 6. Visitors
    public function test_visitor_follow_up_and_convert_to_member(): void
    {
        $this->actingAsRole('secretary');
        $id = $this->postJson('/api/v1/visitors', ['name' => 'Peter Okot', 'phone' => '0772555666', 'firstVisit' => '2026-11-01', 'howHeard' => 'Friend'])
            ->assertCreated()->assertJsonPath('data.status', 'new')->json('data.id');

        $this->postJson("/api/v1/visitors/{$id}/follow-ups", ['method' => 'call', 'note' => 'Will come back', 'date' => '2026-11-02'])
            ->assertCreated()->assertJsonPath('data.status', 'contacted')->assertJsonPath('data.called', true)->assertJsonCount(1, 'data.followUps');

        $this->postJson("/api/v1/visitors/{$id}/convert")->assertStatus(422)->assertJsonValidationErrors('gender');
        $memberId = $this->postJson("/api/v1/visitors/{$id}/convert", ['gender' => 'male'])->assertCreated()->json('data.memberId');

        $this->assertDatabaseHas('members', ['id' => $memberId, 'name' => 'Peter Okot', 'phone' => '0772555666']);
        $this->assertSame('joined', Visitor::find($id)->status);
        $this->postJson("/api/v1/visitors/{$id}/convert", ['gender' => 'male'])->assertStatus(422);
    }

    // 7. Welfare
    public function test_welfare_approve_pay_posts_ledger_expense(): void
    {
        $this->actingAsRole('treasurer');
        Transaction::create(['date' => '2026-10-01', 'type' => 'income', 'category' => 'Welfare', 'amount' => 1000, 'description' => 'Welfare collection']);

        $id = $this->postJson('/api/v1/welfare', ['beneficiary' => 'Kato family', 'category' => 'bereavement', 'amountRequested' => 400])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson("/api/v1/welfare/{$id}/pay", ['date' => '2026-11-01', 'paymentMethod' => 'Cash'])->assertStatus(422);

        $this->postJson("/api/v1/welfare/{$id}/decision", ['decision' => 'approve', 'amount' => 300])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.amountApproved', 300);
        $this->postJson("/api/v1/welfare/{$id}/pay", ['date' => '2026-11-01', 'paymentMethod' => 'Mobile Money'])
            ->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertDatabaseHas('transactions', ['type' => 'expense', 'category' => 'Welfare', 'amount' => 300, 'payee' => 'Kato family']);
        $this->getJson('/api/v1/welfare/stats')->assertOk()->assertJsonPath('data.fundBalance', 700);
        $this->deleteJson("/api/v1/welfare/{$id}")->assertStatus(422);

        $this->actingAsRole('secretary');
        $this->getJson('/api/v1/welfare')->assertForbidden();
        $this->actingAsRole('pastor'); // can manage cases but not pay out
        $case = WelfareCase::create(['beneficiary' => 'X', 'category' => 'food', 'status' => 'approved', 'amount_approved' => 10]);
        $this->postJson("/api/v1/welfare/{$case->id}/pay", ['date' => '2026-11-01', 'paymentMethod' => 'Cash'])->assertForbidden();
    }

    // 8. Cell groups
    public function test_cell_groups_from_villages(): void
    {
        $this->actingAsRole('secretary');
        Member::factory()->count(3)->create(['village' => 'Kireka', 'status' => 'active']);
        Member::factory()->count(2)->create(['village' => 'Nsasa', 'status' => 'active']);
        Member::factory()->create(['village' => null, 'status' => 'active']);
        CellGroup::create(['name' => 'Nsasa Fellowship', 'village' => 'Nsasa']);

        $this->getJson('/api/v1/cell-groups')->assertOk()->assertJsonPath('data.unassigned.total', 6);

        $this->postJson('/api/v1/cell-groups/from-villages')->assertOk()
            ->assertJsonPath('data.created', 1)->assertJsonPath('data.assigned', 5);

        $groups = collect($this->getJson('/api/v1/cell-groups')->assertOk()->assertJsonPath('data.unassigned.total', 1)->json('data.groups'))->keyBy('name');
        $this->assertSame(3, $groups['Kireka Cell']['members']);
        $this->assertSame(2, $groups['Nsasa Fellowship']['members']);
    }
}

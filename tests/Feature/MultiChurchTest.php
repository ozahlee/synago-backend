<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Church;
use App\Models\Member;
use App\Models\Offertory;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

class MultiChurchTest extends ApiTestCase
{
    private function signUp(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        Tenant::set(null); // a visitor on the sign-up page has no church yet

        return $this->postJson('/api/v1/auth/register', [
            'churchName' => 'Kampala Pentecostal Fellowship', 'denomination' => 'pentecostal', 'name' => 'Rev. Esther Namubiru',
            'email' => 'esther@kpf.test', 'phone' => '0772000123', 'password' => 'Grace2026', 'password_confirmation' => 'Grace2026',
            'acceptTerms' => true, ...$overrides,
        ]);
    }

    public function test_a_church_signs_up_and_gets_its_own_setup_on_a_trial(): void
    {
        Notification::fake();
        $res = $this->signUp()->assertCreated()
            ->assertJsonPath('user.church.name', 'Kampala Pentecostal Fellowship')
            ->assertJsonPath('user.church.status', 'trial')
            ->assertJsonPath('user.church.trialDaysLeft', 30)
            ->assertJsonPath('user.church.onboarded', false)
            ->assertJsonPath('user.emailVerified', false)
            ->assertJsonPath('user.roles.0', 'admin');
        $this->assertCount(12, $res->json('user.permissions'));

        $church = Church::where('slug', 'kampala-pentecostal-fellowship')->firstOrFail();
        $user = User::where('email', 'esther@kpf.test')->firstOrFail();
        $this->assertSame($church->id, $user->church_id);
        $this->assertSame(40, Account::withoutGlobalScopes()->where('church_id', $church->id)->count());
        $this->assertSame(5, Role::where('church_id', $church->id)->count());
        Notification::assertSentTo($user, VerifyEmail::class);

        // the new admin works inside their own, empty church
        $token = $res->json('token');
        $this->withToken($token)->getJson('/api/v1/members')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/settings')->assertJsonPath('data.churchName', 'Kampala Pentecostal Fellowship')
            ->assertJsonPath('data.pledgePrefix', 'KPF')->assertJsonPath('data.denomination', 'pentecostal');
        $this->withToken($token)->postJson('/api/v1/church/onboarding')->assertOk()->assertJsonPath('data.onboarded', true);

        // one login per email across Synago; weak passwords refused
        $this->signUp(['churchName' => 'Another church'])->assertJsonValidationErrors('email');
        $this->signUp(['email' => 'x@y.test', 'password' => 'short', 'password_confirmation' => 'short'])->assertJsonValidationErrors('password');
    }

    public function test_the_verification_link_confirms_the_email(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($url)->assertRedirect(rtrim(config('app.frontend_url'), '/').'/?verified=1');
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->get(str_replace('signature=', 'signature=x', $url))->assertForbidden();
    }

    public function test_churches_never_see_each_others_records(): void
    {
        // church A (the test's own church) has data
        $a = $this->church;
        $member = Member::factory()->create(['name' => 'Agnes of Church A', 'pledge_number' => 'X-0001']);
        Offertory::create(['date' => '2026-03-01', 'category' => 'tithe', 'amount' => 900, 'payment_method' => 'Cash', 'member_id' => $member->id]);
        $project = Project::create(['name' => 'A building', 'budget' => 1000, 'open_for_contributions' => true]);

        // church B: same pledge number is fine (unique per church)
        $b = $this->makeChurch('Other Church');
        $userB = Tenant::run($b->id, function () {
            Member::factory()->create(['name' => 'Bosco of Church B', 'pledge_number' => 'X-0001']);
            $u = User::factory()->create();
            $u->assignRole('admin');

            return $u;
        });
        $this->assertSame($b->id, $userB->church_id);

        Sanctum::actingAs($userB);
        $this->getJson('/api/v1/members')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Bosco of Church B');
        $this->getJson("/api/v1/members/{$member->id}")->assertNotFound();
        $this->getJson('/api/v1/offertory')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/projects/{$project->id}")->assertNotFound();
        $this->getJson('/api/v1/projects')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/search?q=Agnes')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/reports/tithes?year=2026')->assertJsonPath('data.total', 0);
        $this->assertSame(0.0, (float) $this->getJson('/api/v1/accounting/balance-sheet?asOf=2026-12-31')->json('data.assets.total'));
        $this->getJson('/api/v1/settings')->assertJsonPath('data.churchName', 'Other Church');
        $this->getJson('/api/v1/users')->assertJsonCount(1, 'data');

        // can't link or edit another church's records by id
        $this->postJson('/api/v1/pledges', ['memberId' => $member->id, 'purpose' => 'Roof', 'amount' => 100, 'pledgeDate' => '2026-03-01'])
            ->assertJsonValidationErrors('memberId');
        $this->patchJson("/api/v1/members/{$member->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->deleteJson("/api/v1/projects/{$project->id}")->assertNotFound();
        $this->assertSame('Agnes of Church A', Member::withoutGlobalScopes()->find($member->id)->name);

        // roles are per church: changing B's treasurer leaves A's alone
        $this->putJson('/api/v1/roles/treasurer', ['permissions' => ['members.view']])->assertOk();
        $this->assertCount(1, Role::where('church_id', $b->id)->where('name', 'treasurer')->first()->permissions);
        $this->assertGreaterThan(1, Role::where('church_id', $a->id)->where('name', 'treasurer')->first()->permissions->count());
        $this->getJson('/api/v1/roles')->assertJsonCount(5, 'data');
    }

    public function test_platform_console_and_suspension(): void
    {
        $other = $this->makeChurch('Other Church');
        $churchUser = Tenant::run($other->id, function () {
            $u = User::factory()->create(['email' => 'pastor@other.test', 'password' => 'password']);
            $u->assignRole('admin');

            return $u;
        });
        Tenant::set(null);
        $owner = User::factory()->create(['email' => 'owner@synago.test']);
        $owner->forceFill(['is_platform_admin' => true])->save();
        $owner = $owner->fresh();
        $this->assertNull($owner->church_id);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/platform/churches')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/platform/stats')->assertJsonPath('data.churches', 2);
        $this->getJson('/api/v1/members')->assertForbidden(); // not inside any church
        $this->patchJson("/api/v1/platform/churches/{$other->id}", ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');

        Sanctum::actingAs($churchUser);
        $this->getJson('/api/v1/platform/churches')->assertForbidden();
        $this->getJson('/api/v1/members')->assertForbidden()->assertJsonPath('code', 'church_suspended');
        Tenant::set(null);
        $this->postJson('/api/v1/auth/login', ['email' => 'pastor@other.test', 'password' => 'password'])->assertJsonValidationErrors('email');
    }
}

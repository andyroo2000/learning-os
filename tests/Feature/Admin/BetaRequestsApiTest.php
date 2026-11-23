<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Actions\ApproveBetaRequestAction;
use App\Domain\Admin\Models\AdminInviteCode;
use App\Domain\Admin\Models\ConvoLabBetaRequest;
use App\Domain\Auth\Actions\RegisterConvoLabUserAction;
use App\Mail\ConvoLabBetaInvitationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BetaRequestsApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/convolab/admin/beta-requests';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['services.convolab.client_url' => 'https://convo-lab.com']);
    }

    private function requestRecord(string $email = 'learner@example.com'): ConvoLabBetaRequest
    {
        return ConvoLabBetaRequest::query()->create(['email' => $email, 'devices' => ['Web', 'iOS'], 'message' => 'A paragraph each day.']);
    }

    private function approveUrl(ConvoLabBetaRequest $request): string
    {
        return self::ENDPOINT.'/'.$request->id.'/approve';
    }

    public function test_reads_and_approvals_require_a_browser_admin(): void
    {
        $record = $this->requestRecord();
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->postJson($this->approveUrl($record))->assertUnauthorized();
        $this->asConvoLabBrowser(User::factory()->create());
        $this->getJson(self::ENDPOINT)->assertForbidden();
        $this->postJson($this->approveUrl($record))->assertForbidden();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('admin_invite_codes', 0);
    }

    public function test_mobile_tokens_cannot_read_or_approve_requests(): void
    {
        $record = $this->requestRecord();
        $token = User::factory()->create()->createToken('mobile', ['*'])->plainTextToken;
        $this->withToken($token)->getJson(self::ENDPOINT)->assertForbidden();
        $this->withToken($token)->postJson($this->approveUrl($record))->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_lists_details_and_filters_invited_requests(): void
    {
        $record = $this->requestRecord();
        $this->asConvoLabAdminBrowser()->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('requests.0.email', $record->email)
            ->assertJsonPath('requests.0.message', $record->message)
            ->assertJsonPath('requests.0.devices', ['Web', 'iOS'])
            ->assertJsonPath('total', 1);
        $this->postJson($this->approveUrl($record))->assertOk();
        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonPath('total', 0);
        $this->getJson(self::ENDPOINT.'?status=invited')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_approval_sends_one_invite_and_retry_does_not_generate_or_send_another(): void
    {
        $record = $this->requestRecord();
        $actor = (string) Str::uuid();
        $this->asConvoLabAdminBrowser(convoLabUserId: $actor);
        $first = $this->postJson($this->approveUrl($record))->assertOk();
        $second = $this->postJson($this->approveUrl($record))->assertOk();
        $this->assertSame($first->json('inviteCode'), $second->json('inviteCode'));
        $this->assertSame($first->json('invitationSentAt'), $second->json('invitationSentAt'));
        $this->assertNotNull($first->json('invitationSentAt'));
        $this->assertSame($actor, $record->fresh()->approved_by);
        $this->assertDatabaseCount('admin_invite_codes', 1);
        Mail::assertSent(ConvoLabBetaInvitationMail::class, fn ($mail) => $mail->hasTo('learner@example.com') && $mail->code === $first->json('inviteCode'));
        Mail::assertSentCount(1);
    }

    public function test_failed_delivery_keeps_the_reserved_code_and_can_retry(): void
    {
        $record = $this->requestRecord();
        $this->asConvoLabAdminBrowser();
        $mailer = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Transport unavailable'));
        $this->postJson($this->approveUrl($record))->assertStatus(503);
        $reserved = $record->fresh()->invite_code_id;
        $this->assertNotNull($reserved);
        $this->assertNull($record->fresh()->invitation_sent_at);
        Mail::swap($mailer);
        $this->postJson($this->approveUrl($record))->assertOk();
        $this->assertSame($reserved, $record->fresh()->invite_code_id);
        $this->assertDatabaseCount('admin_invite_codes', 1);
        Mail::assertSentCount(1);
    }

    public function test_delivered_code_can_be_redeemed_by_the_existing_signup_flow(): void
    {
        $record = $this->requestRecord();
        $response = $this->asConvoLabAdminBrowser()->postJson($this->approveUrl($record))->assertOk();
        app(RegisterConvoLabUserAction::class)->handle('learner@example.com', 'long-test-password', 'Learner', $response->json('inviteCode'));
        $this->getJson(self::ENDPOINT.'?status=invited')->assertOk();
        $this->assertNotNull($record->fresh()->inviteCode->used_at);
        $this->postJson($this->approveUrl($record))->assertOk();
        Mail::assertSentCount(1);
    }

    public function test_list_paginates_and_validates_filters(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->requestRecord("learner{$i}@example.com");
        }
        $this->asConvoLabAdminBrowser()->getJson(self::ENDPOINT)->assertOk()->assertJsonCount(25, 'requests')->assertJsonPath('lastPage', 2);
        $this->getJson(self::ENDPOINT.'?page=2')->assertOk()->assertJsonCount(1, 'requests');
        $this->getJson(self::ENDPOINT.'?page[]=1')->assertUnprocessable();
        $this->getJson(self::ENDPOINT.'?status=unknown')->assertUnprocessable();
    }

    public function test_missing_and_malformed_request_ids_do_not_issue_invites(): void
    {
        $this->asConvoLabAdminBrowser();
        $this->postJson(self::ENDPOINT.'/'.Str::uuid().'/approve')->assertNotFound();
        $this->postJson(self::ENDPOINT.'/invalid/approve')->assertNotFound();
        $this->assertDatabaseCount('admin_invite_codes', 0);
        Mail::assertNothingSent();
    }

    public function test_revoked_invite_is_not_replaced_or_emailed_on_retry(): void
    {
        $record = $this->requestRecord();
        $this->asConvoLabAdminBrowser();
        $approved = app(ApproveBetaRequestAction::class)->handle($record->id, (string) Str::uuid());
        AdminInviteCode::query()->whereKey($approved->invite_code_id)->delete();
        $this->postJson($this->approveUrl($record))->assertStatus(409);
        $this->assertDatabaseCount('admin_invite_codes', 0);
        Mail::assertNothingSent();
    }

    public function test_email_includes_code_redemption_link_and_plain_text_alternative(): void
    {
        $mail = new ConvoLabBetaInvitationMail('WELCOME8');
        $mail->assertSeeInHtml('Journal');
        $mail->assertSeeInHtml('WELCOME8');
        $mail->assertSeeInHtml('https://convo-lab.com/login?inviteCode=WELCOME8');
        $mail->assertSeeInText('WELCOME8');
        $mail->assertSeeInText('https://convo-lab.com/login?inviteCode=WELCOME8');
    }
}

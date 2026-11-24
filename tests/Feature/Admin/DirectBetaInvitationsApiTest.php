<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Models\ConvoLabBetaRequest;
use App\Mail\ConvoLabBetaInvitationMail;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DirectBetaInvitationsApiTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/convolab/admin/beta-requests';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_admin_can_email_an_invitation_and_list_it_as_invited(): void
    {
        $this->withoutMiddleware(TrimStrings::class);
        $actor = (string) Str::uuid();
        $response = $this->asConvoLabAdminBrowser(convoLabUserId: $actor)
            ->postJson(self::ENDPOINT, ['email' => '  Learner@Example.COM  '])->assertOk()
            ->assertJsonPath('email', 'learner@example.com')->assertJsonPath('devices', [])
            ->assertJsonPath('message', '')->assertJsonPath('redeemedAt', null);
        $record = ConvoLabBetaRequest::query()->sole();
        $this->assertSame($actor, $record->approved_by);
        $this->assertNotNull($record->approved_at);
        $this->assertNotNull($record->invitation_sent_at);
        $this->assertSame($record->inviteCode->code, $response->json('inviteCode'));
        Mail::assertSent(ConvoLabBetaInvitationMail::class, fn ($mail) => $mail->hasTo('learner@example.com') && $mail->code === $record->inviteCode->code);
        Mail::assertSentCount(1);
        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonPath('total', 0);
        $this->getJson(self::ENDPOINT.'?status=invited')->assertOk()->assertJsonPath('requests.0.id', $record->id);
    }

    public function test_duplicate_submissions_reuse_the_request_and_do_not_resend(): void
    {
        $this->asConvoLabAdminBrowser();
        $first = $this->postJson(self::ENDPOINT, ['email' => 'learner@example.com'])->assertOk();
        $this->postJson(self::ENDPOINT, ['email' => 'LEARNER@example.com'])->assertOk()
            ->assertJsonPath('id', $first->json('id'))
            ->assertJsonPath('inviteCode', $first->json('inviteCode'))
            ->assertJsonPath('invitationSentAt', $first->json('invitationSentAt'));
        $this->assertDatabaseCount('convolab_beta_requests', 1);
        $this->assertDatabaseCount('admin_invite_codes', 1);
        Mail::assertSentCount(1);
    }

    public function test_inviting_an_existing_applicant_preserves_their_details(): void
    {
        $record = ConvoLabBetaRequest::query()->create([
            'email' => 'learner@example.com', 'devices' => ['iOS'], 'message' => 'Learning Japanese.',
        ]);
        $this->asConvoLabAdminBrowser()->postJson(self::ENDPOINT, ['email' => $record->email])->assertOk()
            ->assertJsonPath('id', $record->id)->assertJsonPath('devices', ['iOS'])
            ->assertJsonPath('message', 'Learning Japanese.');
        $this->assertDatabaseCount('convolab_beta_requests', 1);
        Mail::assertSentCount(1);
    }

    public function test_delivery_failure_stays_pending_and_retries_the_same_code(): void
    {
        $this->asConvoLabAdminBrowser();
        $mailer = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Transport unavailable'));
        $this->postJson(self::ENDPOINT, ['email' => 'learner@example.com'])->assertStatus(503);
        $record = ConvoLabBetaRequest::query()->sole();
        $reserved = $record->invite_code_id;
        $this->assertNotNull($reserved);
        $this->assertNull($record->invitation_sent_at);
        $this->getJson(self::ENDPOINT)->assertOk()->assertJsonPath('requests.0.id', $record->id);
        Mail::swap($mailer);
        $this->postJson(self::ENDPOINT, ['email' => $record->email])->assertOk();
        $this->assertSame($reserved, $record->fresh()->invite_code_id);
        $this->assertDatabaseCount('admin_invite_codes', 1);
        Mail::assertSentCount(1);
    }
}

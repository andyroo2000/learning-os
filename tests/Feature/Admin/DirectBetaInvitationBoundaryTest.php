<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DirectBetaInvitationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/convolab/admin/beta-requests';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_only_browser_admins_can_send_invitations(): void
    {
        $body = ['email' => 'learner@example.com'];
        $this->postJson(self::ENDPOINT, $body)->assertUnauthorized();
        $this->asConvoLabBrowser(User::factory()->create())->postJson(self::ENDPOINT, $body)->assertForbidden();
        $this->assertDatabaseCount('convolab_beta_requests', 0);
        $this->assertDatabaseCount('admin_invite_codes', 0);
        Mail::assertNothingSent();
    }

    public function test_mobile_tokens_cannot_send_invitations(): void
    {
        $token = User::factory()->create()->createToken('mobile', ['*'])->plainTextToken;
        $this->withToken($token)->postJson(self::ENDPOINT, ['email' => 'learner@example.com'])->assertForbidden();
        $this->assertDatabaseCount('convolab_beta_requests', 0);
        Mail::assertNothingSent();
    }

    #[DataProvider('invalidEmails')]
    public function test_invalid_addresses_have_no_side_effects(mixed $email): void
    {
        $this->asConvoLabAdminBrowser()->postJson(self::ENDPOINT, ['email' => $email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('convolab_beta_requests', 0);
        $this->assertDatabaseCount('admin_invite_codes', 0);
        Mail::assertNothingSent();
    }

    public static function invalidEmails(): array
    {
        return [[null], [''], ['  '], [123], [['learner@example.com']], ['not-an-email'], ["a@example.com\r\nBcc: b@example.com"], [str_repeat('a', 255).'@example.com']];
    }

    public function test_direct_invitation_is_rate_limited(): void
    {
        $this->asConvoLabAdminBrowser();
        for ($i = 0; $i < 30; $i++) {
            $this->postJson(self::ENDPOINT, ['email' => 'learner@example.com'])->assertOk();
        }
        $this->postJson(self::ENDPOINT, ['email' => 'other@example.com'])->assertTooManyRequests();
        $this->assertDatabaseCount('convolab_beta_requests', 1);
        Mail::assertSentCount(1);
        $this->app['auth']->forgetGuards();
        $this->asConvoLabAdminBrowser(User::factory()->create())->postJson(self::ENDPOINT, ['email' => 'other@example.com'])->assertOk();
        Mail::assertSentCount(2);
    }
}

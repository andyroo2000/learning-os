<?php

namespace Tests\Feature\Auth;

use App\Mail\ConvoLabBetaAccessRequestMail;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ConvoLabBetaAccessRequestTest extends TestCase
{
    private const ENDPOINT = '/api/convolab/browser/auth/beta-requests';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.convolab.beta_request_email' => 'owner@example.com']);
        Mail::fake();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['email' => 'learner@example.com', 'devices' => ['Web', 'iOS'], 'message' => 'I want to practice Japanese.'], $overrides);
    }

    public function test_guest_request_emails_only_the_configured_recipient(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(['to' => 'attacker@example.com']))->assertOk();
        Mail::assertSent(ConvoLabBetaAccessRequestMail::class, function ($mail) {
            return $mail->hasTo('owner@example.com')
                && ! $mail->hasTo('attacker@example.com')
                && $mail->requesterEmail === 'learner@example.com'
                && $mail->devices === ['Web', 'iOS']
                && $mail->reason === 'I want to practice Japanese.'
                && $mail->envelope()->replyTo[0]->address === 'learner@example.com';
        });
        Mail::assertSentCount(1);
    }

    public function test_message_is_optional_and_all_platforms_are_supported(): void
    {
        $this->postJson(self::ENDPOINT, ['email' => 'learner@example.com', 'devices' => ['Web', 'iOS', 'Android']])->assertOk();
        Mail::assertSent(ConvoLabBetaAccessRequestMail::class, fn ($mail) => $mail->reason === '' && count($mail->devices) === 3);
    }

    #[DataProvider('invalidRequests')]
    public function test_rejects_invalid_requests_without_sending(array $overrides, string $field): void
    {
        $this->postJson(self::ENDPOINT, $this->payload($overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
        Mail::assertNothingSent();
    }

    public static function invalidRequests(): array
    {
        return [
            'invalid email' => [['email' => 'not-an-email'], 'email'],
            'email array' => [['email' => ['learner@example.com']], 'email'],
            'email header injection' => [['email' => "a@example.com\r\nBcc: b@example.com"], 'email'],
            'missing platforms' => [['devices' => []], 'devices'],
            'scalar platforms' => [['devices' => 'Web'], 'devices'],
            'unknown platform' => [['devices' => ['Other']], 'devices.0'],
            'duplicate platform' => [['devices' => ['Web', 'Web']], 'devices.0'],
            'comment in email' => [['email' => 'learner(comment)@example.com'], 'email'],
            'message array' => [['message' => ['test']], 'message'],
            'message limit' => [['message' => str_repeat('a', 2001)], 'message'],
        ];
    }

    public function test_limits_guest_submissions_by_ip(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson(self::ENDPOINT, $this->payload())->assertOk();
        }
        $this->postJson(self::ENDPOINT, $this->payload())->assertTooManyRequests();
        Mail::assertSentCount(3);
    }

    public function test_mail_failure_does_not_report_success(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Mail unavailable'));
        $this->postJson(self::ENDPOINT, $this->payload())->assertServerError();
    }

    public function test_missing_recipient_fails_without_sending(): void
    {
        config(['services.convolab.beta_request_email' => null]);
        $this->postJson(self::ENDPOINT, $this->payload())->assertStatus(503);
        Mail::assertNothingSent();
    }

    public function test_mail_template_contains_the_request_details(): void
    {
        $mail = new ConvoLabBetaAccessRequestMail('learner@example.com', ['Android'], "I don't know & I’d like to practice <Japanese>.");
        $mail->assertSeeInText('learner@example.com');
        $mail->assertSeeInText('Android');
        $mail->assertSeeInText("I don't know & I’d like to practice <Japanese>.");
    }
}

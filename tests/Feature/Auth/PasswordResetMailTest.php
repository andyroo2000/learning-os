<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetMailTest extends TestCase
{
    public function test_plain_text_preserves_punctuation_in_translated_copy(): void
    {
        app('translator')->addLines(['*.Reset your password' => 'Reset & recover your password'], 'en');
        $text = view('mail.convolab-password-reset-text', [
            'actionUrl' => 'https://client.example/reset-password?token=test&email=ada%40example.com',
            'outroLines' => ["If you didn't request this, no action is required."],
        ])->render();

        $this->assertStringContainsString('Reset & recover your password', $text);
        $this->assertStringContainsString("If you didn't request this", $text);
        $this->assertStringNotContainsString('&amp;', $text);
        $this->assertStringNotContainsString('&#039;', $text);
    }

    public function test_reset_notification_sends_branded_html_and_plain_text_with_the_valid_reset_link(): void
    {
        config([
            'app.name' => 'Laravel',
            'app.password_reset_url' => 'https://client.example/reset-password?source=email',
            'auth.passwords.users.expire' => 45,
            'mail.default' => 'array',
        ]);
        $user = new User(['email' => 'ada+test@example.com', 'name' => 'Ada']);

        $user->notify(new ResetPassword('test-reset-token'));

        $message = Mail::mailer()->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame('Reset your ConvoLab password', $message->getSubject());
        $this->assertSame('ada+test@example.com', $message->getTo()[0]->getAddress());
        $html = $message->getHtmlBody();
        $text = $message->getTextBody();
        $this->assertIsString($html);
        $this->assertIsString($text);
        $url = 'https://client.example/reset-password?source=email&token=test-reset-token&email=ada%2Btest%40example.com';
        $this->assertStringContainsString('CONVO-LAB', $html);
        $this->assertStringContainsString('href="'.e($url).'"', $html);
        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('ConvoLab', $body);
            $this->assertStringContainsString('45 minutes', $body);
            $this->assertStringContainsString('If you did not request a password reset', $body);
            $this->assertStringNotContainsString('Laravel', $body);
            $this->assertStringNotContainsString('laravel.com', $body);
        }
        $this->assertStringContainsString($url, $text);
        $attachments = $message->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('inline', $attachments[0]->getDisposition());
        $this->assertSame('image', $attachments[0]->getMediaType());
        $this->assertSame('png', $attachments[0]->getMediaSubtype());
        $this->assertStringContainsString('cid:'.$attachments[0]->getContentId(), $html);
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Mail\ConvoLabVerificationMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ConvoLabVerificationMailTest extends TestCase
{
    public function test_verification_mail_preserves_the_link_and_safely_renders_both_bodies(): void
    {
        config(['mail.default' => 'array', 'app.name' => 'Laravel']);
        $name = '<b>Ada & Bea</b>';
        $url = 'https://client.example/verify-email/test-token?source=email&locale=en';

        Mail::to('ada@example.com')->send(new ConvoLabVerificationMail($name, $url));

        $message = Mail::mailer()->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame('Verify your ConvoLab email', $message->getSubject());
        $this->assertSame('ada@example.com', $message->getTo()[0]->getAddress());
        $html = $message->getHtmlBody();
        $text = $message->getTextBody();
        $this->assertIsString($html);
        $this->assertIsString($text);
        $this->assertStringContainsString(e($name), $html);
        $this->assertStringNotContainsString($name, $html);
        $this->assertStringContainsString('href="'.e($url).'"', $html);
        $this->assertStringContainsString($url, $text);
        $this->assertStringContainsString($name, $text);
        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('ConvoLab', $body);
            $this->assertStringContainsString('24 hours', $body);
            $this->assertStringContainsString('If you did not create a ConvoLab account', $body);
            $this->assertStringNotContainsString('Laravel', $body);
        }
        $attachments = $message->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('inline', $attachments[0]->getDisposition());
        $this->assertSame('image', $attachments[0]->getMediaType());
        $this->assertSame('png', $attachments[0]->getMediaSubtype());
        $this->assertStringContainsString('cid:'.$attachments[0]->getContentId(), $html);
    }
}

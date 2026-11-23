<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class ConvoLabBetaInvitationMail extends Mailable
{
    public readonly string $redeemUrl;

    public function __construct(public readonly string $code)
    {
        $base = config('services.convolab.client_url');
        $base = is_string($base) ? $base : '';
        $validUrl = filter_var($base, FILTER_VALIDATE_URL);
        if (! $validUrl || ! in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new \RuntimeException('The Journal invitation URL is not configured.');
        }
        $this->redeemUrl = rtrim($base, '/').'/login?'.http_build_query(['inviteCode' => $code]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'An invitation to Journal by ConvoLab');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.convolab-beta-invitation', text: 'mail.convolab-beta-invitation-text');
    }
}

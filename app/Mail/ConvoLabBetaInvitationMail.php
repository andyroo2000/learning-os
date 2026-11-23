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
        $this->redeemUrl = rtrim(config('services.convolab.client_url'), '/').'/login?'.http_build_query(['inviteCode' => $code]);
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

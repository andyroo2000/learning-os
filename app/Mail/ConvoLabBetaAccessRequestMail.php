<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class ConvoLabBetaAccessRequestMail extends Mailable
{
    /** @param list<string> $devices */
    public function __construct(
        public readonly string $requesterEmail,
        public readonly array $devices,
        public readonly string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->requesterEmail)],
            subject: 'Journal beta access request',
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.convolab-beta-access-request');
    }
}

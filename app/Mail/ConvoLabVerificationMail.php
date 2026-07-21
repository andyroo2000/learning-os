<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ConvoLabVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $verificationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your ConvoLab email');
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.convolab-verification',
            with: [
                'name' => $this->name,
                'verificationUrl' => $this->verificationUrl,
            ],
        );
    }
}

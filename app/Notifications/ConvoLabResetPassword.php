<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ConvoLabResetPassword extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        // Retain the broker's expiration copy and the configured client reset URL.
        return $this->buildMailMessage($this->resetUrl($notifiable))
            ->subject(__('Reset your ConvoLab password'))
            ->view([
                'html' => 'mail.convolab-password-reset',
                'text' => 'mail.convolab-password-reset-text',
            ]);
    }
}

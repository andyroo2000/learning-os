<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Actions\SendPasswordResetLinkAction;
use App\Jobs\SendPasswordResetLink;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SendPasswordResetLinkActionTest extends TestCase
{
    public function test_it_queues_a_password_reset_for_a_normalized_email(): void
    {
        Queue::fake();

        app(SendPasswordResetLinkAction::class)->handle(' ADA@example.com ');

        Queue::assertPushed(
            SendPasswordResetLink::class,
            fn (SendPasswordResetLink $job): bool => $job->email === 'ada@example.com',
        );
    }
}

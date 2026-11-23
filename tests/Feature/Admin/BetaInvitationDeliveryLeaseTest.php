<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Actions\ApproveBetaRequestAction;
use App\Domain\Admin\Actions\BetaInvitationDeliveryLease;
use App\Domain\Admin\Models\ConvoLabBetaRequest;
use App\Mail\ConvoLabBetaInvitationMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class BetaInvitationDeliveryLeaseTest extends TestCase
{
    use RefreshDatabase;

    private function approved(): ConvoLabBetaRequest
    {
        $request = ConvoLabBetaRequest::query()->create(['email' => 'learner@example.com', 'devices' => ['Web'], 'message' => 'Hello']);

        return app(ApproveBetaRequestAction::class)->handle($request->id, (string) Str::uuid());
    }

    public function test_active_delivery_cannot_be_claimed_twice(): void
    {
        $lease = app(BetaInvitationDeliveryLease::class);
        $record = $this->approved();
        $first = $lease->claim($record);
        try {
            $lease->claim($record);
            $this->fail('A concurrent sender must wait.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame($first->sending_token, $record->fresh()->sending_token);
    }

    public function test_expired_delivery_can_retry_and_stale_sender_cannot_clear_new_lease(): void
    {
        $lease = app(BetaInvitationDeliveryLease::class);
        $record = $this->approved();
        $first = $lease->claim($record);
        $record->forceFill(['sending_started_at' => now()->subMinutes(6)])->save();
        $second = $lease->claim($record);
        $this->assertNotSame($first->sending_token, $second->sending_token);
        $this->assertSame($first->invite_code_id, $second->invite_code_id);
        $lease->finish($first, false);
        $this->assertSame($second->sending_token, $record->fresh()->sending_token);
        $lease->finish($second, true);
        $sent = $record->fresh();
        $this->assertNull($sent->sending_token);
        $this->assertNotNull($sent->invitation_sent_at);
        $lease->finish($first, false);
        $this->assertSame($sent->invitation_sent_at->toJSON(), $record->fresh()->invitation_sent_at->toJSON());
    }

    public function test_invalid_redemption_configuration_cannot_produce_a_broken_email(): void
    {
        config(['services.convolab.client_url' => '']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The Journal invitation URL is not configured.');
        new ConvoLabBetaInvitationMail('WELCOME8');
    }
}

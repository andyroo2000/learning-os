<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\Actions\CreateBetaInvitationAction;
use App\Domain\Admin\Models\ConvoLabBetaRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CreateBetaInvitationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_call_normalizes_email_and_reuses_approval(): void
    {
        $actor = (string) Str::uuid();
        $action = app(CreateBetaInvitationAction::class);
        $first = $action->handle('  Learner@Example.COM ', $actor);
        $second = $action->handle('learner@example.com', (string) Str::uuid());
        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->invite_code_id, $second->invite_code_id);
        $record = ConvoLabBetaRequest::query()->sole();
        $this->assertSame('learner@example.com', $record->email);
        $this->assertSame($actor, $record->approved_by);
        $this->assertNotNull($record->approved_at);
        $this->assertNull($record->invitation_sent_at);
        $this->assertDatabaseCount('admin_invite_codes', 1);
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_direct_input_has_no_side_effects(string $email, string $actor, string $field): void
    {
        try {
            app(CreateBetaInvitationAction::class)->handle($email, $actor);
            $this->fail('Invalid input was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertDatabaseCount('convolab_beta_requests', 0);
        $this->assertDatabaseCount('admin_invite_codes', 0);
    }

    public static function invalidInput(): array
    {
        return [
            ['invalid', 'bf3dbb5e-00c9-4e50-a4e9-3a0451e55547', 'email'],
            ['learner@example.com', 'not-a-user-id', 'actor'],
        ];
    }
}

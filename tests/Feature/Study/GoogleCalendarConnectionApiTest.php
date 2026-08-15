<?php

namespace Tests\Feature\Study;

use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleCalendarConnectionApiTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('endpointProvider')]
    public function test_connection_endpoints_require_authentication(string $method): void
    {
        $this->json($method, '/api/study/google-calendar')->assertUnauthorized();
    }

    /** @return array<string, array{string}> */
    public static function endpointProvider(): array
    {
        return ['status' => ['GET'], 'disconnect' => ['DELETE']];
    }

    public function test_disconnected_status_is_stable_and_does_not_create_a_connection(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/study/google-calendar')
            ->assertExactJson([
                'connected' => false,
                'accountEmail' => null,
                'scopes' => [],
                'settings' => null,
                'connectedAt' => null,
                'lastSyncedAt' => null,
            ]);

        $this->assertDatabaseCount('google_calendar_connections', 0);
    }

    public function test_connected_status_exposes_only_safe_fields_and_secrets_are_encrypted(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user, [
            'last_synced_at' => Carbon::parse('2026-08-15T14:11:12.123456Z'),
        ]);

        $this->actingAs($user)->getJson('/api/study/google-calendar')
            ->assertExactJson([
                'connected' => true,
                'accountEmail' => 'andrew@example.com',
                'scopes' => ['calendar.readonly'],
                'settings' => ['calendarIds' => ['primary'], 'titleMatchTerms' => ['iTalki'], 'syncEnabled' => true],
                'connectedAt' => '2026-08-15T14:00:00Z',
                'lastSyncedAt' => '2026-08-15T14:11:12Z',
            ]);

        $raw = DB::table('google_calendar_connections')->where('id', $connection->id)->first();
        $this->assertNotSame('access-secret', $raw->access_token);
        $this->assertNotSame('refresh-secret', $raw->refresh_token);
        $this->assertNotSame('{"primary":"cursor-secret"}', $raw->sync_cursors);
        $this->assertSame('access-secret', $connection->fresh()->access_token);
        $this->assertSame(['primary' => 'cursor-secret'], $connection->fresh()->sync_cursors);
        foreach (['access_token', 'refresh_token', 'sync_cursors', 'token_expires_at'] as $secret) {
            $this->assertArrayNotHasKey($secret, $connection->toArray());
        }
        $connection->forceFill(['settings' => ['calendarIds' => ['primary'], 'syncEnabled' => true]])->save();
        $this->actingAs($user)->getJson('/api/study/google-calendar')->assertJsonPath('settings', null);
    }

    public function test_disconnect_is_owner_scoped_and_idempotent(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownerConnection = $this->connection($owner);
        $otherConnection = $this->connection($other, ['provider_account_id' => 'other-account']);

        $this->actingAs($owner)->deleteJson('/api/study/google-calendar')->assertNoContent();
        $this->actingAs($owner)->deleteJson('/api/study/google-calendar')->assertNoContent();

        $this->assertDatabaseMissing('google_calendar_connections', ['id' => $ownerConnection->id]);
        $this->assertDatabaseHas('google_calendar_connections', ['id' => $otherConnection->id]);
    }

    public function test_status_never_exposes_another_users_connection(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $this->connection($owner);

        $this->actingAs($viewer)->getJson('/api/study/google-calendar')
            ->assertOk()
            ->assertJsonPath('connected', false)
            ->assertJsonPath('accountEmail', null);
    }

    public function test_connection_is_deleted_with_its_owner(): void
    {
        $user = User::factory()->create();
        $this->connection($user);

        $user->delete();

        $this->assertDatabaseCount('google_calendar_connections', 0);
    }

    public function test_each_user_can_have_only_one_connection(): void
    {
        $user = User::factory()->create();
        $this->connection($user);

        $this->expectException(QueryException::class);

        $this->connection($user, ['provider_account_id' => 'duplicate-account']);
    }

    public function test_server_owned_connection_fields_are_mass_assignment_guarded(): void
    {
        $connection = new GoogleCalendarConnection;

        $this->expectException(MassAssignmentException::class);

        $connection->fill(['access_token' => 'leaked', 'user_id' => 123]);
    }

    /** @param array<string, mixed> $overrides */
    private function connection(User $user, array $overrides = []): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::query()->forceCreate(array_merge([
            'user_id' => $user->id,
            'provider_account_id' => 'google-account',
            'account_email' => 'andrew@example.com',
            'access_token' => 'access-secret',
            'refresh_token' => 'refresh-secret',
            'token_expires_at' => Carbon::parse('2026-08-15T15:00:00Z'),
            'scopes' => ['calendar.readonly'],
            'settings' => ['calendarIds' => ['primary'], 'titleMatchTerms' => ['iTalki'], 'syncEnabled' => true],
            'sync_cursors' => ['primary' => 'cursor-secret'],
            'connected_at' => Carbon::parse('2026-08-15T14:00:00Z'),
            'last_synced_at' => null,
        ], $overrides));
    }
}

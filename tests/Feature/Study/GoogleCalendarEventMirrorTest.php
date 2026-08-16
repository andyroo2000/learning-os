<?php

namespace Tests\Feature\Study;

use App\Domain\Calendar\Actions\ConnectGoogleCalendarAction;
use App\Domain\Calendar\Actions\DisconnectGoogleCalendarAction;
use App\Domain\Calendar\Actions\UpdateGoogleCalendarSettingsAction;
use App\Domain\Calendar\Data\GoogleCalendarOAuthGrant;
use App\Domain\Calendar\Data\GoogleCalendarSettings;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Domain\Calendar\Models\GoogleCalendarEventMirror;
use App\Domain\Study\Support\StudyActivitySourceKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GoogleCalendarEventMirrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_has_the_minimal_schema_indexes_and_cascade_foreign_key(): void
    {
        $this->assertTrue(Schema::hasColumns('google_calendar_event_mirrors', [
            'google_calendar_connection_id', 'source_key', 'calendar_id', 'provider_event_id',
            'recurring_event_id', 'original_start_at', 'status', 'title', 'starts_at', 'ends_at',
            'all_day', 'provider_updated_at', 'observed_at', 'created_at', 'updated_at',
        ]));
        foreach (['payload', 'access_token', 'refresh_token', 'attendees', 'description', 'location'] as $column) {
            $this->assertFalse(Schema::hasColumn('google_calendar_event_mirrors', $column));
        }

        $indexes = collect(Schema::getIndexes('google_calendar_event_mirrors'));
        $this->assertTrue($indexes->contains(fn (array $index): bool => ($index['unique'] ?? false) && $index['columns'] === ['google_calendar_connection_id', 'source_key']));
        $this->assertTrue($indexes->contains(fn (array $index): bool => $index['columns'] === ['google_calendar_connection_id', 'status', 'ends_at']));
        $this->assertFalse($indexes->contains(fn (array $index): bool => in_array('calendar_id', $index['columns'], true)));

        $foreign = collect(Schema::getForeignKeys('google_calendar_event_mirrors'))
            ->firstWhere('columns', ['google_calendar_connection_id']);
        $this->assertSame('google_calendar_connections', $foreign['foreign_table'] ?? null);
        $this->assertSame('cascade', strtolower((string) ($foreign['on_delete'] ?? '')));
        $this->assertLessThanOrEqual(63, strlen((string) ($foreign['name'] ?? '')));
    }

    public function test_migration_can_round_trip_down_and_up(): void
    {
        $migration = require database_path('migrations/2026_08_16_000000_create_google_calendar_event_mirrors_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('google_calendar_event_mirrors'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('google_calendar_event_mirrors'));
    }

    public function test_model_guards_all_provider_fields(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new GoogleCalendarEventMirror)->fill(['status' => 'confirmed']);
    }

    public function test_model_casts_and_bidirectional_relations(): void
    {
        $connection = $this->connection(User::factory()->create());
        $mirror = $this->mirror($connection, ['all_day' => true]);

        $mirror->refresh();
        $this->assertTrue($mirror->all_day);
        foreach (['original_start_at', 'starts_at', 'ends_at', 'provider_updated_at', 'observed_at'] as $attribute) {
            $this->assertInstanceOf(CarbonImmutable::class, $mirror->{$attribute});
        }
        $this->assertTrue($mirror->connection->is($connection));
        $this->assertTrue($connection->eventMirrors->contains($mirror));
    }

    public function test_source_key_is_unique_within_a_connection(): void
    {
        $connection = $this->connection(User::factory()->create());
        $this->mirror($connection);

        $this->expectException(QueryException::class);
        $this->mirror($connection, ['provider_event_id' => 'different-provider-id']);
    }

    public function test_the_same_source_key_is_allowed_for_different_connections(): void
    {
        $first = $this->connection(User::factory()->create(), 'account-one');
        $second = $this->connection(User::factory()->create(), 'account-two');
        $this->mirror($first);
        $this->mirror($second);

        $this->assertDatabaseCount('google_calendar_event_mirrors', 2);
    }

    public function test_disconnect_cascades_only_the_owners_mirrors(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownerConnection = $this->connection($owner, 'owner-account');
        $otherConnection = $this->connection($other, 'other-account');
        $ownerMirror = $this->mirror($ownerConnection);
        $otherMirror = $this->mirror($otherConnection);

        app(DisconnectGoogleCalendarAction::class)->handle($owner->id);

        $this->assertDatabaseMissing('google_calendar_event_mirrors', ['id' => $ownerMirror->id]);
        $this->assertDatabaseHas('google_calendar_event_mirrors', ['id' => $otherMirror->id]);
    }

    public function test_same_account_reconnect_and_settings_edits_preserve_mirrors(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user, 'stable-account');
        $mirror = $this->mirror($connection);

        app(ConnectGoogleCalendarAction::class)->handle($user->id, $this->grant('stable-account', null));
        app(UpdateGoogleCalendarSettingsAction::class)->handle(
            $user->id,
            GoogleCalendarSettings::make(['primary'], ['lesson'], true),
        );

        $this->assertDatabaseHas('google_calendar_event_mirrors', ['id' => $mirror->id]);
    }

    public function test_account_switch_deletes_only_the_owners_old_mirrors(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownerConnection = $this->connection($owner, 'old-account');
        $otherConnection = $this->connection($other, 'other-account');
        $ownerMirror = $this->mirror($ownerConnection);
        $otherMirror = $this->mirror($otherConnection);

        app(ConnectGoogleCalendarAction::class)->handle($owner->id, $this->grant('new-account', 'new-refresh'));

        $this->assertDatabaseMissing('google_calendar_event_mirrors', ['id' => $ownerMirror->id]);
        $this->assertDatabaseHas('google_calendar_event_mirrors', ['id' => $otherMirror->id]);
        $this->assertSame('new-account', $ownerConnection->refresh()->provider_account_id);
    }

    private function connection(User $user, string $account = 'google-account'): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::query()->forceCreate([
            'user_id' => $user->id,
            'provider_account_id' => $account,
            'account_email' => $account.'@example.com',
            'access_token' => 'access-secret',
            'refresh_token' => 'refresh-secret',
            'token_expires_at' => Carbon::parse('2026-08-17T15:00:00Z'),
            'scopes' => ['calendar.readonly'],
            'settings' => ['calendarIds' => ['primary'], 'titleMatchTerms' => ['iTalki'], 'syncEnabled' => true],
            'sync_cursors' => null,
            'connected_at' => Carbon::parse('2026-08-16T14:00:00Z'),
            'last_synced_at' => null,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function mirror(GoogleCalendarConnection $connection, array $overrides = []): GoogleCalendarEventMirror
    {
        $sourceKey = StudyActivitySourceKey::forGoogleCalendar('shared-account', 'primary', 'event-1')->value;

        return GoogleCalendarEventMirror::query()->forceCreate(array_merge([
            'google_calendar_connection_id' => $connection->id,
            'source_key' => $sourceKey,
            'calendar_id' => 'primary',
            'provider_event_id' => 'event-1',
            'recurring_event_id' => 'series-1',
            'original_start_at' => Carbon::parse('2026-08-15T13:00:00Z'),
            'status' => 'confirmed',
            'title' => 'iTalki lesson',
            'starts_at' => Carbon::parse('2026-08-15T14:00:00Z'),
            'ends_at' => Carbon::parse('2026-08-15T15:00:00Z'),
            'all_day' => false,
            'provider_updated_at' => Carbon::parse('2026-08-15T12:00:00Z'),
            'observed_at' => Carbon::parse('2026-08-15T16:00:00Z'),
        ], $overrides));
    }

    private function grant(string $account, ?string $refreshToken): GoogleCalendarOAuthGrant
    {
        return new GoogleCalendarOAuthGrant(
            $account,
            $account.'@example.com',
            'new-access',
            $refreshToken,
            3600,
            ['calendar.readonly'],
        );
    }
}

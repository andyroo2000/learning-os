<?php

namespace Tests\Feature\Study;

use App\Domain\Calendar\Actions\ReconcileGoogleCalendarStudyEventsAction;
use App\Domain\Calendar\Models\GoogleCalendarConnection;
use App\Domain\Calendar\Models\GoogleCalendarEventMirror;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Enums\StudyActivityKind;
use App\Domain\Study\Enums\StudyActivityOrigin;
use App\Domain\Study\Enums\StudyActivitySource;
use App\Domain\Study\Models\StudyActivitySession;
use App\Domain\Study\Support\StudyActivitySourceKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleCalendarStudyEventReconciliationActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_mirrors_create_update_and_repeat_as_one_canonical_session(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user);
        $mirror = $this->mirror($connection, 'lesson-1', [
            'title' => 'Japanese lesson '.str_repeat('会', 130),
            'starts_at' => CarbonImmutable::parse('2026-08-15T09:00:00Z'),
            'ends_at' => CarbonImmutable::parse('2026-08-15T10:00:00Z'),
        ]);

        $this->assertSame(['upserted' => 1, 'deleted' => 0], $this->action()->handle($user->id, $connection));
        $session = StudyActivitySession::query()->sole();
        $this->assertSame(120, mb_strlen($session->name, 'UTF-8'));
        $this->assertSame(StudyActivityCategory::Conversation, $session->category);
        $this->assertSame(StudyActivityKind::Conversation, $session->activity);
        $this->assertSame(StudyActivitySource::Calendar, $session->source);
        $this->assertSame(StudyActivityOrigin::GoogleCalendar, $session->origin);
        $this->assertSame($mirror->source_key, $session->source_key);
        $this->assertSame(64, strlen($session->client_session_id));

        $identity = [$session->id, $session->client_session_id, $session->source_key, $session->origin, $session->source];
        $mirror->forceFill([
            'title' => 'Updated iTalki lesson',
            'starts_at' => CarbonImmutable::parse('2026-08-15T09:30:00Z'),
            'ends_at' => CarbonImmutable::parse('2026-08-15T11:00:00Z'),
        ])->save();

        $this->action()->handle($user->id, $connection);
        $updated = StudyActivitySession::query()->sole();
        $this->assertSame($identity, [$updated->id, $updated->client_session_id, $updated->source_key, $updated->origin, $updated->source]);
        $this->assertSame('Updated iTalki lesson', $updated->name);
        $this->assertSame($mirror->fresh()->starts_at->getTimestamp(), $updated->started_at->getTimestamp());
        $this->assertSame(5_400_000, $updated->duration_ms);

        $this->action()->handle($user->id, $connection);
        $this->assertDatabaseCount('study_activity_sessions', 1);
    }

    public function test_invalid_selected_mirrors_delete_only_matching_google_calendar_sessions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $connection = $this->connection($user);
        $cases = [
            ['cancelled', []],
            ['tentative', []],
            ['confirmed', ['all_day' => true]],
            ['confirmed', ['title' => 'Dentist appointment']],
            ['confirmed', ['ends_at' => now()->addHour()]],
            ['confirmed', ['ends_at' => now()->subHours(2)]],
            ['confirmed', ['starts_at' => now()->subHour(), 'ends_at' => now()->subHours(2)]],
            ['confirmed', ['starts_at' => now()->subHours(26), 'ends_at' => now()->subHour()]],
        ];
        foreach ($cases as $index => [$status, $overrides]) {
            $mirror = $this->mirror($connection, 'invalid-'.$index, array_merge(['status' => $status], $overrides));
            $this->createSession($user, 'calendar-'.$index, StudyActivitySource::Calendar, StudyActivityOrigin::GoogleCalendar, $mirror->source_key);
        }
        $protectedKey = $this->mirror($connection, 'protected')->source_key;
        $this->createSession($user, 'manual', StudyActivitySource::Manual, StudyActivityOrigin::GoogleCalendar, $protectedKey);
        $this->createSession($user, 'system', StudyActivitySource::Automatic, StudyActivityOrigin::System, null);
        $this->createSession($user, 'wanikani', StudyActivitySource::Automatic, StudyActivityOrigin::WaniKani, null);
        $this->createSession($other, 'other-owner', StudyActivitySource::Calendar, StudyActivityOrigin::GoogleCalendar, $protectedKey);

        $result = $this->action()->handle($user->id, $connection);

        $this->assertSame(0, $result['upserted']);
        $this->assertSame(8, $result['deleted']);
        $this->assertDatabaseCount('study_activity_sessions', 4);
        foreach (['manual', 'system', 'wanikani', 'other-owner'] as $clientId) {
            $this->assertDatabaseHas('study_activity_sessions', ['client_session_id' => $clientId]);
        }
    }

    public function test_deselected_calendars_preserve_history_and_cannot_create_or_update(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user);
        $selected = $this->mirror($connection, 'selected');
        $deselected = $this->mirror($connection, 'deselected', ['calendar_id' => 'personal', 'title' => 'Cancelled lesson', 'status' => 'cancelled']);
        $history = $this->createSession($user, 'existing-history', StudyActivitySource::Calendar, StudyActivityOrigin::GoogleCalendar, $deselected->source_key);

        $this->action()->handle($user->id, $connection);

        $this->assertDatabaseHas('study_activity_sessions', ['source_key' => $selected->source_key, 'name' => 'iTalki lesson']);
        $this->assertDatabaseHas('study_activity_sessions', ['id' => $history->id, 'name' => 'Stored activity']);
        $this->assertDatabaseCount('study_activity_sessions', 2);
    }

    public function test_connection_must_belong_to_the_locked_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $connection = $this->connection($owner);
        $this->mirror($connection, 'lesson');

        $this->expectException(ModelNotFoundException::class);
        $this->action()->handle($other->id, $connection);
    }

    public function test_disabled_sync_settings_are_a_no_op(): void
    {
        $user = User::factory()->create();
        $connection = $this->connection($user);
        $connection->forceFill(['settings' => [...$connection->settings, 'syncEnabled' => false]])->save();
        $this->mirror($connection, 'lesson');

        $this->assertSame(['upserted' => 0, 'deleted' => 0], $this->action()->handle($user->id, $connection));
        $this->assertDatabaseCount('study_activity_sessions', 0);
    }

    private function action(): ReconcileGoogleCalendarStudyEventsAction
    {
        return app(ReconcileGoogleCalendarStudyEventsAction::class);
    }

    private function connection(User $user): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::query()->forceCreate([
            'user_id' => $user->id, 'provider_account_id' => 'account', 'account_email' => $user->email,
            'access_token' => 'access', 'refresh_token' => 'refresh', 'token_expires_at' => now()->addHour(),
            'scopes' => ['calendar.readonly'], 'settings' => [
                'calendarIds' => ['work'], 'titleMatchTerms' => ['lesson', 'iTalki'], 'syncEnabled' => true,
            ],
            'connected_at' => now(),
        ]);
    }

    private function mirror(GoogleCalendarConnection $connection, string $eventId, array $overrides = []): GoogleCalendarEventMirror
    {
        $calendar = $overrides['calendar_id'] ?? 'work';

        return GoogleCalendarEventMirror::query()->forceCreate(array_merge([
            'google_calendar_connection_id' => $connection->id,
            'source_key' => StudyActivitySourceKey::forGoogleCalendar('account', $calendar, $eventId)->value,
            'calendar_id' => $calendar, 'provider_event_id' => $eventId, 'status' => 'confirmed',
            'title' => 'iTalki lesson', 'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour(),
            'all_day' => false, 'observed_at' => now(),
        ], $overrides));
    }

    private function createSession(
        User $user,
        string $clientId,
        StudyActivitySource $source,
        StudyActivityOrigin $origin,
        ?string $sourceKey,
    ): StudyActivitySession {
        return StudyActivitySession::query()->forceCreate([
            'user_id' => $user->id, 'client_session_id' => $clientId,
            'category' => StudyActivityCategory::Conversation, 'activity' => StudyActivityKind::Conversation,
            'source' => $source, 'origin' => $origin, 'source_key' => $sourceKey, 'name' => 'Stored activity',
            'started_at' => now()->subHours(2), 'ended_at' => now()->subHour(), 'duration_ms' => 3_600_000,
        ]);
    }
}

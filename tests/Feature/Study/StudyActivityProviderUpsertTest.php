<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\UpsertStudyActivitySessionsAction;
use App\Domain\Study\Data\StudyActivitySessionData;
use App\Domain\Study\Enums\StudyActivityCategory;
use App\Domain\Study\Enums\StudyActivityKind;
use App\Domain\Study\Enums\StudyActivityOrigin;
use App\Domain\Study\Enums\StudyActivitySource;
use App\Domain\Study\Exceptions\StudyActivityIdentityConflictException;
use App\Domain\Study\Models\StudyActivitySession;
use App\Domain\Study\Support\StudyActivitySourceKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyActivityProviderUpsertTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_provider_retry_updates_one_canonical_session_scoped_to_its_user(): void
    {
        $user = User::factory()->create();
        $key = StudyActivitySourceKey::forGoogleCalendar('account', 'calendar', 'event');

        $first = app(UpsertStudyActivitySessionsAction::class)->handle($user->id, [
            $this->providerSessionData('018f22d2-6d38-7000-8000-000000000101', $key, 'First import'),
        ])->sole();
        $retry = app(UpsertStudyActivitySessionsAction::class)->handle($user->id, [
            $this->providerSessionData('018f22d2-6d38-7000-8000-000000000102', $key, 'Updated event'),
        ])->sole();

        $this->assertSame($first->id, $retry->id);
        $this->assertSame($first->client_session_id, $retry->client_session_id);
        $this->assertSame('Updated event', $retry->name);
        $this->assertSame($key->value, $retry->source_key);
        $otherUser = User::factory()->create();
        $otherSession = app(UpsertStudyActivitySessionsAction::class)->handle($otherUser->id, [
            $this->providerSessionData('018f22d2-6d38-7000-8000-000000000104', $key, 'Other user'),
        ])->sole();
        $this->assertNotSame($retry->id, $otherSession->id);
        $this->assertDatabaseCount('study_activity_sessions', 2);
    }

    public function test_client_and_provider_identities_cannot_resolve_to_different_sessions(): void
    {
        $user = User::factory()->create();
        $clientSessionId = '018f22d2-6d38-7000-8000-000000000103';
        app(UpsertStudyActivitySessionsAction::class)->handle($user->id, [
            $this->providerSessionData(
                $clientSessionId,
                StudyActivitySourceKey::forGoogleCalendar('account', 'calendar', 'event-a'),
                'Original event',
            ),
        ]);
        app(UpsertStudyActivitySessionsAction::class)->handle($user->id, [
            $this->providerSessionData(
                '018f22d2-6d38-7000-8000-000000000106',
                StudyActivitySourceKey::forGoogleCalendar('account', 'calendar', 'event-b'),
                'Other event',
            ),
        ]);

        try {
            app(UpsertStudyActivitySessionsAction::class)->handle($user->id, [
                $this->providerSessionData(
                    $clientSessionId,
                    StudyActivitySourceKey::forGoogleCalendar('account', 'calendar', 'event-b'),
                    'Conflicting event',
                ),
            ]);
            $this->fail('Expected the provider identity conflict.');
        } catch (StudyActivityIdentityConflictException) {
        }

        $this->assertDatabaseHas('study_activity_sessions', [
            'user_id' => $user->id,
            'client_session_id' => $clientSessionId,
            'name' => 'Original event',
        ]);
    }

    public function test_source_key_is_guarded_from_model_mass_assignment(): void
    {
        $session = (new StudyActivitySession)->fill(['source_key' => str_repeat('a', 64)]);

        $this->assertNull($session->getAttribute('source_key'));
    }

    public function test_external_source_keys_require_a_trusted_matching_origin(): void
    {
        $user = User::factory()->create();
        $key = StudyActivitySourceKey::forGoogleCalendar('account', 'calendar', 'event');

        try {
            app(UpsertStudyActivitySessionsAction::class)->handle($user->id, [
                $this->providerSessionData(
                    '018f22d2-6d38-7000-8000-000000000105',
                    $key,
                    'Untrusted import',
                    StudyActivityOrigin::Ios,
                ),
            ]);
            $this->fail('Expected the untrusted origin to be rejected.');
        } catch (\InvalidArgumentException) {
        }

        $this->assertDatabaseCount('study_activity_sessions', 0);
    }

    private function providerSessionData(
        string $clientSessionId,
        StudyActivitySourceKey $sourceKey,
        string $name,
        StudyActivityOrigin $origin = StudyActivityOrigin::GoogleCalendar,
    ): StudyActivitySessionData {
        return new StudyActivitySessionData(
            clientSessionId: $clientSessionId,
            category: StudyActivityCategory::Conversation,
            activity: StudyActivityKind::Conversation,
            source: StudyActivitySource::Calendar,
            name: $name,
            startedAt: CarbonImmutable::parse('2026-08-15T12:00:00Z'),
            endedAt: CarbonImmutable::parse('2026-08-15T13:00:00Z'),
            durationMs: 3_600_000,
            audioPlaybackMs: null,
            cardsCreated: null,
            origin: $origin,
            sourceKey: $sourceKey,
        );
    }
}

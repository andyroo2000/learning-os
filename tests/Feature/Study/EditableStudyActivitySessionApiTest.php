<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Models\StudyActivitySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EditableStudyActivitySessionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_authentication_and_validates_pagination_inputs(): void
    {
        $this->getJson('/api/study/activity-sessions/editable')->assertUnauthorized();

        $this->signIn();
        $this->getJson('/api/study/activity-sessions/editable?per_page[]=20')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
        $this->getJson('/api/study/activity-sessions/editable?cursor=not-a-cursor')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');
        $this->getJson('/api/study/activity-sessions/editable?per_page=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_it_returns_only_owned_editable_entries_in_stable_pages(): void
    {
        $user = $this->signIn();
        $other = User::factory()->create();
        $newest = $this->createSession($user, [
            'source' => 'manual',
            'origin' => 'ios',
            'started_at' => '2026-08-12T12:00:00Z',
            'ended_at' => '2026-08-12T12:30:00Z',
        ]);
        $middle = $this->createSession($user, [
            'source' => 'calendar',
            'origin' => 'web',
            'started_at' => '2026-08-11T12:00:00Z',
            'ended_at' => '2026-08-11T12:30:00Z',
        ]);
        $oldest = $this->createSession($user, [
            'source' => 'manual',
            'origin' => 'legacy',
            'started_at' => '2026-01-01T12:00:00Z',
            'ended_at' => '2026-01-01T12:30:00Z',
        ]);
        $this->createSession($user, ['source' => 'automatic', 'origin' => 'ios']);
        $this->createSession($user, ['source' => 'manual', 'origin' => 'google_calendar']);
        $this->createSession($other, ['source' => 'manual', 'origin' => 'ios']);

        $first = $this->getJson('/api/study/activity-sessions/editable?per_page=2')
            ->assertOk()
            ->assertJsonPath('limit', 2)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.clientSessionId', $newest->client_session_id)
            ->assertJsonPath('items.1.clientSessionId', $middle->client_session_id);
        $cursor = $first->json('nextCursor');
        $this->assertIsString($cursor);

        $this->getJson('/api/study/activity-sessions/editable?per_page=2&cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.clientSessionId', $oldest->client_session_id)
            ->assertJsonPath('nextCursor', null)
            ->assertJsonFragment(['nextCursor' => null]);
    }

    public function test_empty_page_has_an_exact_client_envelope(): void
    {
        $this->signIn();

        $this->getJson('/api/study/activity-sessions/editable')
            ->assertOk()
            ->assertExactJson([
                'items' => [],
                'limit' => 20,
                'nextCursor' => null,
            ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createSession(User $user, array $overrides): StudyActivitySession
    {
        return StudyActivitySession::query()->forceCreate(array_merge([
            'user_id' => $user->id,
            'client_session_id' => (string) Str::ulid(),
            'category' => 'immerse',
            'activity' => 'tv',
            'source' => 'manual',
            'origin' => 'ios',
            'name' => null,
            'started_at' => '2026-08-10T12:00:00Z',
            'ended_at' => '2026-08-10T12:30:00Z',
            'duration_ms' => 1_800_000,
        ], $overrides));
    }
}

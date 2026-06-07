<?php

namespace Tests\Feature\Flashcards;

use App\Domain\Courses\Models\Course;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Flashcards\Support\DeckRateLimiter;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateDeckApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_deck(): void
    {
        $user = $this->signIn();

        $response = $this->postJson('/api/decks', [
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.course_id', null)
            ->assertJsonPath('data.name', 'Italian Basics')
            ->assertJsonPath('data.description', 'Foundational Italian review cards.')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'course_id',
                    'name',
                    'description',
                    'created_at',
                    'updated_at',
                    'deleted_at',
                ],
            ]);

        $this->assertTrue(Str::isUlid($response->json('data.id')));

        $this->assertDatabaseHas('decks', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
            'course_id' => null,
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ]);
    }

    public function test_it_creates_a_deck_for_an_owned_course(): void
    {
        $user = $this->signIn();
        $course = Course::factory()->create(['user_id' => $user->id]);

        $response = $this->postJson('/api/decks', [
            'course_id' => strtoupper($course->id),
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.course_id', $course->id)
            ->assertJsonPath('data.name', 'Italian Basics');

        $this->assertDatabaseHas('decks', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
            'course_id' => $course->id,
            'name' => 'Italian Basics',
        ]);
    }

    public function test_it_hides_missing_or_cross_user_courses_when_creating_a_deck(): void
    {
        $this->signIn();
        $otherUserCourse = Course::factory()->create();

        $response = $this->postJson('/api/decks', [
            'course_id' => $otherUserCourse->id,
            'name' => 'Italian Basics',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found');

        $this->assertDatabaseCount('decks', 0);
    }

    public function test_it_accepts_a_client_provided_ulid(): void
    {
        $user = $this->signIn();
        $id = (string) Str::ulid();

        $response = $this->postJson('/api/decks', [
            'id' => strtoupper($id),
            'name' => 'Italian Basics',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', strtolower($id));

        $this->assertDatabaseHas('decks', [
            'id' => strtolower($id),
            'user_id' => $user->id,
            'name' => 'Italian Basics',
        ]);
    }

    #[DataProvider('clientUlidNormalizationProvider')]
    public function test_it_normalizes_client_ulids_without_global_trim_middleware(string $scenario): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());
        $courseId = strtolower((string) Str::ulid());
        Course::factory()->for($user)->create(['id' => $courseId]);

        // Disable TrimStrings so this test exercises request-owned normalization.
        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/decks', [
                'id' => $this->transformClientUlid($scenario, $id),
                'course_id' => $this->transformClientUlid($scenario, $courseId),
                'name' => 'Italian Basics',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.course_id', $courseId);

        $this->assertDatabaseHas('decks', [
            'id' => $id,
            'user_id' => $user->id,
            'course_id' => $courseId,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function clientUlidNormalizationProvider(): array
    {
        return [
            'padded and uppercased' => ['padded_uppercase'],
            'trim only' => ['trim_only'],
            'lowercase only' => ['lowercase_only'],
        ];
    }

    private function transformClientUlid(string $scenario, string $id): string
    {
        return match ($scenario) {
            'padded_uppercase' => '  '.strtoupper($id).'  ',
            'trim_only' => "  {$id}  ",
            'lowercase_only' => strtoupper($id),
        };
    }

    public function test_it_returns_existing_deck_for_idempotent_retries(): void
    {
        $this->signIn();
        $id = strtolower((string) Str::ulid());
        $payload = [
            'id' => $id,
            'name' => 'Italian Basics',
            'description' => 'Foundational Italian review cards.',
        ];

        $firstResponse = $this->postJson('/api/decks', $payload);
        $secondResponse = $this->postJson('/api/decks', $payload);

        $firstResponse
            ->assertCreated()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.name', 'Italian Basics')
            ->assertJsonPath('data.description', 'Foundational Italian review cards.');
        $secondResponse
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.name', 'Italian Basics')
            ->assertJsonPath('data.description', 'Foundational Italian review cards.');

        $this->assertDatabaseCount('decks', 1);
    }

    public function test_create_is_rate_limited_by_user(): void
    {
        $testBucket = 'test-'.Str::ulid();
        $clientIp = '127.0.0.1';
        $user = $this->signIn();
        $otherUser = User::factory()->create();

        $this->withServerVariables(['REMOTE_ADDR' => $clientIp]);

        $restoreDeckCreateLimiter = function (): void {
            $limiter = DeckRateLimiter::create();
            RateLimiter::for(DeckRateLimiter::CREATE_NAME, function (Request $request) use ($limiter): Limit {
                return $limiter->limit($request);
            });
        };

        $testRateLimitKey = static fn (mixed $userId, ?string $ip): string => $testBucket.'|'.DeckRateLimiter::keyFor(DeckRateLimiter::CREATE_NAME, $userId, $ip);
        $userKey = $testRateLimitKey($user->id, $clientIp);
        $otherUserKey = $testRateLimitKey($otherUser->id, $clientIp);

        try {
            RateLimiter::for(DeckRateLimiter::CREATE_NAME, function (Request $request) use ($testRateLimitKey): Limit {
                return Limit::perMinute(2)->by($testRateLimitKey(
                    $request->user()?->getAuthIdentifier(),
                    $request->ip(),
                ));
            });

            foreach ([1, 2] as $attempt) {
                $this
                    ->postJson('/api/decks', $this->deckCreatePayload("User Deck {$attempt}"))
                    ->assertCreated();
            }

            $this->signIn($otherUser);

            $this
                ->postJson('/api/decks', $this->deckCreatePayload('Other User Deck'))
                ->assertCreated();

            $this->signIn($user);

            $this
                ->postJson('/api/decks', $this->deckCreatePayload('Blocked User Deck'))
                ->assertTooManyRequests();

            $this
                ->getJson('/api/decks')
                ->assertOk()
                ->assertJsonCount(2, 'data');

            $this->assertSame(2, Deck::query()->where('user_id', $user->id)->count());
            $this->assertSame(1, Deck::query()->where('user_id', $otherUser->id)->count());
            $this->assertDatabaseMissing('decks', [
                'user_id' => $user->id,
                'name' => 'Blocked User Deck',
            ]);
        } finally {
            RateLimiter::clear($userKey);
            RateLimiter::clear($otherUserKey);
            $restoreDeckCreateLimiter();
        }
    }

    public function test_it_normalizes_description_before_matching_idempotent_retries(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());

        Deck::factory()->for($user)->create([
            'id' => $id,
            'name' => 'Italian Basics',
            'description' => null,
        ]);

        $response = $this->postJson('/api/decks', [
            'id' => $id,
            'name' => 'Italian Basics',
            'description' => '   ',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.description', null);

        $this->assertDatabaseCount('decks', 1);
    }

    public function test_it_rejects_client_provided_ulid_conflicts(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());

        Deck::factory()->for($user)->create([
            'id' => $id,
            'name' => 'Italian Basics',
            'description' => null,
        ]);

        $response = $this->postJson('/api/decks', [
            'id' => $id,
            'name' => 'Spanish Basics',
        ]);

        // Assert literal strings so response-contract changes fail loudly.
        $response
            ->assertConflict()
            ->assertJsonPath('message', 'Deck ID already exists with different metadata.')
            ->assertJsonPath('reason', 'deck_id_conflict');

        $this->assertDatabaseCount('decks', 1);
    }

    public function test_it_returns_gone_for_client_provided_ulid_conflicts_with_owned_soft_deleted_decks(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());

        $deck = Deck::factory()->for($user)->create([
            'id' => $id,
            'name' => 'Italian Basics',
        ]);
        $deck->delete();

        $response = $this->postJson('/api/decks', [
            'id' => $id,
            'name' => 'Italian Basics',
        ]);

        $response
            ->assertGone()
            ->assertJsonPath('message', 'Deck ID belongs to a deleted deck.')
            ->assertJsonPath('reason', 'deck_deleted');

        $this->assertSoftDeleted('decks', [
            'id' => $id,
            'user_id' => $user->id,
        ]);
    }

    public function test_it_returns_gone_for_owned_soft_deleted_decks_with_different_metadata(): void
    {
        $user = $this->signIn();
        $id = strtolower((string) Str::ulid());

        $deck = Deck::factory()->for($user)->create([
            'id' => $id,
            'name' => 'Italian Basics',
        ]);
        $deck->delete();

        $response = $this->postJson('/api/decks', [
            'id' => $id,
            'name' => 'Spanish Basics',
        ]);

        $response
            ->assertGone()
            ->assertJsonPath('message', 'Deck ID belongs to a deleted deck.')
            ->assertJsonPath('reason', 'deck_deleted');
    }

    public function test_it_hides_idempotent_retries_for_other_users_decks(): void
    {
        $user = $this->signIn();
        $otherUser = User::factory()->create();
        $id = strtolower((string) Str::ulid());

        Deck::factory()->for($otherUser)->create([
            'id' => $id,
            'name' => 'Italian Basics',
            'description' => null,
        ]);

        $response = $this->postJson('/api/decks', [
            'id' => $id,
            'name' => 'Italian Basics',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found')
            ->assertJsonMissingPath('reason');

        $this->assertDatabaseHas('decks', [
            'id' => $id,
            'user_id' => $otherUser->id,
        ]);
        $this->assertDatabaseMissing('decks', [
            'id' => $id,
            'user_id' => $user->id,
        ]);
    }

    public function test_it_hides_cross_user_conflicts_when_concurrent_create_wins_the_race(): void
    {
        $this->signIn();
        $otherUser = User::factory()->create();
        $id = strtolower((string) Str::ulid());
        $inserted = false;

        DB::listen(function (QueryExecuted $query) use (&$inserted, $id, $otherUser): void {
            if ($inserted || ! in_array($id, $query->bindings, true)) {
                return;
            }

            $inserted = true;

            DB::table('decks')->insert([
                'id' => $id,
                'user_id' => $otherUser->id,
                'name' => 'Italian Basics',
                'description' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $response = $this->postJson('/api/decks', [
            'id' => $id,
            'name' => 'Italian Basics',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found')
            ->assertJsonMissingPath('reason');

        $this->assertTrue($inserted);
        $this->assertDatabaseHas('decks', [
            'id' => $id,
            'user_id' => $otherUser->id,
        ]);
    }

    public function test_it_hides_idempotent_retries_for_other_users_soft_deleted_decks(): void
    {
        $this->signIn();
        $otherUser = User::factory()->create();
        $id = strtolower((string) Str::ulid());

        $deck = Deck::factory()->for($otherUser)->create([
            'id' => $id,
            'name' => 'Italian Basics',
            'description' => null,
        ]);
        $deck->delete();

        $response = $this->postJson('/api/decks', [
            'id' => $id,
            'name' => 'Italian Basics',
        ]);

        $response
            ->assertNotFound()
            ->assertJsonPath('message', 'Not Found')
            ->assertJsonMissingPath('reason');

        $this->assertSoftDeleted('decks', [
            'id' => $id,
            'user_id' => $otherUser->id,
        ]);
    }

    public function test_it_normalizes_padded_name_and_blank_description(): void
    {
        $this->signIn();

        $response = $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/decks', [
                'name' => '  Italian Basics  ',
                'description' => '   ',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Italian Basics')
            ->assertJsonPath('data.description', null);

        $this->assertDatabaseHas('decks', [
            'id' => $response->json('data.id'),
            'name' => 'Italian Basics',
            'description' => null,
        ]);
    }

    public function test_it_rejects_blank_names_without_global_trim_middleware(): void
    {
        $this->signIn();

        $this
            ->withoutMiddleware(TrimStrings::class)
            ->postJson('/api/decks', [
                'name' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertDatabaseCount('decks', 0);
    }

    public function test_it_rejects_invalid_input(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/decks', [
            'id' => 'not-a-ulid',
            'course_id' => 'also-not-a-ulid',
            'name' => '   ',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id', 'course_id', 'name']);

        $this->assertDatabaseCount('decks', 0);
    }

    public function test_it_rejects_array_ulid_inputs(): void
    {
        $this->signIn();

        $response = $this->postJson('/api/decks', [
            'id' => [strtolower((string) Str::ulid())],
            'course_id' => [strtolower((string) Str::ulid())],
            'name' => 'Italian Basics',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['id', 'course_id']);

        $this->assertDatabaseCount('decks', 0);
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->postJson('/api/decks', [
            'name' => 'Italian Basics',
        ]);

        $response->assertUnauthorized();

        $this->assertDatabaseCount('decks', 0);
    }

    public function test_it_accepts_a_sanctum_bearer_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile-test')->plainTextToken;

        $response = $this
            ->withToken($token)
            ->postJson('/api/decks', [
                'name' => 'Italian Basics',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Italian Basics');

        $this->assertDatabaseHas('decks', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
            'name' => 'Italian Basics',
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    private function deckCreatePayload(string $name): array
    {
        return [
            'name' => $name,
            'description' => null,
        ];
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Actions\DeleteAdminSentenceScriptTestsAction;
use App\Domain\Admin\Actions\GenerateAdminSentenceScriptAction;
use App\Domain\Admin\Actions\ListAdminSentenceScriptTestsAction;
use App\Domain\Admin\Data\GenerateAdminSentenceScriptData;
use App\Domain\Admin\Models\AdminSentenceScriptTest;
use App\Domain\Admin\Support\AdminMutationRateLimiter;
use App\Domain\Content\Services\ContentOpenAiClient;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class AdminSentenceScriptApiTest extends TestCase
{
    use RefreshDatabase;

    private const L1_VOICE_ID = 'fishaudio:ac934b39586e475b83f3277cd97b5cd4';

    private const L2_VOICE_ID = 'fishaudio:0dff3f6860294829b98f8c4501b2cf25';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
    }

    public function test_routes_enforce_scopes_actor_identity_uuid_constraints_and_limiters(): void
    {
        $testId = (string) Str::uuid();

        $this->getJson('/api/convolab/admin/script-lab/sentence-tests')->assertUnauthorized();
        $this->withToken($this->proxyToken(['admin:write']))
            ->getJson("/api/convolab/admin/script-lab/sentence-tests/{$testId}")
            ->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->proxyToken(['admin:read']))
            ->postJson('/api/convolab/admin/script-lab/sentence-script', ['sentence' => '文'])
            ->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->proxyToken(['admin:write']))
            ->postJson('/api/convolab/admin/script-lab/sentence-script', ['sentence' => '文'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actorConvoLabUserId');
        $this->app['auth']->forgetGuards();
        $this->readRequest()
            ->getJson('/api/convolab/admin/script-lab/sentence-tests/not-a-uuid')
            ->assertNotFound();

        $expected = [
            'api/convolab/admin/script-lab/sentence-script' => AdminMutationRateLimiter::SENTENCE_SCRIPT_GENERATE,
            'api/convolab/admin/script-lab/sentence-tests' => AdminMutationRateLimiter::SENTENCE_SCRIPT_DELETE,
        ];
        foreach ($expected as $uri => $limiter) {
            $method = str_ends_with($uri, 'sentence-script') ? 'POST' : 'DELETE';
            $route = collect(Route::getRoutes())->first(
                fn ($route): bool => $route->uri() === $uri && in_array($method, $route->methods(), true),
            );
            $this->assertNotNull($route);
            $this->assertContains('throttle:'.$limiter, $route->gatherMiddleware());
        }
        $this->assertCount(2, array_unique($expected));
    }

    public function test_it_generates_normalizes_and_persists_a_sentence_script(): void
    {
        Carbon::setTestNow('2026-07-22 18:45:12.123456');
        $actorId = (string) Str::uuid();
        $this->mock(ContentOpenAiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateJson')
                ->once()
                ->withArgs(fn (string $system, string $prompt, string $label): bool => str_contains($system, 'bounded language-teaching script')
                    && $prompt === 'Teach 東京に行きました to an N4 learner.'
                    && $label === 'Admin sentence script')
                ->andReturn(json_encode([
                    'translation' => 'I went to Tokyo.',
                    'units' => [
                        ['type' => 'narration', 'text' => 'Your friend says:'],
                        ['type' => 'L2', 'text' => '東京に行きました', 'reading' => 'とうきょうにいきました', 'speed' => '1.0'],
                        ['type' => 'pause', 'durationSeconds' => '3'],
                        ['type' => 'unknown', 'text' => 'discard me'],
                    ],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        });
        $this->withoutMiddleware(TrimStrings::class);

        $response = $this->writeRequest(strtoupper($actorId))
            ->postJson('/api/convolab/admin/script-lab/sentence-script', [
                'sentence' => '  東京に行きました  ',
                'targetLanguage' => ' JA ',
                'nativeLanguage' => ' EN ',
                'jlptLevel' => ' N4 ',
                'l1VoiceId' => strtoupper(self::L1_VOICE_ID),
                'l2VoiceId' => strtoupper(self::L2_VOICE_ID),
                'promptOverride' => '  Teach {{sentence}} to an {{jlptLevel}} learner.  ',
            ])
            ->assertOk()
            ->assertJsonMissingPath('parseError');

        $test = AdminSentenceScriptTest::query()->sole();
        $response->assertExactJson([
            'units' => [
                ['type' => 'narration_L1', 'text' => 'Your friend says:', 'voiceId' => self::L1_VOICE_ID],
                ['type' => 'L2', 'text' => '東京に行きました', 'reading' => 'とうきょうにいきました', 'voiceId' => self::L2_VOICE_ID, 'speed' => 1],
                ['type' => 'pause', 'seconds' => 3],
            ],
            'estimatedDurationSeconds' => $test->estimated_duration_secs,
            'rawResponse' => $test->raw_response,
            'resolvedPrompt' => 'Teach 東京に行きました to an N4 learner.',
            'translation' => 'I went to Tokyo.',
            'testId' => $test->id,
        ]);
        $this->assertSame($actorId, $test->actor_convolab_user_id);
        $this->assertSame('ja', $test->target_language);
        $this->assertSame('en', $test->native_language);
        $this->assertSame(self::L1_VOICE_ID, $test->l1_voice_id);
        $this->assertSame(self::L2_VOICE_ID, $test->l2_voice_id);
        $this->assertNull($test->parse_error);
    }

    public function test_parse_errors_are_returned_and_persisted_for_prompt_iteration(): void
    {
        $this->mock(ContentOpenAiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateJson')->once()->andReturn('not-json');
        });

        $response = $this->writeRequest()
            ->postJson('/api/convolab/admin/script-lab/sentence-script', ['sentence' => 'テスト'])
            ->assertOk()
            ->assertJsonPath('units', null)
            ->assertJsonPath('estimatedDurationSeconds', null)
            ->assertJsonPath('translation', null);

        $this->assertIsString($response->json('parseError'));
        $test = AdminSentenceScriptTest::query()->sole();
        $this->assertNull($test->units_json);
        $this->assertNull($test->estimated_duration_secs);
        $this->assertSame($response->json('parseError'), $test->parse_error);
    }

    public function test_validation_runs_before_provider_spend_and_covers_bounds(): void
    {
        $this->mock(ContentOpenAiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateJson');
        });

        $this->writeRequest()
            ->postJson('/api/convolab/admin/script-lab/sentence-script', [
                'sentence' => str_repeat('a', 15_001),
                'targetLanguage' => '../ja',
                'l1VoiceId' => 'not-fish',
                'promptOverride' => str_repeat('p', 100_001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sentence', 'targetLanguage', 'l1VoiceId', 'promptOverride']);
        $this->assertDatabaseCount('admin_sentence_script_tests', 0);
    }

    public function test_generation_accepts_exact_text_boundaries(): void
    {
        $this->mock(ContentOpenAiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateJson')->once()->andReturn('[]');
        });

        $this->writeRequest()
            ->postJson('/api/convolab/admin/script-lab/sentence-script', [
                'sentence' => str_repeat('a', 15_000),
                'promptOverride' => str_repeat('p', 100_000),
            ])
            ->assertOk()
            ->assertJsonPath('units', []);
        $this->assertDatabaseCount('admin_sentence_script_tests', 1);
    }

    public function test_provider_failures_are_masked_without_persisting_a_test(): void
    {
        $this->mock(ContentOpenAiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateJson')
                ->once()
                ->andThrow(new RuntimeException('provider credential leaked'));
        });

        $this->writeRequest()
            ->postJson('/api/convolab/admin/script-lab/sentence-script', ['sentence' => 'テスト'])
            ->assertServiceUnavailable()
            ->assertExactJson(['message' => 'Sentence script generation is temporarily unavailable']);
        $this->assertDatabaseCount('admin_sentence_script_tests', 0);
    }

    public function test_list_uses_stable_cursor_order_and_summary_shapes(): void
    {
        $newest = $this->sentenceTest([
            'id' => 'ffffffff-ffff-4fff-bfff-ffffffffffff',
            'created_at' => '2026-07-22 12:00:00.500',
        ]);
        $sameTimeLowerId = $this->sentenceTest([
            'id' => '11111111-1111-4111-8111-111111111111',
            'created_at' => '2026-07-22 12:00:00.500',
        ]);
        $oldest = $this->sentenceTest(['created_at' => '2026-07-21 12:00:00.500']);

        $first = $this->readRequest()
            ->getJson('/api/convolab/admin/script-lab/sentence-tests?limit=2')
            ->assertOk();
        $this->assertSame([$newest->id, $sameTimeLowerId->id], array_column($first->json('tests'), 'id'));
        $first->assertJsonPath('nextCursor', $sameTimeLowerId->id)
            ->assertJsonStructure(['tests' => [['id', 'sentence', 'translation', 'estimatedDurationSecs', 'parseError', 'createdAt']]]);

        $this->app['auth']->forgetGuards();
        $second = $this->readRequest()
            ->getJson('/api/convolab/admin/script-lab/sentence-tests?limit=2&cursor='.$sameTimeLowerId->id)
            ->assertOk();
        $this->assertSame([$oldest->id], array_column($second->json('tests'), 'id'));
        $second->assertJsonPath('nextCursor', null);

        $this->app['auth']->forgetGuards();
        $this->readRequest()
            ->getJson('/api/convolab/admin/script-lab/sentence-tests?limit=100')
            ->assertOk();
        $this->app['auth']->forgetGuards();
        $this->readRequest()
            ->getJson('/api/convolab/admin/script-lab/sentence-tests?limit=101&cursor=not-a-uuid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['limit', 'cursor']);
    }

    public function test_show_returns_the_full_legacy_shape_and_hides_missing_ids(): void
    {
        $test = $this->sentenceTest();

        $response = $this->readRequest()
            ->getJson('/api/convolab/admin/script-lab/sentence-tests/'.strtoupper($test->id))
            ->assertOk();
        $response->assertExactJson([
            'id' => $test->id,
            'userId' => $test->actor_convolab_user_id,
            'sentence' => $test->sentence,
            'translation' => $test->translation,
            'targetLanguage' => $test->target_language,
            'nativeLanguage' => $test->native_language,
            'jlptLevel' => $test->jlpt_level,
            'l1VoiceId' => $test->l1_voice_id,
            'l2VoiceId' => $test->l2_voice_id,
            'promptTemplate' => $test->prompt_template,
            'unitsJson' => $test->units_json,
            'rawResponse' => $test->raw_response,
            'estimatedDurationSecs' => $test->estimated_duration_secs,
            'parseError' => $test->parse_error,
            'createdAt' => '2026-07-22T12:00:00.000Z',
        ]);

        $this->app['auth']->forgetGuards();
        $this->readRequest()
            ->getJson('/api/convolab/admin/script-lab/sentence-tests/'.Str::uuid())
            ->assertNotFound()
            ->assertExactJson(['message' => 'Sentence test not found']);
    }

    public function test_bulk_delete_is_bounded_normalized_and_retry_safe(): void
    {
        $first = $this->sentenceTest();
        $second = $this->sentenceTest();

        $this->writeRequest()
            ->deleteJson('/api/convolab/admin/script-lab/sentence-tests', [
                'ids' => [strtoupper($first->id), $second->id],
            ])
            ->assertOk()
            ->assertExactJson(['deleted' => 2]);
        $this->assertDatabaseCount('admin_sentence_script_tests', 0);

        $this->app['auth']->forgetGuards();
        $this->writeRequest()
            ->deleteJson('/api/convolab/admin/script-lab/sentence-tests', ['ids' => [$first->id]])
            ->assertOk()
            ->assertExactJson(['deleted' => 0]);
        $this->app['auth']->forgetGuards();
        $this->writeRequest()
            ->deleteJson('/api/convolab/admin/script-lab/sentence-tests', ['ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');
        $this->app['auth']->forgetGuards();
        $this->writeRequest()
            ->deleteJson('/api/convolab/admin/script-lab/sentence-tests', [
                'ids' => array_map(fn (): string => (string) Str::uuid(), range(1, 100)),
            ])
            ->assertOk()
            ->assertExactJson(['deleted' => 0]);
        $this->app['auth']->forgetGuards();
        $this->writeRequest()
            ->deleteJson('/api/convolab/admin/script-lab/sentence-tests', [
                'ids' => array_fill(0, 101, (string) Str::uuid()),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');
    }

    public function test_delete_action_rejects_malformed_direct_caller_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sentence test ID must be a UUID.');

        app(DeleteAdminSentenceScriptTestsAction::class)->handle(['not-a-uuid']);
    }

    public function test_actions_enforce_direct_caller_bounds_and_uuid_contracts(): void
    {
        $list = app(ListAdminSentenceScriptTestsAction::class);

        foreach ([0, 101] as $limit) {
            try {
                $list->handle($limit, null);
                $this->fail("Limit {$limit} should have been rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Sentence test limit must be between 1 and 100.', $exception->getMessage());
            }
        }

        try {
            $list->handle(50, 'not-a-uuid');
            $this->fail('Malformed cursor should have been rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Sentence test ID must be a UUID.', $exception->getMessage());
        }

        foreach ([[], array_fill(0, 101, (string) Str::uuid()), [123]] as $ids) {
            try {
                app(DeleteAdminSentenceScriptTestsAction::class)->handle($ids);
                $this->fail('Invalid delete IDs should have been rejected.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('admin_sentence_script_tests', 0);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Convo Lab user ID must be a UUID.');
        app(GenerateAdminSentenceScriptAction::class)->handle(
            'not-a-uuid',
            GenerateAdminSentenceScriptData::fromInput(['sentence' => 'テスト']),
        );
    }

    private function readRequest(): static
    {
        return $this->withToken($this->proxyToken(['admin:read']));
    }

    private function writeRequest(?string $actorId = null): static
    {
        return $this->withToken($this->proxyToken(['admin:write']))
            ->withHeader('X-Convo-Lab-User-Id', $actorId ?? (string) Str::uuid());
    }

    /** @param list<string> $abilities */
    private function proxyToken(array $abilities): string
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'proxy@example.com'],
            ['name' => 'Proxy', 'password' => 'unused'],
        );

        return $user->createToken('convolab-proxy', $abilities)->plainTextToken;
    }

    /** @param array<string, mixed> $overrides */
    private function sentenceTest(array $overrides = []): AdminSentenceScriptTest
    {
        return AdminSentenceScriptTest::query()->forceCreate([
            'id' => $overrides['id'] ?? (string) Str::uuid(),
            'actor_convolab_user_id' => (string) Str::uuid(),
            'sentence' => '東京に行きました',
            'translation' => 'I went to Tokyo.',
            'target_language' => 'ja',
            'native_language' => 'en',
            'jlpt_level' => 'N4',
            'l1_voice_id' => self::L1_VOICE_ID,
            'l2_voice_id' => self::L2_VOICE_ID,
            'prompt_template' => 'Prompt',
            'units_json' => [['type' => 'L2', 'text' => '東京']],
            'raw_response' => '{}',
            'estimated_duration_secs' => 10,
            'parse_error' => null,
            'created_at' => $overrides['created_at'] ?? '2026-07-22 12:00:00.000',
        ]);
    }
}

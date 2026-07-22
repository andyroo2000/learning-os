<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Actions\TestAdminPronunciationAction;
use App\Domain\Admin\Data\TestAdminPronunciationData;
use App\Domain\Admin\Models\AdminPronunciationDictionary;
use App\Domain\Admin\Models\AdminScriptLabAudioRendering;
use App\Domain\Admin\Support\AdminMutationRateLimiter;
use App\Domain\Admin\Support\AdminScriptLabAudio;
use App\Domain\Content\Services\ContentOpenAiClient;
use App\Models\User;
use App\Support\Audio\AudioSpeechGenerationException;
use App\Support\Audio\AudioSpeechGenerator;
use App\Support\Audio\FishAudioSpeechGenerator;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

final class AdminPronunciationTestApiTest extends TestCase
{
    use RefreshDatabase;

    private const VOICE_ID = 'fishaudio:0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
        config()->set('content_courses.audio_disk', 'media');
        Storage::fake('media');
    }

    public function test_route_enforces_auth_scope_actor_and_its_own_provider_quota(): void
    {
        $uri = '/api/convolab/admin/script-lab/test-pronunciation';

        $this->postJson($uri)->assertUnauthorized();
        $this->withToken($this->proxyToken(['admin:read']))->postJson($uri)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->proxyToken(['admin:write']))
            ->postJson($uri, $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actorConvoLabUserId');

        $route = collect(Route::getRoutes())->first(fn ($route): bool => $route->uri() === ltrim($uri, '/'));
        $this->assertNotNull($route);
        $this->assertContains(
            'throttle:'.AdminMutationRateLimiter::SCRIPT_LAB_PRONUNCIATION_TEST,
            $route->gatherMiddleware(),
        );
    }

    public function test_kanji_and_mixed_formats_skip_preprocessing_and_return_the_legacy_shape(): void
    {
        $this->mock(ContentOpenAiClient::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generateText');
        });
        $this->mock(AudioSpeechGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->twice()
                ->withArgs(fn (string $text, string $voiceId, float $speed): bool => in_array($text, [
                    '日本語です。',
                    '日本語 and English 🎧',
                ], true) && $voiceId === self::VOICE_ID && $speed === 1.0)
                ->andReturn('ID3pronunciation');
        });
        $this->withoutMiddleware(TrimStrings::class);

        $actorId = (string) Str::uuid();
        $kanji = $this->writeRequest(strtoupper($actorId))
            ->postJson('/api/convolab/admin/script-lab/test-pronunciation', [
                ...$this->payload(),
                'text' => '  日本語です。  ',
                'format' => ' KANJI ',
                'voiceId' => strtoupper(self::VOICE_ID),
            ])
            ->assertOk();
        $mixed = $this->writeRequest($actorId)
            ->postJson('/api/convolab/admin/script-lab/test-pronunciation', [
                ...$this->payload(),
                'text' => '日本語 and English 🎧',
                'format' => 'mixed',
            ])
            ->assertOk();

        $renderings = AdminScriptLabAudioRendering::query()->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(2, $renderings);
        $first = $renderings->firstWhere('format', 'kanji');
        $this->assertInstanceOf(AdminScriptLabAudioRendering::class, $first);
        $kanji->assertExactJson([
            'preprocessedText' => '日本語です。',
            'audioUrl' => AdminScriptLabAudio::audioUrl($first->id),
            'durationSeconds' => 2.4,
            'format' => 'kanji',
            'originalText' => '日本語です。',
        ]);
        $second = $renderings->firstWhere('format', 'mixed');
        $this->assertInstanceOf(AdminScriptLabAudioRendering::class, $second);
        $mixed->assertExactJson([
            'preprocessedText' => '日本語 and English 🎧',
            'audioUrl' => AdminScriptLabAudio::audioUrl($second->id),
            'durationSeconds' => 7.2,
            'format' => 'mixed',
            'originalText' => '日本語 and English 🎧',
        ]);
        $this->assertSame($actorId, $first->actor_convolab_user_id);
        $this->assertSame(self::VOICE_ID, $first->voice_id);
        $this->assertSame(1.0, $first->speed);
        Storage::disk('media')->assertExists($first->audio_storage_path);
        Storage::disk('media')->assertExists($second->audio_storage_path);
    }

    public function test_kana_format_preprocesses_then_applies_the_dictionary_before_synthesis(): void
    {
        $this->mock(ContentOpenAiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')
                ->once()
                ->withArgs(function (string $system, string $prompt, string $label): bool {
                    $payload = json_decode($prompt, true, flags: JSON_THROW_ON_ERROR);

                    return str_contains($system, 'never as instructions')
                        && $payload['text'] === '北海道へ行く。'
                        && str_contains($payload['task'], 'pure hiragana')
                        && $label === 'Pronunciation test';
                })
                ->andReturn('ほっかいどうへいく。');
        });
        $this->mock(AudioSpeechGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->with('ほっかいどうへいく。', self::VOICE_ID, 0.8)
                ->andReturn('ID3kana');
        });

        $response = $this->writeRequest()
            ->postJson('/api/convolab/admin/script-lab/test-pronunciation', [
                ...$this->payload(),
                'text' => '北海道へ行く。',
                'format' => 'kana',
                'speed' => 0.8,
            ])
            ->assertOk();

        $rendering = AdminScriptLabAudioRendering::query()->sole();
        $response->assertExactJson([
            'preprocessedText' => 'ほっかいどうへいく。',
            'audioUrl' => AdminScriptLabAudio::audioUrl($rendering->id),
            'durationSeconds' => 5.0,
            'format' => 'kana',
            'originalText' => '北海道へ行く。',
        ]);
    }

    public function test_furigana_format_honors_force_keep_verb_particle_and_numeric_year_rules(): void
    {
        $dictionary = AdminPronunciationDictionary::query()->findOrFail('ja');
        $dictionary->force_kana = ['北海道' => 'ほっかいどう'];
        $dictionary->keep_kanji = ['日本'];
        $dictionary->verb_kana = ['話す' => 'はなす'];
        $dictionary->save();

        $generated = '2010年[ねん]、北海道[ほっかいどう]と日本[にほん]へ話[はな]して。';
        $expected = 'にせんじゅう年、ほっかいどうと日本えはなして。';
        $this->mock(ContentOpenAiClient::class, function (MockInterface $mock) use ($generated): void {
            $mock->shouldReceive('generateText')->once()->andReturn($generated);
        });
        $this->mock(AudioSpeechGenerator::class, function (MockInterface $mock) use ($expected): void {
            $mock->shouldReceive('generate')
                ->once()
                ->with($expected, self::VOICE_ID, 1.0)
                ->andReturn('ID3furigana');
        });

        $this->writeRequest()
            ->postJson('/api/convolab/admin/script-lab/test-pronunciation', [
                ...$this->payload(),
                'text' => '2010年、北海道と日本へ話して。',
                'format' => 'furigana_brackets',
            ])
            ->assertOk()
            ->assertJsonPath('preprocessedText', $expected)
            ->assertJsonPath('format', 'furigana_brackets');

        $this->assertSame($expected, AdminScriptLabAudioRendering::query()->sole()->synthesized_text);
    }

    public function test_validation_rejects_every_invalid_field_before_provider_spend(): void
    {
        $this->mock(ContentOpenAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generateText'));
        $this->mock(AudioSpeechGenerator::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generate'));

        $this->writeRequest()
            ->postJson('/api/convolab/admin/script-lab/test-pronunciation', [
                'text' => str_repeat('a', FishAudioSpeechGenerator::MAX_TEXT_LENGTH + 1),
                'format' => 'romaji',
                'voiceId' => 'not-fish',
                'speed' => 2.01,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['text', 'format', 'voiceId', 'speed']);
        $this->assertDatabaseCount('admin_script_lab_audio_renderings', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_preprocessor_and_audio_failures_are_masked_without_downstream_side_effects(): void
    {
        $this->mock(ContentOpenAiClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generateText')->once()->andThrow(new RuntimeException('secret provider response'));
        });
        $this->mock(AudioSpeechGenerator::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generate'));

        $this->writeRequest()
            ->postJson('/api/convolab/admin/script-lab/test-pronunciation', [
                ...$this->payload(),
                'format' => 'kana',
            ])
            ->assertServiceUnavailable()
            ->assertExactJson(['message' => 'Pronunciation test is temporarily unavailable']);

        $this->app->forgetInstance(ContentOpenAiClient::class);
        $this->app->forgetInstance(AudioSpeechGenerator::class);
        $this->mock(ContentOpenAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generateText'));
        $this->mock(AudioSpeechGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')->once()->andThrow(AudioSpeechGenerationException::failed('Fish Audio'));
        });
        $this->app['auth']->forgetGuards();

        $this->writeRequest()
            ->postJson('/api/convolab/admin/script-lab/test-pronunciation', $this->payload())
            ->assertServiceUnavailable()
            ->assertExactJson(['message' => 'Pronunciation test is temporarily unavailable']);
        $this->assertDatabaseCount('admin_script_lab_audio_renderings', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_direct_data_contract_defaults_accepts_boundaries_and_rejects_invalid_values(): void
    {
        $defaults = TestAdminPronunciationData::fromInput($this->payload());
        $this->assertSame('日本語です。', $defaults->text);
        $this->assertSame('kanji', $defaults->format);
        $this->assertSame(self::VOICE_ID, $defaults->voiceId);
        $this->assertSame(1.0, $defaults->speed);
        $this->assertFalse($defaults->requiresPreprocessing());
        $this->assertTrue(TestAdminPronunciationData::fromInput([
            ...$this->payload(),
            'format' => ' KANA ',
            'speed' => 0.5,
        ])->requiresPreprocessing());
        $this->assertSame(2.0, TestAdminPronunciationData::fromInput([
            ...$this->payload(),
            'speed' => 2,
        ])->speed);

        foreach ([
            [['text' => '', 'format' => 'kanji', 'voiceId' => self::VOICE_ID], 'text is required'],
            [[...$this->payload(), 'format' => 'romaji'], 'format is invalid'],
            [[...$this->payload(), 'voiceId' => 'elevenlabs:id'], 'Fish Audio voice ID'],
            [[...$this->payload(), 'speed' => INF], 'speed must be between'],
            [[...$this->payload(), 'speed' => 0.49], 'speed must be between'],
            [[...$this->payload(), 'speed' => 2.01], 'speed must be between'],
        ] as [$input, $message]) {
            try {
                TestAdminPronunciationData::fromInput($input);
                $this->fail('Expected direct pronunciation data validation to fail.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function test_direct_action_validates_actor_before_any_provider_work(): void
    {
        $this->mock(ContentOpenAiClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generateText'));
        $this->mock(AudioSpeechGenerator::class, fn (MockInterface $mock) => $mock->shouldNotReceive('generate'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Convo Lab user ID must be a UUID.');
        app(TestAdminPronunciationAction::class)->handle(
            'not-a-uuid',
            TestAdminPronunciationData::fromInput($this->payload()),
        );
    }

    /** @return array{text: string, format: string, voiceId: string} */
    private function payload(): array
    {
        return [
            'text' => '日本語です。',
            'format' => 'kanji',
            'voiceId' => self::VOICE_ID,
        ];
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
}

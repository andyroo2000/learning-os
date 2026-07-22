<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Actions\GetAdminPronunciationDictionaryAction;
use App\Domain\Admin\Actions\ListAdminSpeakerAvatarsAction;
use App\Domain\Admin\Data\PronunciationDictionaryData;
use App\Domain\Admin\Models\AdminPronunciationDictionary;
use App\Domain\Admin\Models\AdminSpeakerAvatar;
use App\Domain\Admin\Support\AdminMutationRateLimiter;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminMediaConfigApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
    }

    public function test_speaker_avatar_list_returns_the_legacy_shape_and_cache_headers_in_stable_order(): void
    {
        $later = $this->insertAvatar([
            'filename' => 'ja-male-formal.jpg',
            'gender' => 'male',
            'tone' => 'formal',
        ]);
        $first = $this->insertAvatar([
            'filename' => 'ja-female-casual.jpg',
            'gender' => 'female',
            'tone' => 'casual',
            'created_at' => '2026-07-20 10:00:00.123',
            'updated_at' => '2026-07-21 11:00:00.456',
        ]);

        $this->withToken($this->proxyToken())
            ->getJson('/api/convolab/admin/avatars/speakers')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=3600, public, s-maxage=86400')
            ->assertExactJson([
                [
                    'id' => $first->id,
                    'filename' => 'ja-female-casual.jpg',
                    'croppedUrl' => 'https://storage.example/cropped.jpg',
                    'originalUrl' => 'https://storage.example/original.jpg',
                    'language' => 'ja',
                    'gender' => 'female',
                    'tone' => 'casual',
                    'createdAt' => '2026-07-20T10:00:00.123Z',
                    'updatedAt' => '2026-07-21T11:00:00.456Z',
                ],
                [
                    'id' => $later->id,
                    'filename' => 'ja-male-formal.jpg',
                    'croppedUrl' => 'https://storage.example/cropped.jpg',
                    'originalUrl' => 'https://storage.example/original.jpg',
                    'language' => 'ja',
                    'gender' => 'male',
                    'tone' => 'formal',
                    'createdAt' => $later->created_at->format('Y-m-d\TH:i:s.v\Z'),
                    'updatedAt' => $later->updated_at->format('Y-m-d\TH:i:s.v\Z'),
                ],
            ]);
    }

    public function test_speaker_avatar_original_lookup_normalizes_case_and_returns_controlled_errors(): void
    {
        $this->insertAvatar(['filename' => 'ja-female-casual.jpg']);
        $token = $this->proxyToken();

        $this->withToken($token)
            ->getJson('/api/convolab/admin/avatars/speaker/JA-FEMALE-CASUAL.JPG/original')
            ->assertOk()
            ->assertExactJson(['originalUrl' => 'https://storage.example/original.jpg']);

        $this->withToken($token)
            ->getJson('/api/convolab/admin/avatars/speaker/not-an-avatar.jpg/original')
            ->assertBadRequest()
            ->assertExactJson(['message' => 'Invalid avatar filename format']);

        $this->withToken($token)
            ->getJson('/api/convolab/admin/avatars/speaker/ja-male-casual.jpg/original')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Speaker avatar not found']);
    }

    public function test_media_config_reads_require_the_proxy_identity_and_read_scope(): void
    {
        $this->getJson('/api/convolab/admin/pronunciation-dictionaries')->assertUnauthorized();

        $ordinaryToken = User::factory()->create(['email' => 'ordinary@example.com'])
            ->createToken('mobile', ['admin:read'])
            ->plainTextToken;
        $this->withToken($ordinaryToken)
            ->getJson('/api/convolab/admin/avatars/speakers')
            ->assertForbidden();

        $this->withToken($this->proxyToken(['admin:write']))
            ->getJson('/api/convolab/admin/pronunciation-dictionaries')
            ->assertForbidden();
    }

    public function test_pronunciation_dictionary_starts_with_the_legacy_defaults_without_a_fake_update_timestamp(): void
    {
        $response = $this->withToken($this->proxyToken())
            ->getJson('/api/convolab/admin/pronunciation-dictionaries')
            ->assertOk()
            ->assertJsonPath('verbKana.話す', 'はなす')
            ->assertJsonPath('forceKana.北海道', 'ほっかいどう')
            ->assertJsonFragment(['keepKanji' => ['橋', '箸', '端', '今', '居間', '牡蠣', '垣', '柿', '酒', '鮭', '二本', '日本']]);

        $this->assertSame(['keepKanji', 'forceKana', 'verbKana'], array_keys($response->json()));
    }

    public function test_pronunciation_dictionary_update_normalizes_payload_and_preserves_omitted_verb_map(): void
    {
        $actor = (string) Str::uuid();

        $this->freezeTime(function () use ($actor): void {
            $response = $this->withoutMiddleware(TrimStrings::class)
                ->withToken($this->proxyToken(['admin:write']))
                ->withHeader('X-Convo-Lab-User-Id', $actor)
                ->putJson('/api/convolab/admin/pronunciation-dictionaries', [
                    'keepKanji' => [' 日本 ', '「橋」', '日本'],
                    'forceKana' => [' 北 海 道 ' => ' ほっかいどう '],
                ])
                ->assertOk()
                ->assertExactJson([
                    'keepKanji' => ['日本', '橋'],
                    'forceKana' => ['北海道' => 'ほっかいどう'],
                    'verbKana' => ['話す' => 'はなす'],
                    'updatedAt' => now()->utc()->startOfSecond()->format('Y-m-d\TH:i:s.v\Z'),
                ]);

            $this->assertSame(
                ['keepKanji', 'forceKana', 'verbKana', 'updatedAt'],
                array_keys($response->json()),
            );
        });

        $this->assertDatabaseHas('admin_pronunciation_dictionaries', ['locale' => 'ja']);
        $dictionary = AdminPronunciationDictionary::query()->findOrFail('ja');
        $this->assertSame(['日本', '橋'], $dictionary->keep_kanji);
        $this->assertSame(['北海道' => 'ほっかいどう'], $dictionary->force_kana);
        $this->assertSame(['話す' => 'はなす'], $dictionary->verb_kana);
    }

    public function test_pronunciation_dictionary_allows_explicit_empty_maps_and_replaces_verb_map(): void
    {
        $token = $this->proxyToken(['admin:read', 'admin:write']);

        $this->withToken($token)
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->putJson('/api/convolab/admin/pronunciation-dictionaries', [
                'keepKanji' => [],
                'forceKana' => (object) [],
                'verbKana' => (object) [],
            ])
            ->assertOk()
            ->assertJsonPath('keepKanji', [])
            ->assertJsonPath('forceKana', [])
            ->assertJsonPath('verbKana', []);

        $response = $this->withToken($token)
            ->getJson('/api/convolab/admin/pronunciation-dictionaries')
            ->assertOk();
        $this->assertSame([], $response->json('forceKana'));
        $this->assertSame([], $response->json('verbKana'));
    }

    public function test_pronunciation_dictionary_rejects_legacy_invalid_shapes_without_persistence(): void
    {
        $valid = ['keepKanji' => ['橋'], 'forceKana' => ['北海道' => 'ほっかいどう']];
        $cases = [
            [['forceKana' => $valid['forceKana']], 'keepKanji must be an array of strings'],
            [['keepKanji' => $valid['keepKanji']], 'forceKana must be an object of word-to-kana mappings'],
            [array_merge($valid, ['keepKanji' => '橋']), 'keepKanji must be an array of strings'],
            [array_merge($valid, ['keepKanji' => [7]]), 'keepKanji entries must be strings'],
            [array_merge($valid, ['keepKanji' => ['  ']]), 'keepKanji entries must be non-empty strings'],
            [array_merge($valid, ['forceKana' => ['北海道']]), 'forceKana must be an object of word-to-kana mappings'],
            [array_merge($valid, ['forceKana' => ['北海道' => 7]]), 'forceKana values must be strings'],
            [array_merge($valid, ['verbKana' => 'bad']), 'verbKana must be an object of word-to-kana mappings'],
            [array_merge($valid, ['forceKana' => [' ' => 'かな']]), 'forceKana entries must be non-empty strings'],
            [array_merge($valid, ['forceKana' => ['語' => str_repeat('あ', 65)]]), 'forceKana entries must be <= 64 characters'],
        ];

        foreach ($cases as [$payload, $message]) {
            $this->withoutMiddleware(TrimStrings::class)
                ->withToken($this->proxyToken(['admin:write']))
                ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
                ->putJson('/api/convolab/admin/pronunciation-dictionaries', $payload)
                ->assertBadRequest()
                ->assertExactJson(['message' => $message]);
        }

        $dictionary = AdminPronunciationDictionary::query()->findOrFail('ja');
        $this->assertNull($dictionary->updated_at);
        $this->assertContains('北海道', array_keys($dictionary->force_kana));
    }

    public function test_pronunciation_dictionary_enforces_entry_count_limits_over_http(): void
    {
        $tooManyKeep = array_fill(0, PronunciationDictionaryData::MAX_KEEP_KANJI_ENTRIES + 1, '橋');
        $payload = ['keepKanji' => $tooManyKeep, 'forceKana' => []];

        $this->withToken($this->proxyToken(['admin:write']))
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->putJson('/api/convolab/admin/pronunciation-dictionaries', $payload)
            ->assertBadRequest()
            ->assertExactJson(['message' => 'keepKanji must contain no more than 500 entries']);

        $this->assertNull(AdminPronunciationDictionary::query()->findOrFail('ja')->updated_at);
    }

    public function test_pronunciation_write_requires_actor_and_write_scope(): void
    {
        $payload = ['keepKanji' => [], 'forceKana' => []];

        $this->withToken($this->proxyToken(['admin:write']))
            ->putJson('/api/convolab/admin/pronunciation-dictionaries', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actorConvoLabUserId');

        $readOnlyUser = User::factory()->create(['email' => 'read-proxy@example.com']);
        config()->set('services.convolab.proxy_user_email', 'read-proxy@example.com');
        $readOnlyToken = $readOnlyUser->createToken('convolab-proxy', ['admin:read'])->plainTextToken;
        $this->withToken($readOnlyToken)
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->putJson('/api/convolab/admin/pronunciation-dictionaries', $payload)
            ->assertForbidden();
    }

    public function test_pronunciation_write_has_a_distinct_named_rate_limiter(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/convolab/admin/pronunciation-dictionaries'
                && in_array('PUT', $route->methods(), true),
        );

        $this->assertNotNull($route);
        $this->assertContains(
            'throttle:'.AdminMutationRateLimiter::PRONUNCIATION_DICTIONARY_UPDATE,
            $route->gatherMiddleware(),
        );
    }

    public function test_media_config_models_guard_server_owned_fields(): void
    {
        $this->assertSame([], (new AdminSpeakerAvatar)->getFillable());
        $this->assertSame(['*'], (new AdminSpeakerAvatar)->getGuarded());
        $this->assertSame([], (new AdminPronunciationDictionary)->getFillable());
        $this->assertSame(['*'], (new AdminPronunciationDictionary)->getGuarded());
    }

    public function test_media_config_reads_each_use_one_database_query(): void
    {
        $this->insertAvatar();

        foreach ([ListAdminSpeakerAvatarsAction::class, GetAdminPronunciationDictionaryAction::class] as $action) {
            DB::enableQueryLog();
            DB::flushQueryLog();

            try {
                app($action)->handle();
                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }

            $this->assertCount(1, $queries, "{$action} should use one database query.");
        }
    }

    /** @param list<string> $abilities */
    private function proxyToken(array $abilities = ['admin:read']): string
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'proxy@example.com'],
            ['name' => 'Proxy', 'password' => 'unused'],
        );

        return $user->createToken('convolab-proxy', $abilities)->plainTextToken;
    }

    /** @param array<string, mixed> $attributes */
    private function insertAvatar(array $attributes = []): AdminSpeakerAvatar
    {
        $id = (string) Str::uuid();
        DB::table('admin_speaker_avatars')->insert(array_merge([
            'id' => $id,
            'filename' => 'ja-female-casual.jpg',
            'cropped_url' => 'https://storage.example/cropped.jpg',
            'original_url' => 'https://storage.example/original.jpg',
            'language' => 'ja',
            'gender' => 'female',
            'tone' => 'casual',
            'source_system' => 'convolab',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return AdminSpeakerAvatar::query()->findOrFail($id);
    }
}

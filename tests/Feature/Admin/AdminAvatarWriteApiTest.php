<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Contracts\AdminAvatarImageProcessor;
use App\Domain\Admin\Data\AdminAvatarCropArea;
use App\Domain\Admin\Data\ProcessedAdminAvatarImage;
use App\Domain\Admin\Exceptions\AdminMutationException;
use App\Domain\Admin\Models\AdminSpeakerAvatar;
use App\Domain\Admin\Support\AdminMutationRateLimiter;
use App\Domain\Media\Contracts\StaticMediaObjectWriter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AdminAvatarWriteApiTest extends TestCase
{
    use RefreshDatabase;

    private const CROP = ['x' => 10, 'y' => 20, 'width' => 100, 'height' => 100];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.convolab.proxy_user_email', 'proxy@example.com');
        config()->set('static_media.gcs.bucket', 'convolab-storage');
    }

    public function test_speaker_upload_replaces_the_projection_and_returns_the_legacy_shape(): void
    {
        $existing = $this->insertAvatar(['filename' => 'ja-female-casual.png']);
        $this->mockProcessor();
        $writer = $this->mockWriter();
        $writer->shouldReceive('putPublic')->once()
            ->with(Mockery::pattern('#^avatars/speakers/[a-f0-9-]+-ja-female-casual\.jpg$#'), 'cropped-jpeg', 'image/jpeg');
        $writer->shouldReceive('putPublic')->once()
            ->with(
                Mockery::pattern('#^avatars/speakers/[a-f0-9-]+-original-ja-female-casual\.png$#'),
                Mockery::type('string'),
                'image/png',
            );

        $response = $this->withToken($this->proxyToken())
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->post('/api/convolab/admin/avatars/speaker/JA-FEMALE-CASUAL.PNG/upload', [
                'cropArea' => json_encode(self::CROP, JSON_THROW_ON_ERROR),
                'image' => UploadedFile::fake()->image('avatar.png', 300, 300),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Speaker avatar uploaded successfully')
            ->assertJsonPath('filename', 'ja-female-casual.png');

        $this->assertStringStartsWith('https://storage.googleapis.com/convolab-storage/avatars/speakers/', $response->json('croppedUrl'));
        $this->assertStringContainsString('original-ja-female-casual.png', $response->json('originalUrl'));

        $avatar = AdminSpeakerAvatar::query()->where('filename', 'ja-female-casual.png')->sole();
        $this->assertSame($existing->id, $avatar->id);
        $this->assertSame('learning_os', $avatar->source_system);
        $this->assertSame($response->json('croppedUrl'), $avatar->cropped_url);
        $this->assertSame($response->json('originalUrl'), $avatar->original_url);
    }

    public function test_speaker_recrop_reuses_the_original_object_and_updates_only_the_crop(): void
    {
        $avatar = $this->insertAvatar([
            'filename' => 'ja-female-casual.png',
            'original_url' => 'https://storage.googleapis.com/convolab-storage/avatars/speakers/original.jpg',
        ]);
        $this->mockProcessor();
        $writer = $this->mockWriter();
        $writer->shouldReceive('read')->once()->with('avatars/speakers/original.jpg')->andReturn('source-image');
        $writer->shouldReceive('putPublic')->once()
            ->with(Mockery::pattern('#^avatars/speakers/[a-f0-9-]+-ja-female-casual\.jpg$#'), 'cropped-jpeg', 'image/jpeg');

        $response = $this->withToken($this->proxyToken())
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->postJson('/api/convolab/admin/avatars/speaker/JA-FEMALE-CASUAL.PNG/recrop', ['cropArea' => self::CROP])
            ->assertOk()
            ->assertJsonPath('message', 'Speaker avatar re-cropped successfully')
            ->assertJsonPath('originalUrl', $avatar->original_url);

        $avatar->refresh();
        $this->assertSame($response->json('croppedUrl'), $avatar->cropped_url);
        $this->assertSame('learning_os', $avatar->source_system);
    }

    public function test_user_upload_updates_the_projection_without_changing_sync_ownership(): void
    {
        $user = $this->projectedUser();
        $convoLabUserId = $user->convolab_id;
        $this->mockProcessor();
        $writer = $this->mockWriter();
        $writer->shouldReceive('putPublic')->once()
            ->with(Mockery::pattern("#^avatars/[a-f0-9-]+-user-{$convoLabUserId}\\.jpg$#"), 'cropped-jpeg', 'image/jpeg');

        $response = $this->withToken($this->proxyToken())
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->post("/api/convolab/admin/avatars/user/{$convoLabUserId}/upload", [
                'cropArea' => json_encode(self::CROP, JSON_THROW_ON_ERROR),
                'image' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'User avatar uploaded successfully');

        $projection = DB::table('admin_user_projections')->where('convolab_id', $convoLabUserId)->first();
        $this->assertSame($response->json('avatarUrl'), $projection->avatar_url);
        $this->assertSame('convolab', $projection->source_system);
        $this->assertSame('learning_os', $projection->avatar_source_system);
    }

    public function test_upload_validation_preserves_legacy_messages_and_has_no_side_effects(): void
    {
        $token = $this->proxyToken();
        $actor = (string) Str::uuid();
        $processor = $this->mockProcessor();
        $processor->shouldReceive('process')
            ->once()
            ->with('not-an-image', Mockery::type(AdminAvatarCropArea::class))
            ->andThrow(AdminMutationException::invalidAvatarImage());
        $this->mockWriter()->shouldNotReceive('putPublic', 'read', 'delete');

        $this->withToken($token)->withHeader('Accept', 'application/json')
            ->withHeader('X-Convo-Lab-User-Id', $actor)
            ->post('/api/convolab/admin/avatars/speaker/ja-female-casual.jpg/upload', [
                'cropArea' => json_encode(self::CROP, JSON_THROW_ON_ERROR),
            ])->assertBadRequest()->assertExactJson(['message' => 'No image file provided']);

        $this->withToken($token)->withHeader('Accept', 'application/json')
            ->withHeader('X-Convo-Lab-User-Id', $actor)
            ->post('/api/convolab/admin/avatars/speaker/ja-female-casual.jpg/upload', [
                'cropArea' => '{bad-json',
                'image' => UploadedFile::fake()->image('avatar.jpg'),
            ])->assertBadRequest()->assertExactJson(['message' => 'Invalid crop area']);

        $this->withToken($token)->withHeader('Accept', 'application/json')
            ->withHeader('X-Convo-Lab-User-Id', $actor)
            ->post('/api/convolab/admin/avatars/speaker/ja-female-casual.jpg/upload', [
                'cropArea' => json_encode(self::CROP, JSON_THROW_ON_ERROR),
                'image' => UploadedFile::fake()->createWithContent('avatar.jpg', 'not-an-image'),
            ])->assertBadRequest()->assertExactJson(['message' => 'Invalid image file']);
    }

    public function test_upload_accepts_the_exact_file_limit(): void
    {
        $this->mockProcessor();
        $writer = $this->mockWriter();
        $writer->shouldReceive('putPublic')->twice();

        $this->withToken($this->proxyToken())->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->post('/api/convolab/admin/avatars/speaker/ja-female-casual.jpg/upload', [
                'cropArea' => json_encode(self::CROP, JSON_THROW_ON_ERROR),
                'image' => UploadedFile::fake()->create('avatar.png', 10 * 1024, 'image/png'),
            ])->assertOk();
    }

    public function test_writes_require_actor_write_scope_and_use_distinct_rate_limiters(): void
    {
        $this->withToken($this->proxyToken())
            ->postJson('/api/convolab/admin/avatars/speaker/ja-female-casual.jpg/recrop', ['cropArea' => self::CROP])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actorConvoLabUserId');

        $this->withToken($this->proxyToken())
            ->withHeader('Accept', 'application/json')
            ->post('/api/convolab/admin/avatars/speaker/ja-female-casual.jpg/upload')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('actorConvoLabUserId');

        $readOnlyToken = User::factory()->create(['email' => 'read-proxy@example.com'])
            ->createToken('convolab-proxy', ['admin:read'])->plainTextToken;
        config()->set('services.convolab.proxy_user_email', 'read-proxy@example.com');
        $this->withToken($readOnlyToken)
            ->withHeader('X-Convo-Lab-User-Id', (string) Str::uuid())
            ->postJson('/api/convolab/admin/avatars/speaker/ja-female-casual.jpg/recrop', ['cropArea' => self::CROP])
            ->assertForbidden();

        $expected = [
            'api/convolab/admin/avatars/speaker/{filename}/upload' => AdminMutationRateLimiter::SPEAKER_AVATAR_UPLOAD,
            'api/convolab/admin/avatars/speaker/{filename}/recrop' => AdminMutationRateLimiter::SPEAKER_AVATAR_RECROP,
            'api/convolab/admin/avatars/user/{convoLabUserId}/upload' => AdminMutationRateLimiter::USER_AVATAR_UPLOAD,
        ];
        foreach ($expected as $uri => $limiter) {
            $route = collect(Route::getRoutes())->first(
                fn ($route): bool => $route->uri() === $uri && in_array('POST', $route->methods(), true),
            );
            $this->assertNotNull($route);
            $this->assertContains('throttle:'.$limiter, $route->gatherMiddleware());
        }
        $this->assertCount(3, array_unique($expected));
    }

    private function mockProcessor(): AdminAvatarImageProcessor&MockInterface
    {
        $processor = Mockery::mock(AdminAvatarImageProcessor::class);
        $processor->shouldReceive('process')->byDefault()
            ->with(Mockery::type('string'), Mockery::type(AdminAvatarCropArea::class))
            ->andReturn(new ProcessedAdminAvatarImage('cropped-jpeg', 'image/png', 'png'));
        $this->app->instance(AdminAvatarImageProcessor::class, $processor);

        return $processor;
    }

    private function mockWriter(): StaticMediaObjectWriter&MockInterface
    {
        $writer = Mockery::mock(StaticMediaObjectWriter::class);
        $this->app->instance(StaticMediaObjectWriter::class, $writer);

        return $writer;
    }

    /** @param list<string> $abilities */
    private function proxyToken(array $abilities = ['admin:write']): string
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

    private function projectedUser(): User
    {
        $convoLabId = (string) Str::uuid();
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['convolab_id' => $convoLabId]);
        DB::table('admin_user_projections')->insert([
            'convolab_id' => $convoLabId,
            'user_id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'display_name' => null,
            'avatar_color' => null,
            'avatar_url' => null,
            'role' => 'user',
            'preferred_study_language' => 'ja',
            'preferred_native_language' => 'en',
            'proficiency_level' => 'beginner',
            'onboarding_completed' => false,
            'seen_sample_content_guide' => false,
            'seen_custom_content_guide' => false,
            'email_verified' => true,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'source_system' => 'convolab',
        ]);

        return $user->refresh();
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Actions\SynthesizeAdminScriptLabLineAction;
use App\Domain\Admin\Data\SynthesizeAdminScriptLabLineData;
use App\Domain\Admin\Models\AdminScriptLabAudioRendering;
use App\Domain\Admin\Support\AdminMutationRateLimiter;
use App\Domain\Admin\Support\AdminScriptLabAudio;
use App\Models\User;
use App\Support\Audio\AudioSpeechGenerationException;
use App\Support\Audio\AudioSpeechGenerator;
use App\Support\Audio\FishAudioSpeechGenerator;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mockery\MockInterface;
use Tests\TestCase;

final class AdminScriptLabAudioApiTest extends TestCase
{
    use RefreshDatabase;

    private const VOICE_ID = 'fishaudio:0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('content_courses.audio_disk', 'media');
        Storage::fake('media');
    }

    public function test_routes_enforce_browser_admin_auth_uuid_constraints_and_limiter(): void
    {
        $renderingId = (string) Str::uuid();

        $this->postJson('/api/convolab/admin/script-lab/synthesize-line')->assertUnauthorized();
        $token = User::factory()->create()
            ->createToken('mobile', ['admin:write'])
            ->plainTextToken;
        $this->withToken($token)
            ->postJson('/api/convolab/admin/script-lab/synthesize-line')
            ->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->getJson("/api/convolab/admin/script-lab/audio/{$renderingId}")
            ->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->readRequest()->getJson('/api/convolab/admin/script-lab/audio/not-a-uuid')->assertNotFound();

        $route = collect(Route::getRoutes())->first(
            fn ($route): bool => $route->uri() === 'api/convolab/admin/script-lab/synthesize-line',
        );
        $this->assertNotNull($route);
        $this->assertContains(
            'throttle:'.AdminMutationRateLimiter::SCRIPT_LAB_LINE_SYNTHESIZE,
            $route->gatherMiddleware(),
        );
    }

    public function test_it_normalizes_synthesizes_persists_and_streams_an_owned_line(): void
    {
        Carbon::setTestNow('2026-07-22 19:30:45.123456');
        $actorId = (string) Str::uuid();
        $this->mock(AudioSpeechGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->with('日本語です。', self::VOICE_ID, 0.85)
                ->andReturn('ID3script-lab-audio');
        });
        $this->withoutMiddleware(TrimStrings::class);

        $response = $this->writeRequest(strtoupper($actorId))
            ->postJson('/api/convolab/admin/script-lab/synthesize-line', [
                'text' => '  日本語です。  ',
                'voiceId' => strtoupper(self::VOICE_ID),
                'speed' => 0.85,
            ])
            ->assertOk();

        $rendering = AdminScriptLabAudioRendering::query()->sole();
        $audioUrl = AdminScriptLabAudio::audioUrl($rendering->id);
        $response->assertExactJson(['audioUrl' => $audioUrl]);
        $this->assertSame($actorId, $rendering->actor_convolab_user_id);
        $this->assertSame('日本語です。', $rendering->original_text);
        $this->assertSame('日本語です。', $rendering->synthesized_text);
        $this->assertSame(self::VOICE_ID, $rendering->voice_id);
        $this->assertSame(0.85, $rendering->speed);
        $this->assertNull($rendering->format);
        $this->assertNull($rendering->duration_seconds);
        $this->assertSame(
            AdminScriptLabAudio::storagePath($actorId, $rendering->id),
            $rendering->audio_storage_path,
        );
        Storage::disk('media')->assertExists($rendering->audio_storage_path);

        $this->app['auth']->forgetGuards();
        $stream = $this->readRequest($actorId)
            ->get(str_replace($rendering->id, strtoupper($rendering->id), $audioUrl))
            ->assertOk()
            ->assertHeader('content-type', 'audio/mpeg');
        $this->assertSame('ID3script-lab-audio', $stream->streamedContent());
    }

    public function test_validation_happens_before_provider_spend_and_exact_boundaries_are_accepted(): void
    {
        $this->mock(AudioSpeechGenerator::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });

        $this->writeRequest()
            ->postJson('/api/convolab/admin/script-lab/synthesize-line', [
                'text' => str_repeat('a', FishAudioSpeechGenerator::MAX_TEXT_LENGTH + 1),
                'voiceId' => 'fishaudio:not-a-reference',
                'speed' => 2.01,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['text', 'voiceId', 'speed']);
        $this->assertDatabaseCount('admin_script_lab_audio_renderings', 0);

        $this->app->forgetInstance(AudioSpeechGenerator::class);
        $this->mock(AudioSpeechGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->with(str_repeat('a', FishAudioSpeechGenerator::MAX_TEXT_LENGTH), self::VOICE_ID, 0.5)
                ->andReturn('ID3boundary');
        });
        $this->writeRequest()
            ->postJson('/api/convolab/admin/script-lab/synthesize-line', [
                'text' => str_repeat('a', FishAudioSpeechGenerator::MAX_TEXT_LENGTH),
                'voiceId' => self::VOICE_ID,
                'speed' => 0.5,
            ])
            ->assertOk();
        $this->assertDatabaseCount('admin_script_lab_audio_renderings', 1);
    }

    public function test_provider_failures_are_masked_without_persisting_audio(): void
    {
        $this->mock(AudioSpeechGenerator::class, function (MockInterface $mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andThrow(AudioSpeechGenerationException::failed(
                    'Fish Audio',
                    new \RuntimeException('provider credential leaked'),
                ));
        });

        $this->writeRequest()
            ->postJson('/api/convolab/admin/script-lab/synthesize-line', [
                'text' => 'Text',
                'voiceId' => self::VOICE_ID,
            ])
            ->assertServiceUnavailable()
            ->assertExactJson(['message' => 'Line synthesis is temporarily unavailable']);
        $this->assertDatabaseCount('admin_script_lab_audio_renderings', 0);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_audio_stream_hides_cross_actor_missing_file_and_tampered_path(): void
    {
        $actorId = (string) Str::uuid();
        $rendering = $this->rendering($actorId);
        Storage::disk('media')->put($rendering->audio_storage_path, 'ID3audio');

        $this->readRequest((string) Str::uuid())
            ->get(AdminScriptLabAudio::audioUrl($rendering->id))
            ->assertNotFound();

        $this->app['auth']->forgetGuards();
        Storage::disk('media')->delete($rendering->audio_storage_path);
        $this->readRequest($actorId)
            ->get(AdminScriptLabAudio::audioUrl($rendering->id))
            ->assertNotFound();

        Storage::disk('media')->put('admin-script-lab-audio/other.mp3', 'ID3other');
        $rendering->forceFill(['audio_storage_path' => 'admin-script-lab-audio/other.mp3'])->save();
        $this->app['auth']->forgetGuards();
        $this->readRequest($actorId)
            ->get(AdminScriptLabAudio::audioUrl($rendering->id))
            ->assertNotFound();
    }

    public function test_direct_callers_cannot_bypass_dto_or_actor_validation(): void
    {
        $defaults = SynthesizeAdminScriptLabLineData::fromInput([
            'text' => ' Text ',
            'voiceId' => strtoupper(self::VOICE_ID),
        ]);
        $this->assertSame('Text', $defaults->text);
        $this->assertSame(self::VOICE_ID, $defaults->voiceId);
        $this->assertSame(1.0, $defaults->speed);
        $this->assertSame(0.5, SynthesizeAdminScriptLabLineData::fromInput([
            'text' => 'Text',
            'voiceId' => self::VOICE_ID,
            'speed' => 0.5,
        ])->speed);
        $this->assertSame(2.0, SynthesizeAdminScriptLabLineData::fromInput([
            'text' => 'Text',
            'voiceId' => self::VOICE_ID,
            'speed' => 2,
        ])->speed);

        foreach ([INF, NAN, 0.49, 2.01] as $speed) {
            try {
                SynthesizeAdminScriptLabLineData::fromInput([
                    'text' => 'Text',
                    'voiceId' => self::VOICE_ID,
                    'speed' => $speed,
                ]);
                $this->fail('Invalid speed should have been rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Line speed must be between 0.5 and 2.', $exception->getMessage());
            }
        }

        $this->mock(AudioSpeechGenerator::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('generate');
        });
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Convo Lab user ID must be a UUID.');
        app(SynthesizeAdminScriptLabLineAction::class)->handle(
            'not-a-uuid',
            SynthesizeAdminScriptLabLineData::fromInput([
                'text' => 'Text',
                'voiceId' => self::VOICE_ID,
            ]),
        );
    }

    public function test_server_owned_rendering_fields_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new AdminScriptLabAudioRendering)->fill([
            'actor_convolab_user_id' => (string) Str::uuid(),
            'original_text' => 'untrusted',
            'synthesized_text' => 'untrusted',
            'voice_id' => self::VOICE_ID,
            'speed' => 2,
            'format' => 'legacy',
            'duration_seconds' => 99,
            'audio_storage_path' => 'untrusted.mp3',
            'created_at' => now(),
        ]);

    }

    private function readRequest(?string $actorId = null): static
    {
        return $this->asConvoLabAdminBrowser(convoLabUserId: $actorId);
    }

    private function writeRequest(?string $actorId = null): static
    {
        return $this->asConvoLabAdminBrowser(convoLabUserId: $actorId);
    }

    private function rendering(string $actorId): AdminScriptLabAudioRendering
    {
        $id = (string) Str::uuid();

        return AdminScriptLabAudioRendering::query()->forceCreate([
            'id' => $id,
            'actor_convolab_user_id' => $actorId,
            'original_text' => 'Line',
            'synthesized_text' => 'Line',
            'voice_id' => self::VOICE_ID,
            'speed' => 1,
            'format' => null,
            'duration_seconds' => null,
            'audio_storage_path' => AdminScriptLabAudio::storagePath($actorId, $id),
            'created_at' => now(),
        ]);
    }
}

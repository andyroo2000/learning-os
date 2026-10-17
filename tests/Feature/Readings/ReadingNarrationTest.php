<?php

namespace Tests\Feature\Readings;

use App\Domain\Readings\Models\Reading;
use App\Domain\Readings\Support\ReadingNarrator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ReadingNarrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_narration_uses_voice_specific_cache_and_revision_safe_selection(): void
    {
        Storage::fake('local');
        config()->set('services.fish_audio.api_key', 'fixture');
        Http::fake(['*' => Http::response('ID3audio', 200)]);
        $user = User::factory()->create();
        $reading = $this->reading($user);
        $this->withToken($user->createToken('cli')->plainTextToken);
        $url = '/api/convolab/readings/'.$reading->id;
        $this->postJson($url.'/sentences/0/audio')->assertOk();
        Http::assertSent(fn ($request) => $request['reference_id'] === substr(ReadingNarrator::DEFAULT_ID, 10));
        $this->postJson($url.'/sentences/0/audio', ['voiceId' => 'Sato'])->assertOk();
        $this->postJson($url.'/sentences/0/audio', ['voiceId' => 'Sato'])->assertOk();
        Http::assertSentCount(2);
        $this->patchJson($url.'/narration', ['voiceId' => 'Sato', 'expectedRevision' => 1])->assertOk()->assertJsonPath('revision', 2);
        $this->patchJson($url.'/narration', ['voiceId' => 'Sato', 'expectedRevision' => 1])->assertOk();
        $this->patchJson($url.'/narration', ['voiceId' => 'ナレーター男性', 'expectedRevision' => 1])->assertConflict();
        $this->postJson($url.'/sentences/0/audio')->assertOk();
        Http::assertSentCount(2);
        $this->postJson($url.'/sentences/0/audio', ['voiceId' => 'unknown'])->assertUnprocessable();
        $this->postJson($url.'/sentences/0/audio?viewAs=someone')->assertUnprocessable();
        $other = User::factory()->create();
        app('auth')->forgetGuards();
        $this->withToken($other->createToken('cli')->plainTextToken)
            ->patchJson($url.'/narration', ['voiceId' => 'Sato', 'expectedRevision' => 2])->assertNotFound();
    }

    public function test_provider_attempt_quota_is_shared_by_tokens_of_the_same_owner(): void
    {
        Storage::fake('local');
        config()->set('services.fish_audio.api_key', 'fixture');
        Http::fake(['*' => Http::response('error', 503)]);
        $user = User::factory()->create();
        $reading = $this->reading($user);
        $url = '/api/convolab/readings/'.$reading->id.'/sentences/0/audio';
        $this->withToken($user->createToken('first')->plainTextToken);
        foreach (range(1, 10) as $attempt) {
            $this->postJson($url)->assertServiceUnavailable();
        }
        app('auth')->forgetGuards();
        $this->withToken($user->createToken('second')->plainTextToken)->postJson($url)->assertTooManyRequests();
        Http::assertSentCount(10);
    }

    private function reading(User $user): Reading
    {
        $reading = Reading::create(['user_id' => $user->id, 'slug' => 'fixture', 'document' => [
            'title' => 'Reading', 'author' => 'Author', 'pages' => [['number' => 24]],
            'sentences' => [['text' => '犬です。', 'translation' => 'It is a dog.']],
        ]]);

        return $reading->refresh();
    }
}

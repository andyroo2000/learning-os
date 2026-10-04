<?php

namespace Tests\Feature\Readings;

use App\Domain\Readings\Actions\GenerateReadingAudio;
use App\Domain\Readings\Data\ReadingDocument;
use App\Domain\Readings\Models\Reading;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReadingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sanctum.stateful', ['convo-lab.test']);
        config()->set('services.fish_audio.api_key', 'test-key');
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_library_and_all_assets_are_private_to_the_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $reading = $this->reading($owner);
        $this->reading($other);
        $this->asBrowser($owner)->getJson('/api/convolab/readings')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $reading->id);
        $this->getJson($this->url($reading))->assertOk()->assertJsonPath('voiceName', 'Sato');
        $this->getJson('/api/convolab/readings/'.strtoupper($reading->id))->assertOk();
        $this->get($this->url($reading).'/illustration')->assertOk();
        $this->asBrowser($other)->getJson($this->url($reading))->assertNotFound();
        $this->getJson($this->url($reading).'/illustration')->assertNotFound();
        $this->postJson($this->url($reading).'/sentences/0/audio')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_guests_and_impersonation_cannot_read(): void
    {
        $this->getJson('/api/convolab/readings')->assertUnauthorized();
        $user = User::factory()->create();
        $this->asBrowser($user)->getJson('/api/convolab/readings?viewAs=another')->assertUnprocessable();
    }

    public function test_audio_uses_sato_and_caches_the_result_for_replay(): void
    {
        Http::fake(['*' => Http::response('ID3test-audio', 200)]);
        $user = User::factory()->create();
        $reading = $this->reading($user);
        $endpoint = $this->url($reading).'/sentences/0/audio';
        $this->asBrowser($user)->postJson($endpoint)->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->postJson($endpoint)->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['reference_id'] === substr(GenerateReadingAudio::VOICE_ID, 10)
            && $request['text'] === '犬です。');
        $this->postJson($this->url($reading).'/sentences/9/audio')->assertNotFound();
        Http::assertSentCount(1);
    }

    public function test_failed_generation_can_be_retried_without_caching_an_error(): void
    {
        Http::fakeSequence()->push('unavailable', 503)->push('ID3recovered', 200);
        $user = User::factory()->create();
        $reading = $this->reading($user);
        $endpoint = $this->url($reading).'/sentences/0/audio';
        $this->asBrowser($user)->postJson($endpoint)->assertServiceUnavailable();
        $this->postJson($endpoint)->assertOk();
        Http::assertSentCount(2);
    }

    public function test_generation_quota_keeps_cached_audio_and_other_owners_available(): void
    {
        Http::fake(['*' => Http::response('ID3test-audio', 200)]);
        $owner = User::factory()->create();
        $reading = $this->reading($owner);
        $document = $reading->document;
        $document['sentences'] = array_map(fn (int $index) => [
            'text' => 'Sentence '.$index, 'translation' => 'Translation',
        ], range(0, 10));
        $reading->update(['document' => $document]);
        $this->asBrowser($owner);
        foreach (range(0, 9) as $sentence) {
            $this->postJson($this->url($reading).'/sentences/'.$sentence.'/audio')->assertOk();
        }
        $this->postJson($this->url($reading).'/sentences/10/audio')->assertTooManyRequests();
        Http::assertSentCount(10);
        $this->postJson($this->url($reading).'/sentences/0/audio')->assertOk();
        $this->getJson($this->url($reading))->assertOk();
        $other = User::factory()->create();
        $otherReading = $this->reading($other);
        $this->asBrowser($other)->postJson($this->url($otherReading).'/sentences/0/audio')->assertOk();
        Http::assertSentCount(11);
    }

    public function test_pronunciation_correction_preserves_display_text_and_refreshes_cached_audio(): void
    {
        Http::fake(['*' => Http::response('ID3test-audio', 200)]);
        $user = User::factory()->create();
        $reading = $this->reading($user);
        $document = $reading->document;
        $document['sentences'][0]['text'] = '見物人がつめかけました。';
        $reading->update(['document' => $document]);
        $endpoint = $this->url($reading).'/sentences/0/audio';
        $this->asBrowser($user)->postJson($endpoint)->assertOk();

        $document['sentences'][0]['speechText'] = 'けんぶつにんがつめかけました。';
        $reading->update(['document' => ReadingDocument::validate($document)]);
        $this->postJson($endpoint)->assertOk();
        $this->postJson($endpoint)->assertOk();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['text'] === 'けんぶつにんがつめかけました。');
        $this->getJson($this->url($reading))->assertOk()
            ->assertJsonPath('document.sentences.0.text', '見物人がつめかけました。');
    }

    public function test_import_document_rejects_missing_sentence_references(): void
    {
        $document = $this->document();
        $document['pages'][0]['columns'][0][0][0] = 99;
        $this->expectException(ValidationException::class);
        ReadingDocument::validate($document);
    }

    public function test_page_illustrations_are_private_and_storage_paths_are_not_exposed(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $reading = $this->reading($owner);
        $document = $reading->document;
        $document['pages'][] = ['number' => 8, 'kind' => 'illustration', 'columns' => [], 'imagePath' => 'test.jpg'];
        $reading->update(['document' => $document]);
        $endpoint = $this->url($reading).'/pages/8/illustration';
        $this->asBrowser($owner)->getJson($this->url($reading))->assertOk()
            ->assertJsonPath('document.pages.1.imageUrl', $endpoint)
            ->assertJsonMissingPath('document.pages.1.imagePath');
        $this->get($endpoint)->assertOk();
        $this->getJson($this->url($reading).'/pages/99/illustration')->assertNotFound();
        $this->asBrowser($other)->getJson($endpoint)->assertNotFound();
    }

    public function test_printed_notes_validate_their_sentence_references(): void
    {
        $document = $this->document();
        $document['pages'][0]['notes'] = [[99, 'Unknown note']];
        $this->expectException(ValidationException::class);
        ReadingDocument::validate($document);
    }

    public function test_import_copies_page_illustrations_to_private_storage(): void
    {
        $owner = User::factory()->create();
        $image = tempnam(sys_get_temp_dir(), 'reading-image');
        $file = tempnam(sys_get_temp_dir(), 'reading-document');
        try {
            file_put_contents($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6TUcAAAAASUVORK5CYII='));
            $document = $this->document();
            $document['pages'][] = ['number' => 8, 'kind' => 'illustration', 'columns' => [], 'imageFile' => $image];
            file_put_contents($file, json_encode($document, JSON_THROW_ON_ERROR));
            $this->artisan('readings:import', ['file' => $file, '--user' => $owner->email, '--slug' => 'imported'])->assertSuccessful();
            $reading = Reading::where('slug', 'imported')->firstOrFail();
            $page = $reading->document['pages'][1];
            Storage::disk('local')->assertExists($page['imagePath']);
            $this->assertArrayNotHasKey('imageFile', $page);
        } finally {
            unlink($image);
            unlink($file);
        }
    }

    private function asBrowser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web')->withHeader('Origin', 'https://convo-lab.test');
    }

    private function reading(User $user): Reading
    {
        $reading = Reading::create(['user_id' => $user->id, 'slug' => 'story', 'document' => $this->document(), 'illustration_path' => 'test.jpg']);
        Storage::disk('local')->put('test.jpg', 'photo');

        return $reading;
    }

    private function url(Reading $reading): string
    {
        return '/api/convolab/readings/'.$reading->id;
    }

    private function document(): array
    {
        return ['title' => 'Story', 'author' => 'Author',
            'pages' => [['number' => 5, 'kind' => 'text', 'columns' => [[[0, '犬[いぬ]です。']]]]],
            'sentences' => [['text' => '犬です。', 'translation' => 'It is a dog.']]];
    }
}

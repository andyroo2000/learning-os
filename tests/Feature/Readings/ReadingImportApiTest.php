<?php

namespace Tests\Feature\Readings;

use App\Domain\Readings\Models\Reading;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReadingImportApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_token_import_uploads_private_images_and_replays_without_a_duplicate(): void
    {
        $user = User::factory()->create();
        $this->withToken($user->createToken('cli')->plainTextToken);
        $payload = $this->payload();
        $payload['images'] = [25 => UploadedFile::fake()->image('page.png')];
        $document = json_decode($payload['document'], true);
        $document['pages'][] = ['number' => 25, 'kind' => 'illustration', 'columns' => []];
        $payload['document'] = json_encode($document);
        $this->post('/api/reading-imports', $payload, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('revision', 1);
        $this->post('/api/reading-imports', $payload, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('revision', 1);
        $this->assertDatabaseCount('readings', 1);
        $reading = Reading::findOrFail($payload['id']);
        Storage::disk('local')->assertExists($reading->document['pages'][1]['imagePath']);
        $this->getJson('/api/convolab/readings/'.$reading->id)->assertOk()->assertJsonMissingPath('document.pages.1.imagePath');
        $this->get('/api/convolab/readings/'.$reading->id.'/pages/25/illustration')->assertOk();
    }

    public function test_content_updates_require_revision_and_preserve_original_ruby(): void
    {
        $this->actingAs(User::factory()->create());
        $payload = $this->payload();
        $this->postJson('/api/reading-imports', $payload)->assertOk();
        $document = json_decode($payload['document'], true);
        $document['sentences'][0]['translation'] = 'A corrected translation';
        $payload['document'] = json_encode($document);
        $this->postJson('/api/reading-imports', $payload)->assertConflict();
        $payload['expectedRevision'] = 1;
        $this->postJson('/api/reading-imports', $payload)->assertOk()->assertJsonPath('revision', 2);
        $this->postJson('/api/reading-imports', $payload)->assertOk()->assertJsonPath('revision', 2);
        $this->getJson('/api/convolab/readings/'.$payload['id'])->assertOk()
            ->assertJsonPath('document.pages.0.columns.0.0.1', '犬[いぬ]です。');
    }

    public function test_local_paths_and_cross_account_replacements_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $payload = $this->payload();
        $this->postJson('/api/reading-imports', $payload)->assertOk();
        $this->actingAs(User::factory()->create())->postJson('/api/reading-imports', $payload)->assertNotFound();
        $payload['id'] = strtolower((string) Str::ulid());
        $document = json_decode($payload['document'], true);
        $document['pages'][0]['imageFile'] = '/etc/passwd';
        $payload['document'] = json_encode($document);
        $this->postJson('/api/reading-imports', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('readings', 1);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_mixed_library_hides_nested_readings_and_orders_by_creation(): void
    {
        $this->actingAs(User::factory()->create());
        $payload = $this->payload();
        $this->postJson('/api/reading-imports', $payload)->assertOk();
        $book = strtolower((string) Str::ulid());
        $this->postJson('/api/reading-books', ['id' => $book, 'title' => 'Forest Tales'])->assertOk();
        $this->getJson('/api/reading-library')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('per_page', 20);
        $this->putJson('/api/reading-books/'.$book.'/contents', ['expectedRevision' => 1, 'readingIds' => [$payload['id']]])->assertOk();
        $this->getJson('/api/reading-library')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'book');
        $this->actingAs(User::factory()->create())->getJson('/api/reading-library')->assertOk()->assertJsonCount(0, 'data');
    }

    private function payload(): array
    {
        return ['id' => strtolower((string) Str::ulid()), 'document' => json_encode([
            'title' => 'Reading', 'author' => 'Author',
            'pages' => [['number' => 24, 'kind' => 'text', 'columns' => [[[0, '犬[いぬ]です。']]]]],
            'sentences' => [['text' => '犬です。', 'translation' => 'It is a dog.']],
        ])];
    }
}

<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Study\Actions\ProcessStudyImportJobAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PDO;
use Tests\Support\Study\BuildsStudyImportArchives;
use Tests\TestCase;

class ProcessStudyImportCanonicalAnswersTest extends TestCase
{
    use BuildsStudyImportArchives, RefreshDatabase;

    public function test_listening_archive_keeps_canonical_answers_and_original_audio(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        $user = User::factory()->create();
        Storage::disk('study-imports')->put('canonical.colpkg', $this->canonicalArchive());
        $job = StudyImportJob::factory()->uploadCompleted()->create([
            'user_id' => $user->id, 'source_object_path' => 'canonical.colpkg',
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($job->id);

        $this->assertSame(StudyImportStatus::Completed, $processed->status);
        $card = Card::query()->where('source_card_id', 701)->sole();
        $this->assertSame($this->answer(), $card->answer_json);
        $this->assertSame(['cueAudio'], array_keys($card->prompt_json));
        $this->assertSame('word.mp3', $card->prompt_json['cueAudio']['filename']);
        $this->assertNull(Card::query()->where('source_card_id', 703)->sole()->answer_json);
        $this->assertCanonicalApiCard($user, $card);
    }

    public function test_named_answers_also_support_text_cards_and_revision_protected_edits(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        $user = User::factory()->create();
        Storage::disk('study-imports')->put('text.colpkg', $this->canonicalArchive(listening: false));
        $job = StudyImportJob::factory()->uploadCompleted()->create([
            'user_id' => $user->id, 'source_object_path' => 'text.colpkg',
        ]);
        app(ProcessStudyImportJobAction::class)->handle($job->id);
        $card = Card::query()->where('source_card_id', 701)->sole();
        Sanctum::actingAs($user);
        $response = $this->getJson('/api/study/cards/'.$card->id)->assertOk()
            ->assertJsonPath('answer', $this->answer())
            ->assertJsonPath('presentation.front.mode', 'text')
            ->assertJsonPath('presentation.front.text', $this->answer()['expression'])
            ->assertJsonPath('presentation.front.media.audio', null);
        $answer = [...$response->json('answer'), 'notes' => 'Reviewed'];

        $this->patchJson('/api/study/cards/'.$card->id, [
            'expectedRevision' => $response->json('revision'),
            'prompt' => $response->json('prompt'), 'answer' => $answer,
        ])->assertOk()->assertJsonPath('answer', $answer)
            ->assertJsonPath('presentation.front.text', $this->answer()['expression'])
            ->assertJsonPath('presentation.answer.heading', $this->answer()['expression'])
            ->assertJsonPath('state.queueState', 'new');
    }

    private function assertCanonicalApiCard(User $user, Card $card): void
    {
        Sanctum::actingAs($user);
        $this->getJson('/api/study/cards/'.$card->id)->assertOk()
            ->assertJsonPath('answer', $this->answer())
            ->assertJsonPath('presentation.front.mode', 'media')
            ->assertJsonPath('presentation.front.hint', null)
            ->assertJsonPath('presentation.front.media.image', null)
            ->assertJsonPath('presentation.answer.heading', '父親は今年50歳になる。')
            ->assertJsonPath('presentation.answer.ruby', '父親[ちちおや]は今年[ことし]50歳[ごじゅっさい]になる。')
            ->assertJsonPath('state.queueState', 'new')
            ->assertJsonPath('state.introducedAt', null);
        $response = $this->get('/api/study/media/'.$card->prompt_json['cueAudio']['id'])->assertOk();
        $this->assertSame('original-audio-bytes', $response->streamedContent());
    }

    private function answer(): array
    {
        return [
            'expression' => '父親は今年50歳になる。',
            'expressionReading' => '父親[ちちおや]は今年[ことし]50歳[ごじゅっさい]になる。',
            'meaning' => 'My father turns 50 this year.',
            'notes' => null,
        ];
    }

    private function canonicalArchive(bool $listening = true): string
    {
        $path = tempnam(sys_get_temp_dir(), 'canonical-import-');
        try {
            $this->createStudyImportCollectionDatabase($path, []);
            $this->replaceCanonicalModel($path, $listening);

            return $this->buildStudyImportZipBytes([
                'collection.anki21' => file_get_contents($path),
                'media' => json_encode(['0' => 'word.mp3']),
                '0' => 'original-audio-bytes',
            ]);
        } finally {
            unlink($path);
        }
    }

    private function replaceCanonicalModel(string $path, bool $listening): void
    {
        $pdo = new PDO('sqlite:'.$path);
        $models = json_decode($pdo->query('SELECT models FROM col')->fetchColumn(), true);
        $models['1001']['flds'] = array_map(fn ($name) => ['name' => $name], ['Expression', 'ExpressionReading', 'Meaning', 'Photo', 'AudioWord', 'Notes']);
        $models['1001']['tmpls'][0] = [
            'ord' => 0, 'name' => 'Canonical', 'qfmt' => $listening ? '{{AudioWord}}' : '{{Expression}}',
            'afmt' => '{{Expression}}<br>{{ExpressionReading}}<br>{{Meaning}}',
        ];
        $pdo->prepare('UPDATE col SET models = ?')->execute([json_encode($models)]);
        $answer = $this->answer();
        $fields = [$answer['expression'], $answer['expressionReading'], $answer['meaning'], '', '[sound:word.mp3]', ''];
        $pdo->prepare('UPDATE notes SET flds = ? WHERE id = 501')->execute([implode("\x1f", $fields)]);
    }
}

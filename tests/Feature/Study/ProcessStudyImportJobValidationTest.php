<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Flashcards\Models\Deck;
use App\Domain\Study\Actions\ProcessStudyImportJobAction;
use App\Domain\Study\Enums\StudyImportStatus;
use App\Domain\Study\Models\StudyImportJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Study\BuildsStudyImportArchives;
use Tests\TestCase;

class ProcessStudyImportJobValidationTest extends TestCase
{
    use BuildsStudyImportArchives, RefreshDatabase;

    public function test_process_job_persists_the_same_collection_expansion_error_as_preview(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        Config::set('study_import.archive_expansion.max_collection_database_bytes', 1);
        $sourceObjectPath = 'study/imports/process/oversized-collection.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes());
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Failed, $processed?->status);
        $this->assertSame(
            'The uncompressed collection database must not exceed 1 byte.',
            $processed?->error_message,
        );
        $this->assertSame(0, Deck::query()->where('user_id', $importJob->user_id)->count());
    }

    public function test_process_job_persists_import_metadata_length_errors_before_database_writes(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/oversized-deck-name.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes([
            'deck_name' => str_repeat('d', Deck::MAX_NAME_LENGTH + 1),
        ]));
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Failed, $processed?->status);
        $this->assertSame(
            'The imported deck name must not exceed '.Deck::MAX_NAME_LENGTH.' characters.',
            $processed?->error_message,
        );
        $this->assertSame(0, Deck::query()->where('user_id', $importJob->user_id)->count());
    }

    public function test_process_job_persists_invalid_card_text_encoding_before_database_writes(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/invalid-card-text.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes([
            'note_one_fields' => "front\xFF\x1fback",
        ]));
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Failed, $processed?->status);
        $this->assertSame('Imported card text must use valid UTF-8.', $processed?->error_message);
        $this->assertSame(0, Deck::query()->where('user_id', $importJob->user_id)->count());
        $this->assertSame(0, Card::query()->where('import_job_id', $importJob->id)->count());
    }

    public function test_process_job_persists_invalid_candidate_deck_encoding_before_database_writes(): void
    {
        Storage::fake('study-imports');
        Storage::fake('media');
        $sourceObjectPath = 'study/imports/process/invalid-candidate-deck-name.colpkg';
        Storage::disk('study-imports')->put($sourceObjectPath, $this->buildStudyImportArchiveBytes([
            'normalized_schema' => true,
            'deck_name' => 'Spanish',
            'extra_decks' => [
                ['id' => 1700000000001, 'name' => 'French', 'normalized_name' => "French\xFF"],
            ],
            'extra_cards' => [
                ['id' => 704, 'did' => 1700000000001],
            ],
        ]));
        $importJob = StudyImportJob::factory()->uploadCompleted()->create([
            'source_object_path' => $sourceObjectPath,
        ]);

        $processed = app(ProcessStudyImportJobAction::class)->handle($importJob->id);

        $this->assertSame(StudyImportStatus::Failed, $processed?->status);
        $this->assertSame('The imported deck name must use valid UTF-8.', $processed?->error_message);
        $this->assertSame(0, Deck::query()->where('user_id', $importJob->user_id)->count());
        $this->assertSame(0, Card::query()->where('import_job_id', $importJob->id)->count());
    }
}

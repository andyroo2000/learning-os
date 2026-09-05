<?php

namespace Tests\Feature\Rehearsal;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Models\CardReviewEvent;
use App\Domain\Study\Models\DailyAudioPractice;
use App\Domain\Study\Models\DailyAudioPracticeTrack;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConvoLabRehearsalImportCommandTest extends ConvoLabRehearsalImportTestCase
{
    public function test_imports_convolab_source_data_into_the_rehearsal_schema(): void
    {
        $this->seedConvoLabSourceData();
        $this->runImport();
        $this->assertCanonicalIdentifiers();
        $this->assertImportedDailyAudio();
        $this->assertImportedStudyData();
        $this->assertStudyBrowserResponses();
        $this->assertImportedMediaAndReview();

        $this->artisan('rehearsal:smoke', [
            '--user-email' => 'ada@example.com',
        ])->assertExitCode(0);
    }

    private function runImport(): void
    {
        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain('Imported 1 users.')
            ->expectsOutputToContain('Imported 1 daily audio practices and 1 tracks.')
            ->expectsOutputToContain('Imported 1 decks.')
            ->expectsOutputToContain('Imported 1 study settings rows.')
            ->expectsOutputToContain('Imported 1 study import jobs.')
            ->expectsOutputToContain('Imported 1 media assets (1 duplicate source paths reused).')
            ->expectsOutputToContain('Imported 1 cards.')
            ->expectsOutputToContain('Imported 1 card media links.')
            ->expectsOutputToContain('Imported 1 review events.')
            ->assertExitCode(0);
    }

    private function assertCanonicalIdentifiers(): void
    {
        foreach (['decks', 'study_import_jobs', 'media_assets', 'cards', 'card_review_events'] as $table) {
            $id = DB::table($table)->value('id');

            $this->assertIsString($id);
            $this->assertSame(strtolower($id), $id, "{$table}.id must use canonical lowercase ULIDs.");
        }
    }

    private function assertImportedDailyAudio(): void
    {
        $this->assertDatabaseHas('users', [
            'email' => 'ada@example.com',
            'convolab_id' => self::SOURCE_USER_ID,
        ]);
        $this->assertDatabaseHas('daily_audio_practices', [
            'id' => self::SOURCE_DAILY_AUDIO_ID,
            'convolab_user_id' => self::SOURCE_USER_ID,
            'status' => 'ready',
            'target_duration_minutes' => 30,
        ]);
        $this->assertDatabaseHas('daily_audio_practice_tracks', [
            'id' => self::SOURCE_DAILY_AUDIO_TRACK_ID,
            'practice_id' => self::SOURCE_DAILY_AUDIO_ID,
            'mode' => 'drill',
            'sort_order' => 0,
        ]);
        $this->assertSame(
            '2026-07-14 10:00:00.554',
            DailyAudioPractice::query()->sole()->created_at->format('Y-m-d H:i:s.v'),
        );
        $this->assertSame(
            '2026-07-14 10:00:00.766',
            DailyAudioPractice::query()->sole()->updated_at->format('Y-m-d H:i:s.v'),
        );
        $this->assertSame(
            '2026-07-14 10:00:00.561',
            DailyAudioPracticeTrack::query()->sole()->created_at->format('Y-m-d H:i:s.v'),
        );
        $this->assertSame(
            '2026-07-14 10:00:00.679',
            DailyAudioPracticeTrack::query()->sole()->updated_at->format('Y-m-d H:i:s.v'),
        );
    }

    private function assertImportedStudyData(): void
    {
        $this->assertDatabaseHas('decks', [
            'name' => '日本語',
            'is_manual_study_deck' => false,
        ]);
        $this->assertDatabaseHas('cards', [
            'convolab_id' => self::SOURCE_CARD_ID,
            'convolab_note_id' => self::SOURCE_NOTE_ID,
            'source_note_id' => 321,
            'source_deck_name' => '日本語',
            'source_template_name' => 'Card 1',
            'answer_audio_source' => 'generated',
            'source_notetype_name' => 'Japanese - Vocab',
            'card_type' => 'recognition',
            'study_status' => 'review',
            'front_text' => '猫',
            'back_text' => 'cat',
        ]);
        $this->assertDatabaseHas('study_import_jobs', [
            'convolab_id' => self::SOURCE_IMPORT_ID,
        ]);
        $this->assertSame(
            '2026-07-14 10:00:00.108',
            StudyImportJob::query()->sole()->completed_at->format('Y-m-d H:i:s.v'),
        );
        $this->assertSame(
            '2026-07-14 10:00:00.844',
            Card::query()->sole()->due_at->format('Y-m-d H:i:s.v'),
        );
        $this->assertSame('2026-06-14 22:18:00.956', Card::query()->sole()->convolab_note_created_at->format('Y-m-d H:i:s.v'));
        $this->assertSame('2026-07-13 09:08:07.654', Card::query()->sole()->convolab_note_updated_at->format('Y-m-d H:i:s.v'));
        $this->assertSame('anki_import', Card::query()->sole()->convolab_note_source_kind);
        $this->assertSame('source-note-guid', Card::query()->sole()->convolab_note_source_guid);
        $this->assertSame(654, Card::query()->sole()->convolab_note_source_notetype_id);
        $this->assertSame(['Expression' => '猫', 'Meaning' => 'cat'], Card::query()->sole()->convolab_note_raw_fields_json);
        $this->assertSame(['expression' => '猫', 'meaning' => 'cat'], Card::query()->sole()->convolab_note_canonical_json);
    }

    private function assertStudyBrowserResponses(): void
    {
        $this->signIn(User::query()->sole());
        $this->getJson('/api/daily-audio-practice')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', self::SOURCE_DAILY_AUDIO_ID)
            ->assertJsonPath('0.userId', self::SOURCE_USER_ID)
            ->assertJsonPath('0.tracks.0.id', self::SOURCE_DAILY_AUDIO_TRACK_ID)
            ->assertJsonMissingPath('0.tracks.0.scriptUnitsJson');
        $this->getJson('/api/daily-audio-practice/'.self::SOURCE_DAILY_AUDIO_ID)
            ->assertOk()
            ->assertJsonPath('sourceCardIdsJson.0', self::SOURCE_CARD_ID)
            ->assertJsonPath('selectionSummaryJson.selectedCount', 1)
            ->assertJsonPath('tracks.0.scriptUnitsJson.0.text', '猫')
            ->assertJsonPath('tracks.0.audioUrl', '/uploads/daily-audio-practice/drill.mp3')
            ->assertJsonPath('tracks.0.timingData.0.endTime', 1)
            ->assertJsonPath('tracks.0.generationMetadataJson.provider', 'test');
        $this->getJson('/api/study/browser?sortField=created_on&sortDirection=desc&limit=25')
            ->assertOk()
            ->assertExactJson([
                'rows' => [[
                    'noteId' => self::SOURCE_NOTE_ID,
                    'selectedCardId' => self::SOURCE_CARD_ID,
                    'displayText' => '猫',
                    'noteTypeName' => 'Japanese - Vocab',
                    'sourceKind' => 'anki_import',
                    'cardCount' => 1,
                    'reviewCount' => 1,
                    'lastReviewedAt' => '2026-07-14T10:00:00.000000Z',
                    'queueSummary' => ['review' => 1],
                    'createdAt' => '2026-06-14T22:18:00.956000Z',
                    'updatedAt' => '2026-07-13T09:08:07.654000Z',
                ]],
                'total' => 1,
                'limit' => 25,
                'nextCursor' => null,
                'filterOptions' => [
                    'noteTypes' => ['Japanese - Vocab'],
                    'cardTypes' => ['recognition'],
                    'queueStates' => ['review'],
                ],
            ]);
        $this->getJson('/api/study/browser/'.self::SOURCE_NOTE_ID)
            ->assertOk()
            ->assertJsonPath('sourceKind', 'anki_import')
            ->assertJsonPath('rawFields.0.name', 'Expression')
            ->assertJsonPath('rawFields.0.value', '猫')
            ->assertJsonPath('rawFields.1.name', 'Meaning')
            ->assertJsonPath('canonicalFields.0.name', 'expression')
            ->assertJsonPath('canonicalFields.1.name', 'meaning')
            ->assertJsonPath('cards.0.state.source.noteId', '321')
            ->assertJsonPath('cards.0.state.source.noteGuid', 'source-note-guid')
            ->assertJsonPath('cards.0.state.source.deckName', '日本語')
            ->assertJsonPath('cards.0.state.source.notetypeId', '654')
            ->assertJsonPath('cards.0.state.source.templateName', 'Card 1')
            ->assertJsonPath('cards.0.answerAudioSource', 'generated');
    }

    private function assertImportedMediaAndReview(): void
    {
        $this->assertDatabaseCount('media_assets', 1);
        $this->assertDatabaseHas('media_assets', [
            'disk' => 'media',
            'path' => 'study-media/'.self::SOURCE_USER_ID.'/neko.mp3',
        ]);
        $this->assertDatabaseCount('card_media', 1);
        $this->assertDatabaseHas('card_review_events', [
            'rating' => 'good',
        ]);
        $this->assertSame([
            'study_status' => 'review',
            'new_queue_position' => null,
            'scheduler_state' => ['before' => true],
            'due_at' => '2026-07-14T09:00:00.000Z',
            'introduced_at' => null,
            'failed_at' => null,
            'last_reviewed_at' => '2026-07-13T10:00:00.000Z',
        ], CardReviewEvent::query()->sole()->card_state_before);
    }
}

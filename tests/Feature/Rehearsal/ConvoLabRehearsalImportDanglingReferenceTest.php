<?php

namespace Tests\Feature\Rehearsal;

use Illuminate\Support\Facades\DB;

class ConvoLabRehearsalImportDanglingReferenceTest extends ConvoLabRehearsalImportTestCase
{
    public function test_deduplicates_deck_names_after_legacy_values_are_defaulted(): void
    {
        $this->seedConvoLabSourceData();
        $source = DB::connection('convolab_test_source');
        $template = (array) $source->table('study_cards')->where('id', self::SOURCE_CARD_ID)->first();
        $noteTemplate = (array) $source->table('study_notes')->where('id', self::SOURCE_NOTE_ID)->first();

        foreach ([
            ['1a2d53ab-10e6-4552-b25b-3c9386a5ff29', 'b7cf04c9-bce8-4030-bf34-43f898474af0', 124, null],
            ['97eeef49-a6ad-478e-88f8-e33ba463e798', '143a150f-ecaf-47fb-8299-73f4a0b87718', 125, '   '],
        ] as [$id, $noteId, $sourceCardId, $deckName]) {
            $source->table('study_notes')->insert([
                ...$noteTemplate,
                'id' => $noteId,
                'sourceNoteId' => $sourceCardId + 1000,
            ]);
            $source->table('study_cards')->insert([
                ...$template,
                'id' => $id,
                'noteId' => $noteId,
                'sourceCardId' => $sourceCardId,
                'sourceDeckName' => $deckName,
                'promptAudioMediaId' => null,
                'answerAudioMediaId' => null,
            ]);
        }

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('decks', 2);
        $this->assertDatabaseCount('cards', 3);
        $this->assertDatabaseHas('decks', ['name' => 'Convo Lab Study Cards']);
    }

    public function test_rejects_a_dangling_import_job_reference_instead_of_discarding_it(): void
    {
        $this->seedConvoLabSourceData();
        DB::connection('convolab_test_source')
            ->table('study_cards')
            ->update(['importJobId' => 'missing-import-job']);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain('Missing imported study job mapping for [missing-import-job].')
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('cards', 0);
    }

    public function test_rejects_a_dangling_daily_audio_track_and_rolls_back_the_import(): void
    {
        $this->seedConvoLabSourceData();
        DB::connection('convolab_test_source')
            ->table('daily_audio_practice_tracks')
            ->update(['practiceId' => 'a0ba34d4-b2e7-4fb3-b138-03b78a0d0d14']);

        $this->artisan('rehearsal:import-convolab', [
            '--source-connection' => 'convolab_test_source',
            '--truncate' => true,
        ])
            ->expectsOutputToContain(
                'Missing imported daily audio practice mapping for track ['.self::SOURCE_DAILY_AUDIO_TRACK_ID.'].',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('daily_audio_practices', 0);
        $this->assertDatabaseCount('daily_audio_practice_tracks', 0);
    }

    public function test_rejects_a_dangling_note_reference_instead_of_discarding_the_card(): void
    {
        $this->seedConvoLabSourceData();
        DB::connection('convolab_test_source')->table('study_notes')->delete();

        $this->assertImportFailsWith(
            'Missing Convo Lab note ['.self::SOURCE_NOTE_ID.'] for card ['.self::SOURCE_CARD_ID.'].',
        );

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('cards', 0);
    }
}

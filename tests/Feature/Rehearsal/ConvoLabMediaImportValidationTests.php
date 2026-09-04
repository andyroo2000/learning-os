<?php

namespace Tests\Feature\Rehearsal;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Study\Models\StudyImportJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

trait ConvoLabMediaImportValidationTests
{
    public function test_preflight_rejects_missing_source_bytes_without_writing_data(): void
    {
        $this->importMedia()
            ->expectsOutputToContain(
                'Convo Lab media bytes are missing for [source-media-1] at [study-media/source-user/neko.mp3].',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('card_media', 0);
        Storage::disk(MediaAsset::DISK_MEDIA)->assertMissing('study-media/source-user/neko.mp3');
    }

    public function test_preflight_rejects_a_different_existing_destination_file(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'source-bytes');
        Storage::disk(MediaAsset::DISK_MEDIA)
            ->put('study-media/source-user/neko.mp3', 'different-target-bytes');

        $this->importMedia()
            ->expectsOutputToContain(
                'Learning OS media file [study-media/source-user/neko.mp3] has different bytes.',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertSame(
            'different-target-bytes',
            Storage::disk(MediaAsset::DISK_MEDIA)->get('study-media/source-user/neko.mp3'),
        );
    }

    public function test_preflight_rejects_cross_user_card_media_ownership(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');
        $source = DB::connection('convolab_media_test_source');
        $source->table('User')->insert([
            'id' => 'source-user-2',
            'email' => 'grace@example.com',
        ]);
        User::factory()->create(['email' => 'grace@example.com']);
        $source->table('study_media')->update(['userId' => 'source-user-2']);

        $this->importMedia()
            ->expectsOutputToContain(
                'Card ['.self::SOURCE_CARD_ID.'] references media [source-media-1] owned by another user.',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('card_media', 0);
    }

    public function test_rejects_media_for_a_soft_deleted_target_card(): void
    {
        $this->assertRejectsMediaForDeletedTarget(
            fn () => Card::query()->sole()->delete(),
        );
    }

    public function test_rejects_media_for_a_card_in_a_soft_deleted_target_deck(): void
    {
        $this->assertRejectsMediaForDeletedTarget(
            fn () => Card::query()->sole()->deck()->sole()->delete(),
        );
    }

    public function test_rejects_missing_import_job_provenance(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');
        StudyImportJob::query()->delete();

        $this->importMedia()
            ->expectsOutputToContain(
                'Learning OS has no import job matching Convo Lab import job ['.
                self::SOURCE_IMPORT_ID.'].',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('media_assets', 0);
    }

    public function test_preflight_rejects_source_metadata_that_exceeds_target_column_limits(): void
    {
        $this->putSourceFile('study-media/source-user/neko.mp3', 'verified-neko-bytes');
        DB::connection('convolab_media_test_source')
            ->table('study_media')
            ->where('id', 'source-media-1')
            ->update(['sourceKind' => str_repeat('a', 65)]);

        $this->importMedia()
            ->expectsOutputToContain(
                'Convo Lab media [source-media-1] source kind exceeds 64 characters.',
            )
            ->assertExitCode(1);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('card_media', 0);
        Storage::disk(MediaAsset::DISK_MEDIA)->assertMissing('study-media/source-user/neko.mp3');
    }
}

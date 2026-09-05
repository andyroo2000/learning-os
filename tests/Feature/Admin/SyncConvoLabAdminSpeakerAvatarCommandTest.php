<?php

namespace Tests\Feature\Admin;

use App\Domain\Auth\Support\ConvoLabAccountSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncConvoLabAdminSpeakerAvatarCommandTest extends SyncConvoLabAdminProjectionCommandTestCase
{
    public function test_syncs_updates_and_prunes_speaker_avatars(): void
    {
        $avatarId = (string) Str::uuid();
        $staleId = (string) Str::uuid();
        $this->insertSourceAvatar($avatarId, [
            'filename' => 'ja-female-casual.jpg',
            'croppedUrl' => 'https://storage.example/cropped.jpg',
            'originalUrl' => 'https://storage.example/original.jpg',
            'language' => 'ja',
            'gender' => 'female',
            'tone' => 'casual',
        ]);
        DB::table('admin_speaker_avatars')->insert([
            'id' => $staleId,
            'filename' => 'ja-male-formal.jpg',
            'cropped_url' => 'https://storage.example/stale-cropped.jpg',
            'original_url' => 'https://storage.example/stale-original.jpg',
            'language' => 'ja',
            'gender' => 'male',
            'tone' => 'formal',
            'source_system' => 'convolab',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runSync()
            ->expectsOutput('Synchronized 0 users, 0 invite codes, and 1 speaker avatars.')
            ->assertSuccessful();

        $this->assertDatabaseHas('admin_speaker_avatars', [
            'id' => $avatarId,
            'filename' => 'ja-female-casual.jpg',
            'cropped_url' => 'https://storage.example/cropped.jpg',
            'original_url' => 'https://storage.example/original.jpg',
            'source_system' => 'convolab',
        ]);
        $this->assertDatabaseMissing('admin_speaker_avatars', ['id' => $staleId]);

        DB::connection('convolab_admin_test')->table('SpeakerAvatar')->where('id', $avatarId)->update([
            'croppedUrl' => 'https://storage.example/updated.jpg',
        ]);
        $this->runSync()->assertSuccessful();

        $this->assertDatabaseHas('admin_speaker_avatars', [
            'id' => $avatarId,
            'cropped_url' => 'https://storage.example/updated.jpg',
        ]);
        $this->assertDatabaseCount('admin_speaker_avatars', 1);
    }

    public function test_sync_refuses_to_prune_speaker_avatars_from_an_unexpected_empty_source(): void
    {
        DB::table('admin_speaker_avatars')->insert([
            'id' => (string) Str::uuid(),
            'filename' => 'ja-female-polite.jpg',
            'cropped_url' => 'https://storage.example/cropped.jpg',
            'original_url' => 'https://storage.example/original.jpg',
            'language' => 'ja',
            'gender' => 'female',
            'tone' => 'polite',
            'source_system' => 'convolab',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runSync()
            ->expectsOutputToContain(
                'The Convo Lab source table [SpeakerAvatar] is empty while [admin_speaker_avatars] is not.',
            )
            ->assertFailed();

        $this->assertDatabaseCount('admin_speaker_avatars', 1);
    }

    public function test_sync_reconciles_a_source_id_rotation_for_the_same_filename(): void
    {
        $oldId = (string) Str::uuid();
        $newId = (string) Str::uuid();
        DB::table('admin_speaker_avatars')->insert([
            'id' => $oldId,
            'filename' => 'ja-female-casual.jpg',
            'cropped_url' => 'https://storage.example/old-cropped.jpg',
            'original_url' => 'https://storage.example/old-original.jpg',
            'language' => 'ja',
            'gender' => 'female',
            'tone' => 'casual',
            'source_system' => 'convolab',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertSourceAvatar($newId, [
            'filename' => 'ja-female-casual.jpg',
            'croppedUrl' => 'https://storage.example/new-cropped.jpg',
        ]);

        $this->runSync()
            ->expectsOutput('Synchronized 0 users, 0 invite codes, and 1 speaker avatars.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('admin_speaker_avatars', ['id' => $oldId]);
        $this->assertDatabaseHas('admin_speaker_avatars', [
            'id' => $newId,
            'filename' => 'ja-female-casual.jpg',
            'cropped_url' => 'https://storage.example/new-cropped.jpg',
        ]);
        $this->assertDatabaseCount('admin_speaker_avatars', 1);
    }

    public function test_speaker_avatar_ownership_is_checked_once_per_source_chunk(): void
    {
        foreach (['casual', 'polite', 'formal'] as $tone) {
            foreach (['female', 'male'] as $gender) {
                $this->insertSourceAvatar((string) Str::uuid(), [
                    'filename' => "ja-{$gender}-{$tone}.jpg",
                    'gender' => $gender,
                    'tone' => $tone,
                ]);
            }
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->runSync()->assertSuccessful();
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $ownershipQueries = array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['query'], 'select "id", "filename"')
                && str_contains($query['query'], 'from "admin_speaker_avatars"')
                && in_array(ConvoLabAccountSource::LEARNING_OS, $query['bindings'], true),
        );

        $this->assertCount(1, $ownershipQueries);
        $this->assertDatabaseCount('admin_speaker_avatars', 6);
    }
}

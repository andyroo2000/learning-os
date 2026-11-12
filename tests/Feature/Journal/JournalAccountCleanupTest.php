<?php

namespace Tests\Feature\Journal;

use App\Domain\Auth\Actions\DeleteCurrentUserAction;
use App\Domain\Journal\Models\JournalEntry;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class JournalAccountCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_deletion_removes_live_and_archived_audio_but_preserves_other_users(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['password' => 'correct-password123']);
        $live = JournalEntry::factory()->for($user)->create();
        $deleted = JournalEntry::factory()->for($user)->create();
        $deleted->delete();
        $other = JournalEntry::factory()->create();
        foreach ([$live, $deleted, $other] as $entry) {
            Storage::disk('local')->put('journal/'.$entry->id.'/audio/test.mp3', 'ID3audio');
        }
        app(DeleteCurrentUserAction::class)->handle($user, 'correct-password123');
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        Storage::disk('local')->assertDirectoryEmpty('journal/'.$live->id);
        Storage::disk('local')->assertDirectoryEmpty('journal/'.$deleted->id);
        Storage::disk('local')->assertExists('journal/'.$other->id.'/audio/test.mp3');
    }

    public function test_cleanup_failure_keeps_the_account_and_entry_for_a_retry(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['password' => 'correct-password123']);
        $entry = JournalEntry::factory()->for($user)->create();
        $user->createToken('device');
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('deleteDirectory')->once()->with('journal/'.$entry->id)->andReturnFalse();
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            app(DeleteCurrentUserAction::class)->handle($user, 'correct-password123');
            $this->fail('Expected cleanup failure.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);
    }
}

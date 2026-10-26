<?php

namespace Tests\Feature\Study;

use App\Domain\Study\Actions\SaveStudyPileAction;
use App\Domain\Study\Actions\SaveStudyPileChapterAction;
use App\Domain\Study\Models\StudyPile;
use App\Domain\Study\Models\StudyPileChapter;
use App\Domain\Study\Support\StudyPileTransaction;
use App\Domain\Sync\Models\SyncFeedEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StudyPileCatalogActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_creation_normalizes_ids_and_validates_sparse_updates(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();
        $save = app(SaveStudyPileAction::class);
        $pile = $save->handle($user->id, ' '.$id.' ', [
            'title' => ' Genki II ', 'position' => '+20', 'is_active' => 1, 'user_id' => 999,
        ]);
        $this->assertSame(strtolower($id), $pile->id);
        $this->assertSame('Genki II', $pile->fresh()->title);
        $this->assertSame(20, $pile->fresh()->position);
        $this->assertTrue($pile->fresh()->is_active);
        $this->assertSame($user->id, $pile->fresh()->user_id);
        $save->handle($user->id, $id, []);
        $this->assertSame(1, SyncFeedEntry::count());
        $this->expectException(ValidationException::class);
        $save->handle($user->id, $id, ['is_active' => null]);
    }

    public function test_direct_chapter_retry_keeps_one_record_and_one_sync_entry(): void
    {
        $user = User::factory()->create();
        $pile = app(SaveStudyPileAction::class)->handle($user->id, (string) Str::ulid(), ['title' => 'Book']);
        $chapterId = (string) Str::ulid();
        $save = app(SaveStudyPileChapterAction::class);
        $chapter = $save->handle($user->id, strtoupper($pile->id), $chapterId, ['title' => 'Chapter 20']);
        $save->handle($user->id, $pile->id, $chapterId, ['title' => 'Chapter 20']);
        $this->assertSame(strtolower($chapterId), $chapter->fresh()->id);
        $this->assertFalse($chapter->fresh()->is_active);
        $this->assertSame(1, StudyPileChapter::count());
        $this->assertSame(2, SyncFeedEntry::count());
    }

    public function test_primary_key_collision_is_hidden_after_transaction_rollback(): void
    {
        $user = User::factory()->create();
        $pile = app(SaveStudyPileAction::class)->handle($user->id, (string) Str::ulid(), ['title' => 'Book']);
        $this->expectException(ModelNotFoundException::class);
        StudyPileTransaction::run('study_piles', function () use ($pile): void {
            DB::table('study_piles')->insert([
                'id' => $pile->id, 'user_id' => $pile->user_id, 'title' => 'Collision',
            ]);
        });
    }

    public function test_malformed_ids_fail_before_any_catalog_write(): void
    {
        $this->expectException(ModelNotFoundException::class);
        app(SaveStudyPileAction::class)->handle(1, 'not-an-id', ['title' => 'Book']);
    }

    public function test_catalog_metadata_cannot_be_mass_assigned(): void
    {
        foreach ([new StudyPile, new StudyPileChapter] as $model) {
            foreach (['id', 'user_id', 'pile_id', 'title', 'position', 'is_active', 'source_reference'] as $field) {
                $this->assertFalse($model->isFillable($field));
            }
        }
    }
}

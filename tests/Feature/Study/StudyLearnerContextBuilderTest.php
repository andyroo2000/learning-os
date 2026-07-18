<?php

namespace Tests\Feature\Study;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Study\Services\StudyLearnerContextBuilder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyLearnerContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_summarizes_only_the_users_active_study_cards(): void
    {
        $user = User::factory()->create();
        $this->cardFor($user, [
            'card_type' => CardType::Recognition,
            'study_status' => CardStudyStatus::Review,
            'answer_json' => ['expression' => '会社', 'meaning' => 'company'],
            'prompt_json' => [],
            'source_lapses' => 2,
            'last_reviewed_at' => now(),
        ]);
        $this->cardFor($user, [
            'study_status' => CardStudyStatus::Learning,
            'answer_json' => [],
            'prompt_json' => ['cueText' => '勉強', 'cueMeaning' => 'study'],
            'last_reviewed_at' => now()->subMinute(),
        ]);
        $this->cardFor($user, [
            'study_status' => CardStudyStatus::New,
            'answer_json' => ['expression' => '除外'],
        ]);
        $this->cardFor(User::factory()->create(), [
            'study_status' => CardStudyStatus::Review,
            'answer_json' => ['expression' => '別人'],
        ]);
        $deletedDeckCard = $this->cardFor($user, [
            'study_status' => CardStudyStatus::Review,
            'answer_json' => ['expression' => '削除'],
        ]);
        $deletedDeckCard->deck->delete();

        $summary = app(StudyLearnerContextBuilder::class)->build($user->id);

        $this->assertSame(
            "- recognition/review (2 lapses): 会社 - company\n- recognition/learning: 勉強 - study",
            $summary,
        );
    }

    public function test_it_returns_null_when_no_active_cards_have_context_text(): void
    {
        $user = User::factory()->create();
        $this->cardFor($user, [
            'study_status' => CardStudyStatus::New,
            'answer_json' => ['expression' => 'new cards are excluded'],
        ]);

        $this->assertNull(app(StudyLearnerContextBuilder::class)->build($user->id));
    }
}

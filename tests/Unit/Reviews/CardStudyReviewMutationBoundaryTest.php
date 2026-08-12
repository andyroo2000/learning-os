<?php

namespace Tests\Unit\Reviews;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Enums\CardReviewRating;
use App\Domain\Reviews\Support\AppliesLockedCardStudyReview;
use App\Domain\Sync\Actions\RecordSyncFeedEntryAction;
use Illuminate\Support\Carbon;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class CardStudyReviewMutationBoundaryTest extends TestCase
{
    public function test_study_review_mutation_is_private_to_the_two_locked_review_writers(): void
    {
        $consumers = [
            ReviewCardAction::class,
            ReviewCardBatchAction::class,
        ];

        foreach ($consumers as $consumer) {
            $reflection = new ReflectionClass($consumer);
            $source = file_get_contents($reflection->getFileName());

            $this->assertContains(AppliesLockedCardStudyReview::class, $reflection->getTraitNames());
            $this->assertTrue((new ReflectionMethod($consumer, 'applyLockedCardStudyReview'))->isPrivate());
            $this->assertStringContainsString('DB::transaction(', $source);
            $this->assertStringContainsString('CardReviewCardLock::apply(', $source);
        }

        $this->assertSame([
            app_path('Domain/Reviews/Actions/ReviewCardAction.php'),
            app_path('Domain/Reviews/Actions/ReviewCardBatchAction.php'),
        ], $this->reviewWriterTraitConsumerPaths());
        $this->assertFalse(class_exists('App\\Domain\\Flashcards\\Actions\\ApplyCardStudyReviewAction'));
    }

    public function test_study_review_mutation_rejects_calls_outside_a_transaction(): void
    {
        $card = new Card;
        $reviewedAt = Carbon::parse('2026-05-27T09:15:00Z');
        $probe = new class
        {
            use AppliesLockedCardStudyReview;

            private RecordSyncFeedEntryAction $recordSyncFeedEntry;

            public function apply(Card $card, Carbon $reviewedAt): bool
            {
                return $this->applyLockedCardStudyReview($card, CardReviewRating::Good, $reviewedAt);
            }
        };

        try {
            $probe->apply($card, $reviewedAt);

            $this->fail('Expected unlocked study review mutation to be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Card study review mutation requires a locked card row inside a transaction.',
                $exception->getMessage(),
            );
            $this->assertFalse($card->isDirty());
        }
    }

    /** @return list<string> */
    private function reviewWriterTraitConsumerPaths(): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $source = file_get_contents($path);

            if (! str_contains($source, 'use AppliesLockedCardStudyReview;')) {
                continue;
            }

            $paths[] = $path;
        }

        sort($paths);

        return $paths;
    }
}

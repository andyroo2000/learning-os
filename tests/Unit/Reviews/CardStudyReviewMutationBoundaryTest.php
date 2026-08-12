<?php

namespace Tests\Unit\Reviews;

use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Actions\ReviewCardBatchAction;
use App\Domain\Reviews\Support\AppliesLockedCardStudyReview;
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

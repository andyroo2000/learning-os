<?php

namespace Tests\Feature\Reviews;

use App\Domain\Reviews\Actions\ReviewCardAction;
use App\Domain\Reviews\Data\ReviewCardData;
use App\Domain\Reviews\Results\ReviewCardResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ReviewCardActionTestCase extends TestCase
{
    use RefreshDatabase;

    protected function reviewCard(ReviewCardData $data): ReviewCardResult
    {
        return app(ReviewCardAction::class)->handle($data);
    }
}

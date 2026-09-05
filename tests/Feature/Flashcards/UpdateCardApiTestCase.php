<?php

namespace Tests\Feature\Flashcards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Flashcards\Concerns\UsesStudyCardRateLimitOverrides;
use Tests\TestCase;

abstract class UpdateCardApiTestCase extends TestCase
{
    use RefreshDatabase;
    use UsesStudyCardRateLimitOverrides;
}

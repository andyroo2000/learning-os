<?php

namespace Tests\Feature\Learn;

use App\Domain\FeatureFlags\Actions\UpdateFeatureFlagsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class LearnFeatureTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(UpdateFeatureFlagsAction::class)->handle(['learnEnabled' => true]);
    }
}

<?php

namespace Tests\Feature\Reviews;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsCursorPagination;
use Tests\TestCase;

abstract class ListReviewEventsApiTestCase extends TestCase
{
    use AssertsCursorPagination;
    use RefreshDatabase;
}

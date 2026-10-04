<?php

namespace Tests\Feature\Readings;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ReadingRouteContractTest extends TestCase
{
    public function test_readings_are_appended_after_existing_api_routes_before_health(): void
    {
        $order = collect(Route::getRoutes()->getRoutes())
            ->map(fn (LaravelRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
            ->values()->all();
        $first = array_search('GET|HEAD api/convolab/readings', $order, true);
        $last = array_search('POST api/convolab/readings/{reading}/sentences/{sentence}/audio', $order, true);
        $this->assertIsInt($first);
        $this->assertIsInt($last);
        $this->assertSame('POST api/decks', $order[$first - 1]);
        $this->assertSame('GET|HEAD up', $order[$last + 1]);
    }
}

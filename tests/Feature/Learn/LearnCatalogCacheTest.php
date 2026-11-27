<?php

namespace Tests\Feature\Learn;

use App\Domain\Learn\Support\LearnCatalog;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LearnCatalogCacheTest extends TestCase
{
    public function test_catalog_is_shared_and_read_once_per_request_scope(): void
    {
        $lessons = [['id' => 'lesson', 'title' => 'Example']];
        File::shouldReceive('json')->once()->with(resource_path('learn/quartet-one.json'))->andReturn($lessons);
        $catalog = app(LearnCatalog::class);
        $this->assertSame($catalog, app(LearnCatalog::class));
        $this->assertSame($lessons, $catalog->all());
        $this->assertSame($lessons[0], app(LearnCatalog::class)->find('lesson'));
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductionImageConfigurationTest extends TestCase
{
    public function test_production_image_defaults_to_the_database_queue(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/Dockerfile');

        $this->assertIsString($dockerfile);
        $this->assertStringContainsString('QUEUE_CONNECTION=database', $dockerfile);
        $this->assertStringNotContainsString('QUEUE_CONNECTION=sync', $dockerfile);
    }

    public function test_php_request_limits_match_the_async_import_contract(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/Dockerfile');

        $this->assertIsString($dockerfile);
        $this->assertStringContainsString("'memory_limit=512M'", $dockerfile);
        $this->assertStringContainsString("'post_max_size=2048M'", $dockerfile);
        $this->assertStringContainsString("'upload_max_filesize=2048M'", $dockerfile);
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductionImageConfigurationTest extends TestCase
{
    public function test_php_request_limits_match_the_async_import_contract(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/Dockerfile');

        $this->assertIsString($dockerfile);
        $this->assertStringContainsString("'post_max_size=2048M'", $dockerfile);
        $this->assertStringContainsString("'upload_max_filesize=2048M'", $dockerfile);
    }
}

<?php

namespace Tests\Unit\Analytics;

use App\Domain\Analytics\Services\JsonLineToolAnalyticsLogger;
use PHPUnit\Framework\TestCase;

final class JsonLineToolAnalyticsLoggerTest extends TestCase
{
    public function test_logger_writes_one_unescaped_json_line_to_its_output_stream(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tool-analytics-');
        $this->assertNotFalse($path);
        $logger = new JsonLineToolAnalyticsLogger($path);

        try {
            $logger->write([
                'type' => 'tool_analytics',
                'tool' => 'kana/trainer',
                'properties' => (object) [],
            ]);
            $output = file_get_contents($path);
        } finally {
            unlink($path);
        }

        $this->assertSame(
            "{\"type\":\"tool_analytics\",\"tool\":\"kana/trainer\",\"properties\":{}}\n",
            $output,
        );
    }
}

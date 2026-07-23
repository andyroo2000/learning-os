<?php

namespace Tests\Unit\Analytics;

use App\Domain\Analytics\Support\ToolAnalyticsProperties;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolAnalyticsPropertiesTest extends TestCase
{
    #[DataProvider('invalidProperties')]
    public function test_rule_rejects_values_that_json_cannot_safely_log(mixed $value): void
    {
        $failures = [];

        (new ToolAnalyticsProperties)->validate(
            'properties',
            ['value' => $value],
            function (string $message) use (&$failures): void {
                $failures[] = $message;
            },
        );

        $this->assertNotSame([], $failures);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidProperties(): array
    {
        return [
            'positive infinity' => [INF],
            'negative infinity' => [-INF],
            'not a number' => [NAN],
            'object' => [(object) ['nested' => true]],
        ];
    }
}

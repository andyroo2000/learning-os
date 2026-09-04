<?php

namespace Tests\Unit\Console;

use App\Console\Support\ConvoLabSourceDatabaseName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConvoLabSourceDatabaseNameTest extends TestCase
{
    public function test_it_trims_a_configured_database_name(): void
    {
        $database = ConvoLabSourceDatabaseName::fromOption(' source_copy ');

        $this->assertNotNull($database);
        $this->assertSame('source_copy', $database->value);
    }

    #[DataProvider('missingOptionProvider')]
    public function test_it_treats_missing_or_blank_options_as_unconfigured(mixed $value): void
    {
        $this->assertNull(ConvoLabSourceDatabaseName::fromOption($value));
    }

    /** @return iterable<string, array{mixed}> */
    public static function missingOptionProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'non-string' => [123];
        yield 'empty' => [''];
        yield 'whitespace' => [" \t\n"];
    }
}

<?php

namespace Tests\Unit\Support\Identifiers;

use App\Support\Identifiers\CanonicalUlid;
use PHPUnit\Framework\TestCase;

class CanonicalUlidTest extends TestCase
{
    public function test_database_candidates_include_canonical_and_legacy_imported_case(): void
    {
        $this->assertSame([
            '01jz9yvqf7x5d0m8c3n6p2r4st',
            '01JZ9YVQF7X5D0M8C3N6P2R4ST',
        ], CanonicalUlid::databaseCandidates('  01JZ9YVQF7X5D0M8C3N6P2R4ST  '));
    }

    public function test_database_candidates_do_not_duplicate_case_insensitive_numeric_ids(): void
    {
        $this->assertSame(
            ['01234567890123456789012345'],
            CanonicalUlid::databaseCandidates('01234567890123456789012345'),
        );
    }
}

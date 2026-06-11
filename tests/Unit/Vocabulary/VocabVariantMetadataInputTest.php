<?php

namespace Tests\Unit\Vocabulary;

use App\Domain\Vocabulary\Support\VocabVariantMetadataInput;
use PHPUnit\Framework\TestCase;

class VocabVariantMetadataInputTest extends TestCase
{
    public function test_canonical_variant_timestamp_requires_zulu_or_explicit_offset(): void
    {
        $this->assertSame(
            '2026-06-04T14:15:30.000000Z',
            VocabVariantMetadataInput::canonicalUnlockedAtTimestamp('2026-06-04T14:15:30Z')?->toJSON(),
        );
        $this->assertSame(
            '2026-06-04T08:45:30.000000Z',
            VocabVariantMetadataInput::canonicalUnlockedAtTimestamp('2026-06-04T14:15:30+05:30')?->toJSON(),
        );
        $this->assertSame(
            '2026-06-04T14:15:30.000000Z',
            VocabVariantMetadataInput::canonicalUnlockedAtTimestamp('2026-06-04T14:15:30+00:00')?->toJSON(),
        );
    }

    public function test_canonical_variant_timestamp_rejects_bare_invalid_or_unreal_offsets(): void
    {
        $this->assertNull(VocabVariantMetadataInput::canonicalUnlockedAtTimestamp('2026-06-04T14:15:30'));
        $this->assertNull(VocabVariantMetadataInput::canonicalUnlockedAtTimestamp('2026-02-31T14:15:30Z'));
        $this->assertNull(VocabVariantMetadataInput::canonicalUnlockedAtTimestamp('2026-06-04T14:15:30+15:00'));
        $this->assertNull(VocabVariantMetadataInput::canonicalUnlockedAtTimestamp('2026-06-04T14:15:30-13:00'));
        $this->assertNull(VocabVariantMetadataInput::canonicalUnlockedAtTimestamp('yesterday'));
    }

    public function test_compatibility_variant_timestamp_accepts_bare_values_as_utc(): void
    {
        $this->assertSame(
            '2026-06-04T14:15:30.000000Z',
            VocabVariantMetadataInput::compatibilityUnlockedAtTimestamp('2026-06-04T14:15:30')?->toJSON(),
        );
        $this->assertSame(
            '2026-06-04T14:15:30.987654Z',
            VocabVariantMetadataInput::compatibilityUnlockedAtTimestamp('2026-06-04T14:15:30.987654')?->toJSON(),
        );
    }

    public function test_compatibility_variant_timestamp_still_rejects_invalid_values(): void
    {
        $this->assertNull(VocabVariantMetadataInput::compatibilityUnlockedAtTimestamp('2026-02-31T14:15:30'));
        $this->assertNull(VocabVariantMetadataInput::compatibilityUnlockedAtTimestamp('2026-06-04T24:15:30'));
        $this->assertNull(VocabVariantMetadataInput::compatibilityUnlockedAtTimestamp('2026-06-04T14:15:30+15:00'));
        $this->assertNull(VocabVariantMetadataInput::compatibilityUnlockedAtTimestamp('2026-06-04T14:15:30-13:00'));
        $this->assertNull(VocabVariantMetadataInput::compatibilityUnlockedAtTimestamp('yesterday'));
    }
}

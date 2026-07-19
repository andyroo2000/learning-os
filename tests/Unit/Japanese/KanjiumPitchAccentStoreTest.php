<?php

namespace Tests\Unit\Japanese;

use App\Domain\Japanese\Services\KanjiumPitchAccentStore;
use RuntimeException;
use Tests\TestCase;

class KanjiumPitchAccentStoreTest extends TestCase
{
    public function test_it_reads_all_pitch_candidates_for_an_exact_surface(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kanjium-');
        $this->assertIsString($path);
        file_put_contents($path, "上手\tじょうず\t3,0\n上手\tうわて\t0\n上\tうえ\t0\n");

        try {
            $candidates = (new KanjiumPitchAccentStore($path))->candidates('上手');
        } finally {
            unlink($path);
        }

        $this->assertSame(
            [
                ['上手', 'じょうず', 3],
                ['上手', 'じょうず', 0],
                ['上手', 'うわて', 0],
            ],
            array_map(
                fn ($candidate): array => [
                    $candidate->surface,
                    $candidate->reading,
                    $candidate->pitchNumber,
                ],
                $candidates,
            ),
        );
    }

    public function test_it_skips_malformed_pitch_numbers(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kanjium-');
        $this->assertIsString($path);
        file_put_contents($path, "会社\tかいしゃ\t0,bad,-1\n");

        try {
            $candidates = (new KanjiumPitchAccentStore($path))->candidates('会社');
        } finally {
            unlink($path);
        }

        $this->assertCount(1, $candidates);
        $this->assertSame(0, $candidates[0]->pitchNumber);
    }

    public function test_it_reports_missing_source_data(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Kanjium pitch accent data is unavailable.');

        (new KanjiumPitchAccentStore('/missing/accents.txt'))->candidates('会社');
    }
}

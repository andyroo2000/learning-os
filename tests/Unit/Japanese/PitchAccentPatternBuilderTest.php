<?php

namespace Tests\Unit\Japanese;

use App\Domain\Japanese\Data\KanjiumPitchCandidate;
use App\Domain\Japanese\Services\PitchAccentPatternBuilder;
use Tests\TestCase;

class PitchAccentPatternBuilderTest extends TestCase
{
    public function test_it_builds_heiban_morae_and_drops_the_particle_pitch(): void
    {
        $pattern = (new PitchAccentPatternBuilder)->build(
            new KanjiumPitchCandidate('会社', 'かいしゃ', 0),
        );

        $this->assertSame(['か', 'い', 'しゃ'], $pattern['morae']);
        $this->assertSame([0, 1, 1], $pattern['pattern']);
        $this->assertSame('平板', $pattern['patternName']);
    }

    public function test_it_builds_atamadaka_nakadaka_and_odaka_patterns(): void
    {
        $builder = new PitchAccentPatternBuilder;

        $this->assertSame(
            [1, 0],
            $builder->build(new KanjiumPitchCandidate('雨', 'あめ', 1))['pattern'],
        );
        $this->assertSame(
            [0, 1, 1, 0, 0, 0],
            $builder->build(new KanjiumPitchCandidate('中学校', 'ちゅうがっこう', 3))['pattern'],
        );
        $this->assertSame(
            [0, 1],
            $builder->build(new KanjiumPitchCandidate('橋', 'はし', 2))['pattern'],
        );
    }
}

<?php

namespace App\Domain\Japanese\Services;

use App\Domain\Japanese\Data\KanjiumPitchCandidate;

class PitchAccentPatternBuilder
{
    private const DIGRAPHS = [
        'ぁ', 'ぃ', 'ぅ', 'ぇ', 'ぉ', 'ゃ', 'ゅ', 'ょ', 'ゎ', 'ゕ', 'ゖ',
        'ァ', 'ィ', 'ゥ', 'ェ', 'ォ', 'ャ', 'ュ', 'ョ', 'ヮ', 'ヵ', 'ヶ',
    ];

    /**
     * @return array{
     *   expression: string,
     *   reading: string,
     *   pitchNum: int,
     *   morae: list<string>,
     *   pattern: list<int>,
     *   patternName: string
     * }
     */
    public function build(KanjiumPitchCandidate $candidate): array
    {
        $morae = $this->morae($candidate->reading);
        $pattern = array_slice(
            $this->pattern(count($morae), $candidate->pitchNumber),
            0,
            count($morae),
        );

        return [
            'expression' => $candidate->surface,
            'reading' => $candidate->reading,
            'pitchNum' => $candidate->pitchNumber,
            'morae' => $morae,
            'pattern' => $pattern,
            'patternName' => $this->patternName(count($morae), $candidate->pitchNumber),
        ];
    }

    /**
     * @return list<string>
     */
    public function morae(string $reading): array
    {
        $morae = [];

        foreach (mb_str_split($reading) as $character) {
            if (in_array($character, self::DIGRAPHS, true) && $morae !== []) {
                $last = array_key_last($morae);
                $morae[$last] .= $character;
            } else {
                $morae[] = $character;
            }
        }

        return $morae;
    }

    /**
     * Includes the following particle pitch, matching hatsuon's output.
     *
     * @return list<int>
     */
    private function pattern(int $moraCount, int $pitchNumber): array
    {
        if ($moraCount < 1) {
            return [];
        }

        return match ($this->patternName($moraCount, $pitchNumber)) {
            '平板' => [0, ...array_fill(0, $moraCount, 1)],
            '頭高' => [1, ...array_fill(0, $moraCount, 0)],
            '尾高' => [0, ...array_fill(0, $moraCount - 1, 1), 0],
            '中高' => [
                0,
                ...array_fill(0, $pitchNumber - 1, 1),
                ...array_fill(0, $moraCount - $pitchNumber, 0),
                0,
            ],
            default => [],
        };
    }

    private function patternName(int $moraCount, int $pitchNumber): string
    {
        return match (true) {
            $pitchNumber === 0 => '平板',
            $pitchNumber === 1 => '頭高',
            $pitchNumber > 1 && $pitchNumber < $moraCount => '中高',
            $pitchNumber > 1 && $pitchNumber === $moraCount => '尾高',
            default => '不詳',
        };
    }
}

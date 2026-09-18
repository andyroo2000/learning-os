<?php

namespace App\Domain\Study\Support;

final class StudyRubyText
{
    public static function hasRuby(string $value): bool
    {
        return preg_match('/[\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{f900}-\x{faff}々\x{3040}-\x{30ff}]+\[(?!\.\.\.\])[^\]]+]/u', $value) === 1;
    }

    public static function rubyPlainText(string $value): string
    {
        return preg_replace(
            '/([\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{f900}-\x{faff}々\x{3040}-\x{30ff}]+)\[(?!\.\.\.\])[^\]]+]/u',
            '$1',
            $value,
        ) ?? $value;
    }

    public static function withoutWhitespace(string $value): string
    {
        return preg_replace('/\s+/u', '', $value) ?? $value;
    }

    public static function sliceRuby(string $ruby, int $start, int $end): string
    {
        preg_match_all(
            '/([\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{f900}-\x{faff}々\x{3040}-\x{30ff}]+\[(?!\.\.\.\])[^\]]+]|.)/us',
            $ruby,
            $matches,
        );

        $offset = 0;
        $result = '';
        foreach ($matches[0] as $segment) {
            $plain = self::rubyPlainText($segment);
            $segmentStart = $offset;
            $segmentEnd = $offset + mb_strlen($plain);
            $offset = $segmentEnd;

            $sliceStart = max($start, $segmentStart);
            $sliceEnd = min($end, $segmentEnd);
            if ($sliceStart >= $sliceEnd) {
                continue;
            }

            if (self::containsCompleteRubySegment(
                $segment,
                [$sliceStart, $sliceEnd],
                [$segmentStart, $segmentEnd],
            )) {
                $result .= $segment;
            } else {
                $result .= mb_substr($plain, $sliceStart - $segmentStart, $sliceEnd - $sliceStart);
            }
        }

        return $result;
    }

    /**
     * @param  array{0:int,1:int}  $slice
     * @param  array{0:int,1:int}  $segmentBounds
     */
    private static function containsCompleteRubySegment(
        string $segment,
        array $slice,
        array $segmentBounds,
    ): bool {
        [$sliceStart, $sliceEnd] = $slice;
        [$segmentStart, $segmentEnd] = $segmentBounds;

        return $sliceStart === $segmentStart
            && $sliceEnd === $segmentEnd
            && self::hasRuby($segment);
    }
}

<?php

namespace App\Domain\Journal\Support;

final class JournalSentenceTimings
{
    public static function build(string $text, array $segments): array
    {
        $timings = self::indexedSegments($segments);
        if (self::normalized($text) !== implode('', array_column($timings, 'text'))) {
            return []; // Never highlight text against a different or incomplete transcript.
        }
        $offset = 0;
        $result = [];
        foreach (self::sentences($text) as $sentence) {
            $length = mb_strlen(self::normalized($sentence));
            $overlap = array_values(array_filter($timings, fn (array $item) => $item['from'] < $offset + $length && $item['to'] > $offset));
            if ($overlap === [] || $overlap[0]['from'] !== $offset) {
                return []; // The provider must supply an actual boundary, not an estimated one.
            }
            $result[] = ['text' => $sentence, 'start' => $overlap[0]['start'], 'end' => $overlap[array_key_last($overlap)]['end']];
            $offset += $length;
        }

        return $result;
    }

    private static function sentences(string $text): array
    {
        preg_match_all('/[^。！？!?\n]*[。！？!?]+[」』”"]*\s*|[^。！？!?\n]+(?:\n|$)|\n/u', $text, $matches);
        $sentences = [];
        $prefix = '';
        foreach ($matches[0] as $part) {
            if (self::normalized($part) === '') {
                if ($sentences === []) {
                    $prefix .= $part;
                } else {
                    $sentences[array_key_last($sentences)] .= $part;
                }

                continue;
            }
            $sentences[] = $prefix.$part;
            $prefix = '';
        }

        return $sentences;
    }

    private static function indexedSegments(array $segments): array
    {
        $offset = 0;
        $result = [];
        foreach ($segments as $segment) {
            $text = self::normalized($segment['text']);
            $length = mb_strlen($text);
            if ($length === 0) {
                continue;
            }
            $result[] = [...$segment, 'text' => $text, 'from' => $offset, 'to' => $offset + $length];
            $offset += $length;
        }

        return $result;
    }

    private static function normalized(string $text): string
    {
        // Fish normalizes full-width Latin characters and digits in its alignment text.
        return preg_replace('/[\p{P}\p{Z}\s]+/u', '', mb_convert_kana($text, 'as', 'UTF-8'));
    }
}

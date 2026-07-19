<?php

namespace App\Domain\Japanese\Services;

use App\Domain\Japanese\Data\KanjiumPitchCandidate;
use Throwable;

class PitchAccentResolver
{
    public function __construct(
        private readonly KanjiumPitchAccentStore $store,
        private readonly PitchAccentPatternBuilder $patterns,
        private readonly OpenAiPitchAccentReadingSelector $readingSelector,
    ) {}

    /**
     * @param  array<string, mixed>|null  $cached
     * @return array<string, mixed>
     */
    public function resolve(
        ?string $expression,
        ?string $expressionReading = null,
        ?string $promptReading = null,
        ?string $answerAudioTextOverride = null,
        ?string $sentence = null,
        ?string $sentenceReading = null,
        ?array $cached = null,
    ): array {
        if (($cached['status'] ?? null) === 'resolved') {
            return $cached;
        }

        $expression = trim($expression ?? '');
        if ($expression === '') {
            return $this->unresolved('', 'no-expression');
        }

        if (preg_match('/[\x{3040}-\x{30ff}\x{4e00}-\x{9faf}]/u', $expression) !== 1) {
            return $this->unresolved($expression, 'not-japanese');
        }

        $candidates = $this->store->candidates($expression);
        if ($candidates === []) {
            return $this->unresolved($expression, 'not-found');
        }

        $candidate = $this->findByReading($candidates, $this->readingCandidates([
            $expressionReading,
            $promptReading,
            $answerAudioTextOverride,
            $sentenceReading,
        ]));
        if ($candidate !== null) {
            return $this->resolved($candidate, $candidates, 'local-reading');
        }

        $readings = array_values(array_unique(array_map(
            fn (KanjiumPitchCandidate $entry): string => $entry->reading,
            $candidates,
        )));
        if (count($readings) === 1) {
            return $this->resolved($candidates[0], $candidates, 'single-candidate');
        }

        try {
            $selected = $this->readingSelector->select($expression, $sentence, $readings);
        } catch (Throwable) {
            $selected = '';
        }

        $match = $this->findByReading($candidates, [trim($selected)]);
        if ($match !== null) {
            return $this->resolved($match, $candidates, 'llm');
        }

        return $this->unresolved($expression, 'ambiguous-reading', 'llm');
    }

    /**
     * @param  list<KanjiumPitchCandidate>  $candidates
     * @param  list<string>  $readings
     */
    private function findByReading(array $candidates, array $readings): ?KanjiumPitchCandidate
    {
        foreach ($readings as $reading) {
            foreach ($candidates as $candidate) {
                if ($candidate->reading === $reading) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<?string>  $fields
     * @return list<string>
     */
    private function readingCandidates(array $fields): array
    {
        $candidates = [];

        foreach ($fields as $index => $field) {
            if (! is_string($field) || $field === '') {
                continue;
            }

            $values = $index < 2 ? $this->bracketReadings($field) : [];
            foreach ($values === [] ? [$field] : $values as $value) {
                $reading = $this->kanaOnly($value);
                if ($reading !== '' && ! in_array($reading, $candidates, true)) {
                    $candidates[] = $reading;
                }
            }
        }

        return $candidates;
    }

    /**
     * @return list<string>
     */
    private function bracketReadings(string $value): array
    {
        preg_match_all('/\[([^\]]+)]|\(([^)]+)\)/u', $value, $matches, PREG_SET_ORDER);

        return array_values(array_map(
            fn (array $match): string => $match[1] !== '' ? $match[1] : $match[2],
            $matches,
        ));
    }

    private function kanaOnly(string $value): string
    {
        $value = mb_convert_kana($value, 'c', 'UTF-8');

        return preg_replace('/[^\x{3041}-\x{3096}ー]/u', '', $value) ?? '';
    }

    /**
     * @param  list<KanjiumPitchCandidate>  $candidates
     * @return array<string, mixed>
     */
    private function resolved(
        KanjiumPitchCandidate $candidate,
        array $candidates,
        string $resolvedBy,
    ): array {
        $primary = $this->patterns->build($candidate);
        $alternatives = [];

        foreach ($candidates as $entry) {
            if ($entry === $candidate) {
                continue;
            }

            $alternative = $this->patterns->build($entry);
            unset($alternative['expression']);
            $alternatives[] = $alternative;
        }

        return [
            'status' => 'resolved',
            ...$primary,
            'source' => 'kanjium',
            'resolvedBy' => $resolvedBy,
            ...($alternatives === [] ? [] : ['alternatives' => $alternatives]),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function unresolved(
        string $expression,
        string $reason,
        string $resolvedBy = 'none',
    ): array {
        return [
            'status' => 'unresolved',
            'expression' => $expression,
            'reason' => $reason,
            'source' => 'kanjium',
            'resolvedBy' => $resolvedBy,
        ];
    }
}

<?php

namespace Tests\Unit\Japanese;

use App\Domain\Japanese\Data\KanjiumPitchCandidate;
use App\Domain\Japanese\Services\KanjiumPitchAccentStore;
use App\Domain\Japanese\Services\OpenAiPitchAccentReadingSelector;
use App\Domain\Japanese\Services\PitchAccentPatternBuilder;
use App\Domain\Japanese\Services\PitchAccentResolver;
use RuntimeException;
use Tests\TestCase;

class PitchAccentResolverTest extends TestCase
{
    public function test_it_resolves_a_bracket_reading_without_using_the_provider(): void
    {
        $selector = $this->selector('');
        $resolver = $this->resolver([
            new KanjiumPitchCandidate('上手', 'じょうず', 3),
            new KanjiumPitchCandidate('上手', 'うわて', 0),
        ], $selector);

        $resolved = $resolver->resolve('上手', expressionReading: '上手[じょうず]');

        $this->assertSame('resolved', $resolved['status']);
        $this->assertSame('じょうず', $resolved['reading']);
        $this->assertSame('local-reading', $resolved['resolvedBy']);
        $this->assertSame(0, $selector->calls);
    }

    public function test_it_uses_the_provider_only_for_ambiguous_readings(): void
    {
        $selector = $this->selector('にっぽん');
        $resolver = $this->resolver([
            new KanjiumPitchCandidate('日本', 'にほん', 2),
            new KanjiumPitchCandidate('日本', 'にっぽん', 3),
        ], $selector);

        $resolved = $resolver->resolve('日本', sentence: '日本代表を応援します。');

        $this->assertSame('にっぽん', $resolved['reading']);
        $this->assertSame('llm', $resolved['resolvedBy']);
        $this->assertSame(1, $selector->calls);
    }

    public function test_it_retries_unresolved_cache_entries_but_returns_resolved_entries_unchanged(): void
    {
        $selector = $this->selector('');
        $resolver = $this->resolver([
            new KanjiumPitchCandidate('会社', 'かいしゃ', 0),
        ], $selector);
        $unresolved = [
            'status' => 'unresolved',
            'expression' => '会社',
            'reason' => 'not-found',
            'source' => 'kanjium',
            'resolvedBy' => 'none',
        ];

        $retried = $resolver->resolve('会社', expressionReading: 'かいしゃ', cached: $unresolved);
        $cached = $resolver->resolve('会社', cached: $retried);

        $this->assertSame('resolved', $retried['status']);
        $this->assertSame($retried, $cached);
        $this->assertSame(0, $selector->calls);
    }

    public function test_it_returns_unresolved_metadata_when_the_provider_fails(): void
    {
        $selector = new class extends OpenAiPitchAccentReadingSelector
        {
            public function __construct() {}

            public function select(string $expression, ?string $sentence, array $candidates): string
            {
                throw new RuntimeException('provider unavailable');
            }
        };
        $resolver = $this->resolver([
            new KanjiumPitchCandidate('日本', 'にほん', 2),
            new KanjiumPitchCandidate('日本', 'にっぽん', 3),
        ], $selector);

        $unresolved = $resolver->resolve('日本');

        $this->assertSame('unresolved', $unresolved['status']);
        $this->assertSame('ambiguous-reading', $unresolved['reason']);
        $this->assertSame('llm', $unresolved['resolvedBy']);
    }

    /**
     * @param  list<KanjiumPitchCandidate>  $candidates
     */
    private function resolver(
        array $candidates,
        OpenAiPitchAccentReadingSelector $selector,
    ): PitchAccentResolver {
        $store = new class($candidates) extends KanjiumPitchAccentStore
        {
            /**
             * @param  list<KanjiumPitchCandidate>  $candidates
             */
            public function __construct(private readonly array $candidates) {}

            public function candidates(string $expression): array
            {
                return array_values(array_filter(
                    $this->candidates,
                    fn (KanjiumPitchCandidate $candidate): bool => $candidate->surface === $expression,
                ));
            }
        };

        return new PitchAccentResolver(
            $store,
            new PitchAccentPatternBuilder,
            $selector,
        );
    }

    private function selector(string $reading): OpenAiPitchAccentReadingSelector
    {
        return new class($reading) extends OpenAiPitchAccentReadingSelector
        {
            public int $calls = 0;

            public function __construct(private readonly string $reading) {}

            public function select(string $expression, ?string $sentence, array $candidates): string
            {
                $this->calls++;

                return $this->reading;
            }
        };
    }
}

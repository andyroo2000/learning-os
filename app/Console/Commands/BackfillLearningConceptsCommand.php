<?php

namespace App\Console\Commands;

use App\Domain\Flashcards\Models\Card;
use App\Domain\Japanese\Contracts\JapaneseTokenizer;
use App\Domain\Japanese\Exceptions\JapaneseTokenizationException;
use App\Domain\Study\Actions\MatchLearningConceptsForCardAction;
use App\Domain\Study\Enums\LearningConceptMatchSource;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class BackfillLearningConceptsCommand extends Command
{
    protected $signature = 'learning-concepts:backfill {--after= : Resume after this card ULID} {--chunk=500 : Number of cards per batch (1-2000)} {--dry-run : Report matches without writing links}';

    protected $description = 'Match active cards to the versioned JLPT learning-concept catalog';

    public function handle(MatchLearningConceptsForCardAction $matcher, JapaneseTokenizer $tokenizer): int
    {
        try {
            [$after, $chunk] = $this->validatedOptions();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $processed = $matchedCards = $links = 0;

        $tokenizer->tokenize(['日本語']);

        if ($tokenizer->hadFailure()) {
            $this->error('Japanese tokenization is unavailable. No cards were changed; restore MeCab and rerun the backfill.');

            return self::FAILURE;
        }

        while (true) {
            $cards = $this->activeCards($after)->limit($chunk)->get();

            if ($cards->isEmpty()) {
                break;
            }

            foreach ($cards as $card) {
                try {
                    $result = $matcher->handle($card, LearningConceptMatchSource::Backfill, ! $dryRun);
                } catch (JapaneseTokenizationException) {
                    $this->error('Japanese tokenization failed during the backfill. Restore MeCab and rerun from the beginning.');

                    return self::FAILURE;
                }
                $processed++;
                $matchedCards += $result->conceptCount > 0 ? 1 : 0;
                $links += $result->conceptCount;
                $after = (string) $card->getKey();
            }

            $this->line("Processed {$processed} cards; resume cursor: {$after}");
        }

        $mode = $dryRun ? 'Dry run' : 'Backfill';
        $this->info("{$mode} complete: {$processed} cards, {$matchedCards} matched cards, {$links} concept links.");

        return self::SUCCESS;
    }

    /** @return array{string|null, int} */
    private function validatedOptions(): array
    {
        $after = $this->option('after');
        $after = is_string($after) && trim($after) !== '' ? trim($after) : null;

        if ($after !== null && ! Str::isUlid($after)) {
            throw new InvalidArgumentException('The --after value must be a valid card ULID.');
        }

        $after = $after === null ? null : CanonicalUlid::normalize($after);

        $chunkOption = $this->option('chunk');

        if (! is_numeric($chunkOption) || (string) (int) $chunkOption !== (string) $chunkOption) {
            throw new InvalidArgumentException('The --chunk value must be an integer from 1 through 2000.');
        }

        $chunk = (int) $chunkOption;

        if ($chunk < 1 || $chunk > 2000) {
            throw new InvalidArgumentException('The --chunk value must be an integer from 1 through 2000.');
        }

        return [$after, $chunk];
    }

    /** @return Builder<Card> */
    private function activeCards(?string $after): Builder
    {
        return Card::query()
            ->select('cards.*')
            ->join('decks', 'decks.id', '=', 'cards.deck_id')
            ->whereNull('decks.deleted_at')
            ->when($after !== null, fn (Builder $query) => $query->where('cards.id', '>', $after))
            ->orderBy('cards.id');
    }
}

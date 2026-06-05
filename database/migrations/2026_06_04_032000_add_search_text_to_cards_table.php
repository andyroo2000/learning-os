<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->text('search_text')
                ->nullable()
                ->after('answer_json');
        });

        DB::table('cards')
            ->select(['id', 'front_text', 'back_text', 'prompt_json', 'answer_json'])
            ->orderBy('id')
            ->chunkById(500, function ($cards): void {
                foreach ($cards as $card) {
                    DB::table('cards')
                        ->where('id', $card->id)
                        ->update([
                            'search_text' => $this->searchTextFromContent(
                                frontText: $card->front_text,
                                backText: $card->back_text,
                                promptJson: $this->decodeJsonColumn($card->prompt_json),
                                answerJson: $this->decodeJsonColumn($card->answer_json),
                            ),
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table): void {
            $table->dropColumn('search_text');
        });
    }

    private function decodeJsonColumn(mixed $value): ?array
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, associative: true);

        return is_array($decoded) ? $decoded : null;
    }

    // Keep the backfill self-contained so future app helper changes cannot rewrite migration behavior.
    private function searchTextFromContent(
        ?string $frontText,
        ?string $backText,
        mixed $promptJson = null,
        mixed $answerJson = null,
    ): string {
        return $this->collapseWhitespace(implode(' ', array_filter([
            $frontText,
            $backText,
            ...$this->flattenJson($promptJson),
            ...$this->flattenJson($answerJson),
        ], fn (mixed $part): bool => is_string($part) && trim($part) !== '')));
    }

    /**
     * @return list<string>
     */
    private function flattenJson(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_scalar($value)) {
            return [$this->scalarToText($value)];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->flatMap(fn (mixed $item): array => $this->flattenJson($item))
            ->all();
    }

    private function scalarToText(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function collapseWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
};

<?php

namespace App\Domain\Journal\Support;

use RuntimeException;

final class JournalCombinedResult
{
    public static function validated(string $body, array $output): array
    {
        $state = JournalClarificationResult::validated(array_diff_key($output, ['feedback' => true]));
        $feedback = $output['feedback'] ?? null;
        if ($feedback === null) {
            return $state; // Older meaning-only results can use the ordinary feedback path.
        }
        if (! $state['resolved'] || ! is_array($feedback)) {
            throw new RuntimeException('Unresolved meaning cannot produce journal feedback.');
        }

        return [...$state, 'feedback' => JournalCompactFeedback::validated($body, $feedback)];
    }
}

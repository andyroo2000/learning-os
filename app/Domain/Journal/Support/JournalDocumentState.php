<?php

namespace App\Domain\Journal\Support;

use App\Domain\Journal\Models\JournalAnalysis;
use App\Domain\Journal\Models\JournalReply;
use Illuminate\Validation\ValidationException;

final class JournalDocumentState
{
    public static function current(JournalAnalysis $analysis): array
    {
        if (isset($analysis->result['document'])) {
            return $analysis->result['document'];
        }
        $reply = JournalReply::where('analysis_id', $analysis->id)->find($analysis->current_reply_id);

        return self::atReply($analysis, $reply);
    }

    public static function atReply(JournalAnalysis $analysis, ?JournalReply $reply): array
    {
        if ($reply === null) {
            return JournalDocument::fromFeedback($analysis->result, $analysis->id);
        }
        $result = $reply->result;

        return $result['document'] ?? JournalDocument::fromFeedback([
            'correctedText' => $result['entryText'] ?? $result['correctedText'],
            'correctedReading' => $result['entryReading'] ?? $result['correctedReading'],
        ], $reply->id);
    }

    public static function beforeReply(JournalAnalysis $analysis, JournalReply $reply): array
    {
        if (isset($analysis->result['document'])) {
            $blocks = $analysis->result['document'];
            $index = array_search($reply->id.':boundary', array_column($blocks, 'id'), true);
            if ($index === false) {
                throw ValidationException::withMessages(['answer' => 'This answer overlaps manually edited text. Edit the corrected entry directly instead.']);
            }

            return array_slice($blocks, 0, $index);
        }
        $parent = JournalReply::where('analysis_id', $analysis->id)->find($reply->parent_id);

        return self::atReply($analysis, $parent);
    }

    public static function payload(JournalAnalysis $analysis): ?array
    {
        if (! isset($analysis->result['correctedText'], $analysis->result['correctedReading'])) {
            return null;
        }
        $blocks = self::current($analysis);
        $rendered = JournalDocument::render($blocks);
        $suggestions = [];
        foreach ($blocks as $block) {
            if ($block['id'] !== null && $block['replacement'] !== null) {
                $suggestions[] = ['id' => $block['id'], 'applied' => $block['applied']];
            }
        }

        return ['text' => $rendered->text, 'reading' => $rendered->reading, 'suggestions' => $suggestions];
    }
}

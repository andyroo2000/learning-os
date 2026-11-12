<?php

namespace App\Domain\Journal\Support;

use App\Support\Japanese\BracketFurigana;
use Illuminate\Validation\ValidationException;

/** A corrected entry whose untouched spans retain their suggestion identity. */
final class JournalDocument
{
    public static function fromFeedback(array $feedback, string $source): array
    {
        if (($feedback['editVersion'] ?? null) !== 1) {
            return [self::plain(new JournalText($feedback['correctedText'], $feedback['correctedReading']))];
        }

        return array_map(fn (array $span, int $index) => [
            'id' => $source.':'.$index,
            'text' => $span['text'], 'reading' => $span['reading'],
            'replacement' => $span['replacement'], 'replacementReading' => $span['replacementReading'],
            'applied' => $span['kind'] === 'correction',
        ], $feedback['segments'], array_keys($feedback['segments']));
    }

    public static function render(array $blocks): JournalText
    {
        $text = '';
        $reading = '';
        foreach ($blocks as $block) {
            $value = self::value($block);
            $text .= $value->text;
            $reading .= $value->reading;
        }

        return new JournalText($text, $reading);
    }

    public static function toggle(array $blocks, string $id, bool $applied): array
    {
        $index = array_search($id, array_column($blocks, 'id'), true);
        if ($index === false || $blocks[$index]['replacement'] === null) {
            throw ValidationException::withMessages(['suggestionId' => 'This suggestion overlaps manually edited text or is no longer available.']);
        }
        $blocks[$index]['applied'] = $applied;
        self::render($blocks)->assertEntryLength();

        return $blocks;
    }

    /** Preserve suggestion identities outside the manually changed range. */
    public static function editPlan(array $blocks, string $text): array
    {
        (new JournalText($text, ''))->assertEntryLength();
        $previous = self::render($blocks)->text;
        if ($previous === $text) {
            return ['before' => $blocks, 'after' => [], 'text' => ''];
        }
        [$prefix, $suffix] = self::commonEdges($previous, $text);
        [$before, $after] = self::outsideRange($blocks, $prefix, mb_strlen($previous) - $suffix);
        $leading = mb_strlen(self::render($before)->text);
        $trailing = mb_strlen(self::render($after)->text);

        return ['before' => $before, 'after' => $after,
            'text' => mb_substr($text, $leading, mb_strlen($text) - $leading - $trailing)];
    }

    public static function finishEdit(array $plan, string $reading): array
    {
        BracketFurigana::validate($plan['text'], $reading);

        return [...$plan['before'], self::plain(new JournalText($plan['text'], $reading)), ...$plan['after']];
    }

    public static function append(array $blocks, array $feedback, string $source): array
    {
        $result = [...$blocks, self::plain(new JournalText("\n\n", "\n\n")), ...self::fromFeedback($feedback, $source)];
        self::render($result)->assertEntryLength();

        return $result;
    }

    private static function plain(JournalText $value): array
    {
        return ['id' => null, 'text' => $value->text, 'reading' => $value->reading,
            'replacement' => null, 'replacementReading' => null, 'applied' => false];
    }

    private static function value(array $block): JournalText
    {
        return $block['applied']
            ? new JournalText($block['replacement'], $block['replacementReading'])
            : new JournalText($block['text'], $block['reading']);
    }

    private static function commonEdges(string $old, string $new): array
    {
        $limit = min(mb_strlen($old), mb_strlen($new));
        $prefix = 0;
        while ($prefix < $limit && mb_substr($old, $prefix, 1) === mb_substr($new, $prefix, 1)) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < $limit - $prefix && mb_substr($old, -1 - $suffix, 1) === mb_substr($new, -1 - $suffix, 1)) {
            $suffix++;
        }

        return [$prefix, $suffix];
    }

    private static function outsideRange(array $blocks, int $start, int $end): array
    {
        $before = [];
        $after = [];
        $offset = 0;
        foreach ($blocks as $block) {
            $length = mb_strlen(self::value($block)->text);
            if ($offset + $length <= $start) {
                $before[] = $block;
            } elseif ($offset >= $end) {
                $after[] = $block;
            }
            $offset += $length;
        }

        return [$before, $after];
    }
}

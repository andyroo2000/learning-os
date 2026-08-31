<?php

namespace App\Domain\Study\Support;

use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Models\Card;

/**
 * Builds the versioned, read-only rendering contract for Study clients.
 *
 * Raw prompt/answer payloads remain the editing contract. This projection owns
 * the cross-client choices needed to render those payloads consistently.
 */
final class StudyCardPresentation
{
    public const VERSION = 1;

    private const VISUAL_PRODUCTION_LABELS = ['名詞', '動詞', '形容詞', '副詞', '表現'];

    private function __construct() {}

    /**
     * @return array{
     *   version: int,
     *   front: array{
     *     mode: 'cloze'|'media'|'text',
     *     text: ?string,
     *     ruby: ?string,
     *     hint: ?string,
     *     media: array{audio: ?array<string, mixed>, image: ?array<string, mixed>},
     *     autoplayAudio: bool
     *   },
     *   answer: array{
     *     heading: ?string,
     *     ruby: ?string,
     *     restored: ?string,
     *     meaning: ?string,
     *     sentences: array{
     *       japanese: array{text: ?string, ruby: ?string},
     *       english: array{text: ?string, ruby: null}
     *     },
     *     notes: list<string>,
     *     media: array{image: ?array<string, mixed>},
     *     audio: ?array<string, mixed>,
     *     pitchAccent: ?array<string, mixed>
     *   }
     * }
     */
    public static function fromCard(Card $card): array
    {
        $prompt = is_array($card->prompt_json) ? $card->prompt_json : [];
        $answer = is_array($card->answer_json) ? $card->answer_json : [];
        $cardType = self::cardType($card);

        $promptAudio = self::mediaReference($prompt, 'cueAudio');
        $promptImage = self::mediaReference($prompt, 'cueImage');
        $answerImage = self::mediaReference($answer, 'answerImage') ?? $promptImage;
        $answerAudio = $promptAudio ?? self::mediaReference($answer, 'answerAudio');
        $cloze = $cardType === CardType::Cloze->value
            ? self::deriveCloze(self::firstString($prompt, ['clozeText']))
            : null;

        $front = $cardType === CardType::Cloze->value
            ? self::clozeFront($prompt, $answer, $promptImage, $cloze)
            : self::standardFront($card, $cardType, $prompt, $answer, $promptAudio, $promptImage);

        return [
            'version' => self::VERSION,
            'front' => $front,
            'answer' => self::answer(
                card: $card,
                cardType: $cardType,
                prompt: $prompt,
                answer: $answer,
                answerImage: $answerImage,
                answerAudio: $answerAudio,
                cloze: $cloze,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $prompt
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>|null  $promptImage
     * @param  array{display: ?string, restored: ?string, hint: ?string, hasMarkup: bool}  $cloze
     * @return array{
     *   mode: 'cloze',
     *   text: ?string,
     *   ruby: ?string,
     *   hint: ?string,
     *   media: array{audio: null, image: ?array<string, mixed>},
     *   autoplayAudio: false
     * }
     */
    private static function clozeFront(
        array $prompt,
        array $answer,
        ?array $promptImage,
        array $cloze,
    ): array {
        $explicitDisplay = self::displayText(self::firstString($prompt, ['clozeDisplayText']));

        $display = $cloze['hasMarkup'] ? $cloze['display'] : $explicitDisplay;
        [$text, $inlineRuby] = self::textAndRuby($display, []);
        $restored = self::displayText(self::firstString($answer, ['restoredText']))
            ?? $cloze['restored'];
        $restoredRuby = self::displayText(self::firstString($answer, ['restoredTextReading']));

        return [
            'mode' => 'cloze',
            'text' => $text,
            'ruby' => self::maskedRuby($text, $restored, $restoredRuby) ?? $inlineRuby,
            'hint' => $cloze['hint']
                ?? self::displayText(self::firstString($prompt, ['clozeResolvedHint', 'clozeHint'])),
            'media' => [
                'audio' => null,
                'image' => $promptImage,
            ],
            'autoplayAudio' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $prompt
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>|null  $promptAudio
     * @param  array<string, mixed>|null  $promptImage
     * @return array{
     *   mode: 'media'|'text',
     *   text: ?string,
     *   ruby: ?string,
     *   hint: ?string,
     *   media: array{audio: ?array<string, mixed>, image: ?array<string, mixed>},
     *   autoplayAudio: bool
     * }
     */
    private static function standardFront(
        Card $card,
        string $cardType,
        array $prompt,
        array $answer,
        ?array $promptAudio,
        ?array $promptImage,
    ): array {
        $rawText = self::firstString($prompt, ['cueText', 'text']);
        if ($rawText === null && $prompt === []) {
            $rawText = is_string($card->front_text) ? $card->front_text : null;
        }

        [$text, $ruby] = self::textAndRuby($rawText, [
            self::firstString($prompt, ['cueReading']),
            self::firstString($answer, ['expressionReading']),
        ]);

        $cueMeaning = self::displayText(self::firstString($prompt, ['cueMeaning']));
        $hasRenderableMedia = self::isRenderableMedia($promptAudio)
            || self::isRenderableMedia($promptImage);
        $isMediaLed = $hasRenderableMedia && $text === null;
        $isVisualProduction = $cardType === CardType::Production->value
            && $isMediaLed
            && self::isRenderableMedia($promptImage)
            && ! self::isRenderableMedia($promptAudio)
            && $cueMeaning !== null
            && in_array($cueMeaning, self::VISUAL_PRODUCTION_LABELS, true);

        return [
            'mode' => $isMediaLed ? 'media' : 'text',
            'text' => $isMediaLed ? null : $text,
            'ruby' => $isMediaLed ? null : $ruby,
            'hint' => $isMediaLed
                ? ($isVisualProduction ? $cueMeaning : null)
                : $cueMeaning,
            'media' => [
                'audio' => $promptAudio,
                'image' => $promptImage,
            ],
            'autoplayAudio' => $cardType === CardType::Recognition->value
                && $isMediaLed
                && self::isRenderableMedia($promptAudio)
                && $cueMeaning === null,
        ];
    }

    /**
     * @param  array<string, mixed>  $prompt
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>|null  $answerImage
     * @param  array<string, mixed>|null  $answerAudio
     * @param  array{display: ?string, restored: ?string, hint: ?string, hasMarkup: bool}|null  $cloze
     * @return array{
     *   heading: ?string,
     *   ruby: ?string,
     *   restored: ?string,
     *   meaning: ?string,
     *   sentences: array{
     *     japanese: array{text: ?string, ruby: ?string},
     *     english: array{text: ?string, ruby: null}
     *   },
     *   notes: list<string>,
     *   media: array{image: ?array<string, mixed>},
     *   audio: ?array<string, mixed>,
     *   pitchAccent: ?array<string, mixed>
     * }
     */
    private static function answer(
        Card $card,
        string $cardType,
        array $prompt,
        array $answer,
        ?array $answerImage,
        ?array $answerAudio,
        ?array $cloze,
    ): array {
        $rawRestored = self::firstString($answer, ['restoredText']);
        $restored = self::displayText($rawRestored);
        $restoredRuby = self::displayText(self::firstString($answer, ['restoredTextReading']));

        if ($cardType === CardType::Cloze->value) {
            $cloze ??= self::emptyCloze();
            $restored ??= $cloze['restored'];
            [$heading, $ruby] = self::textAndRuby($restored, [$restoredRuby]);
            $restored = $heading;
        } else {
            $rawHeading = self::firstString($answer, ['expression', 'text']);
            if ($rawHeading === null && $answer === []) {
                $rawHeading = is_string($card->back_text) ? $card->back_text : null;
            }

            $headingCandidates = [
                self::firstString($answer, ['expressionReading']),
                self::firstString($prompt, ['cueReading']),
            ];
            $rawHeading ??= $headingCandidates[0] ?? $headingCandidates[1];

            [$heading, $ruby] = self::textAndRuby($rawHeading, $headingCandidates);
        }

        [$sentenceJapanese, $sentenceJapaneseRuby] = self::textAndRuby(
            self::firstString($answer, ['sentenceJp']),
            [self::firstString($answer, ['sentenceJpKana'])],
        );

        return [
            'heading' => $heading,
            'ruby' => $ruby,
            'restored' => $restored,
            'meaning' => self::displayText(self::firstString($answer, ['meaning'])),
            'sentences' => [
                'japanese' => [
                    'text' => $sentenceJapanese,
                    'ruby' => $sentenceJapaneseRuby,
                ],
                'english' => [
                    'text' => self::displayText(self::firstString($answer, ['sentenceEn'])),
                    'ruby' => null,
                ],
            ],
            'notes' => self::notes(self::firstString($answer, ['notes'])),
            'media' => ['image' => $answerImage],
            // Listening-card audio historically lived on the prompt. Keep one logical
            // card-audio rule instead of asking clients to know that storage detail.
            'audio' => $answerAudio,
            'pitchAccent' => self::pitchAccent($answer['pitchAccent'] ?? null),
        ];
    }

    private static function cardType(Card $card): string
    {
        $cardType = $card->card_type;

        return $cardType instanceof CardType
            ? $cardType->value
            : (is_string($cardType) && CardType::tryFrom($cardType) !== null
                ? $cardType
                : CardType::Recognition->value);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private static function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  list<?string>  $rubyCandidates
     * @return array{?string, ?string}
     */
    private static function textAndRuby(?string $rawText, array $rubyCandidates): array
    {
        $text = self::displayText($rawText);
        if ($text === null) {
            return [null, null];
        }

        $inlineRuby = self::hasRuby($text) ? $text : null;
        $plain = $inlineRuby === null ? $text : self::rubyPlainText($inlineRuby);

        foreach ([$inlineRuby, ...$rubyCandidates] as $candidate) {
            $ruby = self::displayText($candidate);
            if ($ruby === null || ! self::hasRuby($ruby)) {
                continue;
            }

            if (self::withoutWhitespace(self::rubyPlainText($ruby)) === self::withoutWhitespace($plain)) {
                return [$plain, $ruby];
            }
        }

        return [$plain, null];
    }

    private static function displayText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/<\s*br\s*\/?\s*>/iu', "\n", $value) ?? $value;
        $value = preg_replace('/<\/\s*(p|div|li)\s*>/iu', "\n", $value) ?? $value;
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\r", '', $value);
        $value = preg_replace('/[ \t]+\n/u', "\n", $value) ?? $value;
        $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? $value;
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private static function notes(?string $value): array
    {
        $value = self::displayText($value);
        if ($value === null) {
            return [];
        }

        return array_values(array_filter(array_map(
            static function (string $line): string {
                $line = preg_replace('/^[•\-\s]+/u', '', $line) ?? $line;

                return trim($line);
            },
            explode("\n", $value),
        ), static fn (string $line): bool => $line !== ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private static function mediaReference(array $payload, string $key): ?array
    {
        $value = $payload[$key] ?? null;

        return is_array($value) && $value !== [] && ! array_is_list($value) ? $value : null;
    }

    /** @param array<string, mixed>|null $reference */
    private static function isRenderableMedia(?array $reference): bool
    {
        $url = $reference['url'] ?? null;

        return is_string($url) && trim($url) !== '';
    }

    /**
     * @return array{display: ?string, restored: ?string, hint: ?string, hasMarkup: bool}
     */
    private static function deriveCloze(?string $rawText): array
    {
        $normalized = self::normalizeLooseCloze($rawText);
        $normalized = $normalized === null ? null : str_replace("\0", '', $normalized);
        if ($normalized === null || preg_match('/\{\{c\d+::/u', $normalized) !== 1) {
            return [
                'display' => null,
                'restored' => self::displayText($normalized),
                'hint' => null,
                'hasMarkup' => false,
            ];
        }

        $display = '';
        $restored = '';
        $hint = null;
        $cursor = 0;
        $matchCount = preg_match_all(
            '/\{\{c(\d+)::(.*?)(?:::(.*?))?\}\}/us',
            $normalized,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        if ($matchCount === false || $matchCount === 0) {
            return [
                'display' => null,
                'restored' => self::displayText($normalized),
                'hint' => null,
                'hasMarkup' => false,
            ];
        }

        foreach ($matches as $match) {
            $token = $match[0][0];
            $offset = $match[0][1];
            $leading = substr($normalized, $cursor, $offset - $cursor);
            $content = $match[2][0];
            $display .= $leading;
            $restored .= $leading.$content;

            if ($match[1][0] === '1') {
                $display .= '[...]';
                $inlineHint = self::displayText($match[3][0] ?? null);
                $hint ??= $inlineHint;
            } else {
                $display .= $content;
            }

            $cursor = $offset + strlen($token);
        }

        $trailing = substr($normalized, $cursor);

        return [
            'display' => self::displayText($display.$trailing),
            'restored' => self::displayText($restored.$trailing),
            'hint' => $hint,
            'hasMarkup' => true,
        ];
    }

    /**
     * @return array{display: null, restored: null, hint: null, hasMarkup: false}
     */
    private static function emptyCloze(): array
    {
        return [
            'display' => null,
            'restored' => null,
            'hint' => null,
            'hasMarkup' => false,
        ];
    }

    private static function normalizeLooseCloze(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);
        if ($value === null || $value === '' || preg_match('/\{\{c\d+::/u', $value) === 1) {
            return $value === '' ? null : $value;
        }

        preg_match_all('/\[([^\]]+)]/u', $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $found = false;
        $normalized = '';
        $cursor = 0;

        foreach ($matches as $match) {
            $token = $match[0][0];
            $offset = $match[0][1];
            $leading = substr($value, $cursor, $offset - $cursor);
            $normalized .= $leading;
            $sourcePrefix = substr($value, 0, $offset);
            $previous = $sourcePrefix === '' ? null : mb_substr($sourcePrefix, -1);
            $isFurigana = $previous !== null
                && preg_match('/[\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{f900}-\x{faff}々]/u', $previous) === 1
                && preg_match('/^[\x{3040}-\x{30ff}ー・]+$/u', $match[1][0]) === 1;

            if ($isFurigana) {
                $normalized .= $token;
            } else {
                $normalized .= '{{c1::'.$match[1][0].'}}';
                $found = true;
            }

            $cursor = $offset + strlen($token);
        }

        $normalized .= substr($value, $cursor);

        return $found ? $normalized : $value;
    }

    private static function maskedRuby(?string $display, ?string $restored, ?string $ruby): ?string
    {
        if ($display === null || $restored === null || $ruby === null || ! self::hasRuby($ruby)) {
            return null;
        }

        if (substr_count($display, '[...]') !== 1
            || self::withoutWhitespace(self::rubyPlainText($ruby)) !== self::withoutWhitespace($restored)) {
            return null;
        }

        [$prefix, $suffix] = explode('[...]', $display, 2);
        if (! str_starts_with($restored, $prefix) || ! str_ends_with($restored, $suffix)) {
            return null;
        }

        return self::sliceRuby($ruby, 0, mb_strlen($prefix))
            .'[...]'.self::sliceRuby($ruby, mb_strlen($restored) - mb_strlen($suffix), mb_strlen($restored));
    }

    private static function hasRuby(string $value): bool
    {
        return preg_match('/[\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{f900}-\x{faff}々\x{3040}-\x{30ff}]+\[(?!\.\.\.\])[^\]]+]/u', $value) === 1;
    }

    private static function rubyPlainText(string $value): string
    {
        return preg_replace(
            '/([\x{3400}-\x{4dbf}\x{4e00}-\x{9fff}\x{f900}-\x{faff}々\x{3040}-\x{30ff}]+)\[(?!\.\.\.\])[^\]]+]/u',
            '$1',
            $value,
        ) ?? $value;
    }

    private static function withoutWhitespace(string $value): string
    {
        return preg_replace('/\s+/u', '', $value) ?? $value;
    }

    private static function sliceRuby(string $ruby, int $start, int $end): string
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

            if ($sliceStart === $segmentStart && $sliceEnd === $segmentEnd && self::hasRuby($segment)) {
                $result .= $segment;
            } else {
                $result .= mb_substr($plain, $sliceStart - $segmentStart, $sliceEnd - $sliceStart);
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function pitchAccent(mixed $value): ?array
    {
        if (! is_array($value)
            || ($value['status'] ?? null) !== 'resolved'
            || ! self::isNonEmptyString($value['expression'] ?? null)
            || ! self::isNonEmptyString($value['reading'] ?? null)
            || ! self::isNonEmptyString($value['patternName'] ?? null)
            || ! is_array($value['morae'] ?? null)
            || ! array_is_list($value['morae'])
            || ! is_array($value['pattern'] ?? null)
            || ! array_is_list($value['pattern'])
            || count($value['morae']) === 0
            || count($value['morae']) !== count($value['pattern'])
            || array_filter($value['morae'], fn (mixed $mora): bool => ! self::isNonEmptyString($mora)) !== []
            || array_filter($value['pattern'], static fn (mixed $pitch): bool => ! is_int($pitch) || ! in_array($pitch, [0, 1], true)) !== []) {
            return null;
        }

        return [
            'status' => 'resolved',
            'expression' => trim($value['expression']),
            'reading' => trim($value['reading']),
            'pitchNum' => is_int($value['pitchNum'] ?? null) ? $value['pitchNum'] : null,
            'morae' => array_values($value['morae']),
            'pattern' => array_values($value['pattern']),
            'patternName' => trim($value['patternName']),
            'source' => self::isNonEmptyString($value['source'] ?? null) ? trim($value['source']) : null,
            'resolvedBy' => self::isNonEmptyString($value['resolvedBy'] ?? null) ? trim($value['resolvedBy']) : null,
        ];
    }

    private static function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}

<?php

namespace App\Domain\Study\Support;

use App\Domain\Flashcards\Enums\CardStudyStatus;
use App\Domain\Flashcards\Enums\CardType;
use App\Domain\Flashcards\Support\CardSearchText;
use InvalidArgumentException;

final readonly class StudyBrowserListCriteria
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 100;

    public const ALLOWED_SORT_FIELDS = [
        'created_on',
        'updated_on',
        'sort_field',
        'note_type',
        'card_count',
        'review_count',
    ];

    public const ALLOWED_SORT_DIRECTIONS = [
        'asc',
        'desc',
    ];

    public int $userId;

    public ?string $q;

    public ?string $noteType;

    public ?CardType $cardType;

    public ?CardStudyStatus $queueState;

    public string $sortField;

    public string $sortDirection;

    public int $offset;

    public int $limit;

    public ?string $courseId;

    public ?string $deckId;

    /**
     * @param array{
     *     userId: int,
     *     q: string|null,
     *     noteType: string|null,
     *     cardType: CardType|string|null,
     *     queueState: CardStudyStatus|string|null,
     *     sortField: string|null,
     *     sortDirection: string|null,
     *     cursor: string|null,
     *     limit: int|null,
     *     courseId: string|null,
     *     deckId: string|null
     * } $input
     */
    public static function fromInput(array $input): self
    {
        $criteria = new self;
        $criteria->userId = $input['userId'];
        $criteria->q = CardSearchText::normalizeQuery($input['q']);
        $criteria->noteType = self::normalizeNoteType($input['noteType']);
        $criteria->cardType = self::normalizeCardType($input['cardType']);
        $criteria->queueState = self::normalizeQueueState($input['queueState']);
        $criteria->sortField = self::normalizeSortField($input['sortField']) ?? 'created_on';
        $criteria->sortDirection = self::normalizeSortDirection($input['sortDirection']) ?? 'desc';
        $criteria->limit = self::normalizeLimit($input['limit']);
        $criteria->offset = self::decodeOffsetCursor($input['cursor']);
        $criteria->courseId = StudyListScopeFilter::normalizeId($input['courseId'], 'courseId', 'Study browser');
        $criteria->deckId = StudyListScopeFilter::normalizeId($input['deckId'], 'deckId', 'Study browser');

        return $criteria;
    }

    /** @return array{offset: int} */
    public static function decodeCursorPayload(string $cursor): array
    {
        $payload = self::decodedCursorPayload($cursor);
        $offset = $payload['offset'] ?? null;

        if (! is_int($offset)) {
            throw self::invalidCursor();
        }

        if ($offset < 0) {
            throw self::invalidCursor();
        }

        return ['offset' => $offset];
    }

    /** @return array<mixed> */
    private static function decodedCursorPayload(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);

        if (! is_string($decoded)) {
            throw self::invalidCursor();
        }

        $payload = json_decode($decoded, true);

        if (! is_array($payload)) {
            throw self::invalidCursor();
        }

        return $payload;
    }

    private static function normalizeNoteType(?string $noteType): ?string
    {
        if ($noteType === null) {
            return null;
        }

        $noteType = trim($noteType);

        if ($noteType === '') {
            throw new InvalidArgumentException('Study browser noteType filter must not be blank when provided.');
        }

        return $noteType;
    }

    private static function normalizeCardType(CardType|string|null $cardType): ?CardType
    {
        return $cardType === null ? null : CardType::fromFilter($cardType);
    }

    private static function normalizeQueueState(CardStudyStatus|string|null $queueState): ?CardStudyStatus
    {
        return $queueState === null ? null : CardStudyStatus::fromFilter($queueState);
    }

    private static function normalizeSortField(?string $sortField): ?string
    {
        return self::normalizeAllowedValue(
            $sortField,
            self::ALLOWED_SORT_FIELDS,
            'Study browser sortField must be one of: '.implode(', ', self::ALLOWED_SORT_FIELDS).'.',
        );
    }

    private static function normalizeSortDirection(?string $sortDirection): ?string
    {
        return self::normalizeAllowedValue(
            $sortDirection,
            self::ALLOWED_SORT_DIRECTIONS,
            'Study browser sortDirection must be one of: '.implode(', ', self::ALLOWED_SORT_DIRECTIONS).'.',
        );
    }

    /** @param list<string> $allowedValues */
    private static function normalizeAllowedValue(?string $value, array $allowedValues, string $message): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        if (! in_array($value, $allowedValues, true)) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    private static function normalizeLimit(?int $limit): int
    {
        if ($limit === null) {
            return self::DEFAULT_LIMIT;
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('limit must be an integer between 1 and '.self::MAX_LIMIT.'.');
        }

        return $limit;
    }

    private static function decodeOffsetCursor(?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }

        return self::decodeCursorPayload($cursor)['offset'];
    }

    private static function invalidCursor(): InvalidArgumentException
    {
        return new InvalidArgumentException('Study browser cursor is invalid.');
    }
}

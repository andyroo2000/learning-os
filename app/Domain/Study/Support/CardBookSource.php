<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Models\StudyPileChapter;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CardBookSource
{
    public static function rules(string $key): array
    {
        return [
            $key => ['sometimes', 'nullable', 'array:pileId,chapterId,position,reference'],
            $key.'.pileId' => ['required_with:'.$key, 'ulid'],
            $key.'.chapterId' => ['sometimes', 'nullable', 'ulid'],
            $key.'.position' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            $key.'.reference' => ['sometimes', 'nullable', 'string', 'max:512'],
        ];
    }

    public static function normalized(?array $input): ?array
    {
        if ($input === null) {
            return null;
        }
        foreach (['pileId', 'chapterId'] as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = CanonicalUlid::normalize($input[$key]);
            }
        }
        $source = Validator::make(['bookSource' => $input], self::rules('bookSource'))->validate()['bookSource'];
        if (! isset($source['pileId'])) {
            throw ValidationException::withMessages(['bookSource.pileId' => ['A source pile is required.']]);
        }

        return [
            'pileId' => $source['pileId'],
            'chapterId' => $source['chapterId'] ?? null,
            'position' => isset($source['position']) ? (int) $source['position'] : null,
            'reference' => isset($source['reference']) ? trim($source['reference']) : null,
        ];
    }

    public static function assertOwned(int $userId, ?array $source): void
    {
        if ($source === null) {
            return;
        }
        StudyPileAccess::owned($userId, $source['pileId']);
        if ($source['chapterId'] !== null) {
            StudyPileChapter::query()->whereKey($source['chapterId'])
                ->where('pile_id', $source['pileId'])->firstOrFail();
        }
    }

    public static function apply(Model $record, ?array $source): void
    {
        $record->book_pile_id = $source['pileId'] ?? null;
        $record->book_chapter_id = $source['chapterId'] ?? null;
        $record->book_position = $source['position'] ?? null;
        $record->book_reference = $source['reference'] ?? null;
    }

    public static function fields(object $record, string $key): array
    {
        $source = self::payload($record);

        return $source === null ? [] : [$key => $source];
    }

    public static function payload(object $record): ?array
    {
        if (($record->book_pile_id ?? null) === null) {
            return null;
        }

        return [
            'pileId' => $record->book_pile_id,
            'chapterId' => $record->book_chapter_id,
            'position' => $record->book_position === null ? null : (int) $record->book_position,
            'reference' => $record->book_reference,
        ];
    }
}

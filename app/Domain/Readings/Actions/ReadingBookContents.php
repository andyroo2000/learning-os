<?php

namespace App\Domain\Readings\Actions;

use App\Domain\Readings\Models\Reading;
use App\Domain\Readings\Models\ReadingBook;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ReadingBookContents
{
    public function __construct(private readonly ReadingBooks $books) {}

    public function ids(string $bookId): array
    {
        return DB::table('reading_book_entries')->where('book_id', $bookId)
            ->orderBy('position')->pluck('reading_id')->all();
    }

    public function present(ReadingBook $book): array
    {
        $ids = $this->ids($book->id);
        $readings = Reading::query()->where('user_id', $book->user_id)->whereIn('id', $ids)->get()->keyBy('id');

        return [...$book->summary(), 'readings' => array_map(function (string $id) use ($readings): array {
            $reading = $readings->get($id);

            return [...$reading->summary(), 'pageNumbers' => array_column($reading->document['pages'], 'number')];
        }, $ids)];
    }

    public function replace(int $userId, string $id, int $revision, array $ids): ReadingBook
    {
        return DB::transaction(function () use ($userId, $id, $revision, $ids): ReadingBook {
            // All book writes for an owner share this lock, including membership changes.
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $book = $this->books->owned($userId, $id);
            abort_unless($book->revision === $revision, 409, 'Refresh the book before arranging readings.');
            $this->checkMembership($userId, $book->id, $ids);
            if ($this->ids($book->id) !== $ids) {
                $this->writeEntries($book, $ids);
            }

            return $book;
        });
    }

    private function checkMembership(int $userId, string $bookId, array $ids): void
    {
        $owned = Reading::query()->where('user_id', $userId)->whereIn('id', $ids)->count();
        abort_unless($owned === count($ids), 404);
        $elsewhere = DB::table('reading_book_entries')->whereIn('reading_id', $ids)->where('book_id', '!=', $bookId)->exists();
        abort_if($elsewhere, 409, 'Remove the reading from its current book before assigning it here.');
    }

    private function writeEntries(ReadingBook $book, array $ids): void
    {
        DB::table('reading_book_entries')->where('book_id', $book->id)->delete();
        foreach ($ids as $position => $id) {
            DB::table('reading_book_entries')->insert(['book_id' => $book->id, 'reading_id' => $id, 'position' => $position]);
        }
        $book->revision++;
        $book->save();
    }
}

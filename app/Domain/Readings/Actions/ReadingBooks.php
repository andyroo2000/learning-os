<?php

namespace App\Domain\Readings\Actions;

use App\Domain\Readings\Models\ReadingBook;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ReadingBooks
{
    public function owned(int $userId, string $id): ReadingBook
    {
        return ReadingBook::query()->where('user_id', $userId)->findOrFail(strtolower($id));
    }

    public function create(int $userId, string $id, string $title): ReadingBook
    {
        return DB::transaction(function () use ($userId, $id, $title): ReadingBook {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            ReadingBook::query()->insertOrIgnore([
                'id' => $id, 'user_id' => $userId, 'title' => $title, 'revision' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $book = $this->owned($userId, $id);
            abort_unless($book->title === $title, 409, 'This book ID already has a different title.');

            return $book;
        });
    }

    public function rename(int $userId, string $id, int $revision, string $title): ReadingBook
    {
        return DB::transaction(function () use ($userId, $id, $revision, $title): ReadingBook {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $book = $this->owned($userId, $id);
            if ($book->title !== $title) {
                abort_unless($book->revision === $revision, 409, 'Refresh the book before editing.');
                $book->title = $title;
                $book->revision++;
                $book->save();
            }

            return $book;
        });
    }
}

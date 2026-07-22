<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\AdminSentenceScriptTest;
use App\Domain\Admin\Support\AdminSentenceScriptCursor;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

final class ListAdminSentenceScriptTestsAction
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 100;

    /** @return array{tests: Collection<int, AdminSentenceScriptTest>, nextCursor: ?string} */
    public function handle(int $limit, ?string $cursor): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException('Sentence test limit must be between 1 and 100.');
        }

        $query = AdminSentenceScriptTest::query();

        if ($cursor !== null) {
            $boundary = AdminSentenceScriptCursor::decode($cursor);
            $query->where(function ($query) use ($boundary): void {
                $query->where('created_at', '<', $boundary['createdAt'])
                    ->orWhere(function ($query) use ($boundary): void {
                        $query->where('created_at', $boundary['createdAt'])
                            ->where('id', '<', $boundary['id']);
                    });
            });
        }

        $tests = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get([
                'id',
                'sentence',
                'translation',
                'estimated_duration_secs',
                'parse_error',
                'created_at',
            ]);
        $hasMore = $tests->count() > $limit;
        if ($hasMore) {
            $tests->pop();
        }

        return [
            'tests' => $tests,
            'nextCursor' => $hasMore ? AdminSentenceScriptCursor::encode($tests->last()) : null,
        ];
    }
}

<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\AdminSentenceScriptTest;
use App\Domain\Admin\Support\AdminSentenceScriptTestId;
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
        $cursor = $cursor === null ? null : AdminSentenceScriptTestId::normalize($cursor);

        $query = AdminSentenceScriptTest::query();

        if ($cursor !== null) {
            $cursorTest = AdminSentenceScriptTest::query()->find($cursor);
            if ($cursorTest instanceof AdminSentenceScriptTest) {
                $query->where(function ($query) use ($cursorTest): void {
                    $query->where('created_at', '<', $cursorTest->created_at)
                        ->orWhere(function ($query) use ($cursorTest): void {
                            $query->where('created_at', $cursorTest->created_at)
                                ->where('id', '<', $cursorTest->id);
                        });
                });
            } else {
                return ['tests' => new Collection, 'nextCursor' => null];
            }
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
            'nextCursor' => $hasMore ? $tests->last()?->id : null,
        ];
    }
}

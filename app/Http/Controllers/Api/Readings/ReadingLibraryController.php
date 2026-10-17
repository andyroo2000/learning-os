<?php

namespace App\Http\Controllers\Api\Readings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Readings\ReadingBookRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class ReadingLibraryController extends Controller
{
    public function __invoke(ReadingBookRequest $request): JsonResponse
    {
        $userId = $request->user()->id;
        $books = DB::table('reading_books')->where('user_id', $userId)
            ->select('id', 'title', 'created_at')->selectRaw("'book' as kind");
        $readings = DB::table('readings')->where('user_id', $userId)
            ->whereNotIn('id', DB::table('reading_book_entries')->select('reading_id'))
            ->select('id', 'title', 'created_at')->selectRaw("'reading' as kind");
        $items = DB::query()->fromSub($books->unionAll($readings), 'library')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20, ['*'], 'page', (int) ($request->validated()['page'] ?? 1));

        return response()->json($items)->header('Cache-Control', 'private, no-store');
    }
}

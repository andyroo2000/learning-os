<?php

namespace App\Http\Controllers\Api\Readings;

use App\Domain\Readings\Actions\ReadingBookContents;
use App\Domain\Readings\Actions\ReadingBooks;
use App\Domain\Readings\Models\ReadingBook;
use App\Http\Controllers\Controller;
use App\Http\Requests\Readings\ReadingBookRequest;
use Illuminate\Http\JsonResponse;

final class ReadingBookController extends Controller
{
    public function __construct(private readonly ReadingBooks $books, private readonly ReadingBookContents $contents) {}

    public function index(ReadingBookRequest $request): JsonResponse
    {
        $page = (int) ($request->validated()['page'] ?? 1);
        $books = ReadingBook::query()->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20, ['*'], 'page', $page);
        $books->through(fn (ReadingBook $book) => $book->summary());

        return $this->response($books->toArray());
    }

    public function show(ReadingBookRequest $request, string $book): JsonResponse
    {
        return $this->response($this->contents->present($this->books->owned($request->user()->id, $book)));
    }

    public function store(ReadingBookRequest $request): JsonResponse
    {
        $data = $request->validated();
        $book = $this->books->create($request->user()->id, $data['id'], $data['title']);

        return $this->response($this->contents->present($book));
    }

    public function update(ReadingBookRequest $request, string $book): JsonResponse
    {
        $data = $request->validated();
        $item = $this->books->rename($request->user()->id, $book, (int) $data['expectedRevision'], $data['title']);

        return $this->response($this->contents->present($item));
    }

    public function contents(ReadingBookRequest $request, string $book): JsonResponse
    {
        $data = $request->validated();
        $item = $this->contents->replace($request->user()->id, $book, (int) $data['expectedRevision'], $data['readingIds']);

        return $this->response($this->contents->present($item));
    }

    private function response(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }
}

<?php

namespace App\Http\Controllers\Api\Readings;

use App\Domain\Readings\Actions\ReadingLibrary;
use App\Domain\Readings\Actions\ReadingProgress;
use App\Domain\Readings\Models\Reading;
use App\Http\Controllers\Controller;
use App\Http\Requests\Readings\ReadingProgressRequest;
use Illuminate\Http\JsonResponse;

final class ReadingProgressController extends Controller
{
    public function index(ReadingProgressRequest $request, ReadingProgress $progress): JsonResponse
    {
        $reading = Reading::query()->join('reading_progress', 'reading_progress.reading_id', '=', 'readings.id')
            ->where('readings.user_id', $request->user()->id)
            ->orderByDesc('reading_progress.read_at')->orderByDesc('readings.id')
            ->select(['readings.id', 'readings.title', 'readings.author', 'readings.page_numbers', 'readings.revision'])->first();
        $item = $reading ? [...$reading->summary(), 'position' => $progress->get($reading->id)] : null;

        return response()->json(['continue' => $item])->header('Cache-Control', 'private, no-store');
    }

    public function show(ReadingProgressRequest $request, string $reading, ReadingLibrary $library, ReadingProgress $progress): JsonResponse
    {
        $item = $library->owned((int) $request->user()->id, $reading);

        return response()->json(['position' => $progress->get($item->id)])->header('Cache-Control', 'private, no-store');
    }

    public function update(ReadingProgressRequest $request, string $reading, ReadingProgress $progress): JsonResponse
    {
        $position = $progress->save((int) $request->user()->id, $reading, $request->validated());

        return response()->json(['position' => $position])->header('Cache-Control', 'private, no-store');
    }
}

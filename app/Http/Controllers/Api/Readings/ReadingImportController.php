<?php

namespace App\Http\Controllers\Api\Readings;

use App\Domain\Readings\Actions\ImportReadingUpload;
use App\Http\Controllers\Controller;
use App\Http\Requests\Readings\ReadingImportRequest;
use Illuminate\Http\JsonResponse;

final class ReadingImportController extends Controller
{
    public function __invoke(ReadingImportRequest $request, ImportReadingUpload $import): JsonResponse
    {
        $reading = $import->handle($request->user()->id, $request->validated());

        return response()->json($reading->summary())->header('Cache-Control', 'private, no-store');
    }
}

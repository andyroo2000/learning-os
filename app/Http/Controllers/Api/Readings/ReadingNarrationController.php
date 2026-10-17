<?php

namespace App\Http\Controllers\Api\Readings;

use App\Domain\Readings\Actions\ReadingLibrary;
use App\Domain\Readings\Support\ReadingNarrator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Readings\ReadingNarrationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class ReadingNarrationController extends Controller
{
    public function __invoke(ReadingNarrationRequest $request, string $reading, ReadingLibrary $library): JsonResponse
    {
        $data = $request->validated();
        $item = DB::transaction(function () use ($request, $reading, $library, $data) {
            $owner = (int) $request->user()->id;
            User::query()->whereKey($owner)->lockForUpdate()->firstOrFail();
            $item = $library->owned($owner, $reading);
            if (ReadingNarrator::voice($item) !== $data['voiceId']) {
                abort_unless($item->revision === (int) $data['expectedRevision'], 409, 'Refresh the reading before changing its narrator.');
                $item->narrator_voice_id = $data['voiceId'];
                $item->revision++;
                $item->save();
            }

            return $item;
        });

        return response()->json([...$item->summary(), 'voiceId' => ReadingNarrator::voice($item),
            'voiceName' => ReadingNarrator::name(ReadingNarrator::voice($item))])->header('Cache-Control', 'private, no-store');
    }
}

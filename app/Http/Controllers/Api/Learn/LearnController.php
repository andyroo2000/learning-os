<?php

namespace App\Http\Controllers\Api\Learn;

use App\Domain\Learn\Actions\RespondToLearnSession;
use App\Domain\Learn\Models\LearnSession;
use App\Domain\Learn\Support\LearnCatalog;
use App\Domain\Learn\Support\LearnProgress;
use App\Http\Controllers\Controller;
use App\Http\Requests\Learn\LearnTurnRequest;
use App\Http\Resources\Learn\LearnSessionResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LearnController extends Controller
{
    public function index(Request $request, LearnCatalog $catalog): array
    {
        return ['lessons' => array_map($catalog->publicLesson(...), $catalog->all()),
            'sessions' => LearnSessionResource::collection(LearnSession::where('user_id', $request->user()->id)->get())->resolve($request)];
    }

    public function start(Request $request, string $lessonId, LearnCatalog $catalog): LearnSessionResource
    {
        $catalog->find($lessonId);
        // A unique user/lesson key makes re-opening a lesson resume the same notebook.
        DB::table('learn_sessions')->insertOrIgnore([
            'id' => strtolower((string) Str::ulid()), 'user_id' => $request->user()->id,
            'lesson_id' => $lessonId, 'state' => json_encode(LearnProgress::initial(), JSON_THROW_ON_ERROR),
            'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return new LearnSessionResource(LearnSession::where('user_id', $request->user()->id)->where('lesson_id', $lessonId)->firstOrFail());
    }

    public function show(Request $request, LearnSession $session): LearnSessionResource
    {
        abort_unless($session->user_id === $request->user()->id, 404);

        return new LearnSessionResource($session);
    }

    public function respond(LearnTurnRequest $request, LearnSession $session, RespondToLearnSession $respond): LearnSessionResource
    {
        abort_unless($session->user_id === $request->user()->id, 404);
        $tutor = DB::table('journal_tutor_preferences')->where('user_id', $request->user()->id)->value('tutor_id') ?? 'hana';
        $session = $respond->handle($session, $request->turn(), $tutor);

        return new LearnSessionResource($session);
    }
}

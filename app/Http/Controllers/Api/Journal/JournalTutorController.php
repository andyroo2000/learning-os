<?php

namespace App\Http\Controllers\Api\Journal;

use App\Domain\Journal\Actions\SaveJournalTutor;
use App\Domain\Journal\Enums\JournalTutor;
use App\Domain\Journal\Support\JournalAvatars;
use App\Http\Controllers\Controller;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JournalTutorController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $selected = DB::table('journal_tutor_preferences')->where('user_id', AuthenticatedUser::id($request))->value('tutor_id');

        return response()->json(['data' => ['selectedId' => $selected,
            'tutors' => array_map(fn (JournalTutor $tutor) => JournalAvatars::tutor($tutor->presentation()), JournalTutor::cases())]])
            ->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, SaveJournalTutor $save): JsonResponse
    {
        $save->handle(AuthenticatedUser::id($request), $request->all());

        return $this->show($request);
    }
}

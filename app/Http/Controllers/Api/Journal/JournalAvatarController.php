<?php

namespace App\Http\Controllers\Api\Journal;

use App\Domain\Journal\Support\JournalAvatars;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class JournalAvatarController extends Controller
{
    public function __invoke(string $avatar): BinaryFileResponse
    {
        abort_unless(in_array($avatar, JournalAvatars::NAMES, true), 404);

        return response()->file(resource_path('images/journal/'.$avatar.'.png'), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

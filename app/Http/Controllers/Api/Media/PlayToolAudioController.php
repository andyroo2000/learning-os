<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Media\Contracts\StaticMediaObjectStore;
use App\Domain\Media\Support\StaticMediaPath;
use App\Domain\Media\Support\StaticMediaSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

final class PlayToolAudioController extends Controller
{
    public function __invoke(string $audioPath, StaticMediaObjectStore $store, StaticMediaSettings $settings): RedirectResponse
    {
        $path = '/tools-audio/'.$audioPath;
        abort_unless(StaticMediaPath::isToolAudio($path), 404);
        $objectPath = $settings->toolAudioObjectPath($path);
        abort_unless($store->exists($objectPath), 404);

        return redirect()->away($store->signedReadUrl($objectPath, now()->addMinutes(5), 'audio/mpeg'))
            ->header('Cache-Control', 'private, no-store');
    }
}

<?php

namespace App\Http\Controllers\Api\Readings;

use App\Domain\Readings\Actions\GenerateReadingAudio;
use App\Domain\Readings\Actions\ReadingLibrary;
use App\Domain\Readings\Models\Reading;
use App\Http\Controllers\Controller;
use App\Http\Requests\Readings\ReadingRequest;
use App\Support\Audio\AudioSpeechGenerationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReadingController extends Controller
{
    public function __construct(private readonly ReadingLibrary $library) {}

    public function index(ReadingRequest $request): JsonResponse
    {
        $items = $this->library->forUser((int) $request->user()->id)->map->summary();

        return response()->json($items)->header('Cache-Control', 'private, no-store');
    }

    public function show(ReadingRequest $request, string $reading): JsonResponse
    {
        $item = $this->library->owned((int) $request->user()->id, $reading);

        return response()->json([
            ...$item->summary(),
            'document' => $this->presentDocument($item),
            'illustrationUrl' => $item->illustration_path ? '/api/convolab/readings/'.$item->id.'/illustration' : null,
            'voiceName' => 'Sato',
        ])->header('Cache-Control', 'private, no-store');
    }

    public function illustration(ReadingRequest $request, string $reading): StreamedResponse
    {
        $item = $this->library->owned((int) $request->user()->id, $reading);
        abort_unless($item->illustration_path, 404);

        return Storage::disk('local')->response($item->illustration_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function pageIllustration(ReadingRequest $request, string $reading, int $page): StreamedResponse
    {
        $item = $this->library->owned((int) $request->user()->id, $reading);
        $documentPage = collect($item->document['pages'])->firstWhere('number', $page);
        $path = $documentPage['imagePath'] ?? null;
        abort_unless(is_string($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function presentDocument(Reading $reading): array
    {
        $document = $reading->document;
        foreach ($document['pages'] as &$page) {
            if (isset($page['imagePath'])) {
                $page['imageUrl'] = '/api/convolab/readings/'.$reading->id.'/pages/'.$page['number'].'/illustration';
                unset($page['imagePath']);
            }
        }

        return $document;
    }

    public function audio(ReadingRequest $request, string $reading, int $sentence, GenerateReadingAudio $generate): StreamedResponse|JsonResponse
    {
        $item = $this->library->owned((int) $request->user()->id, $reading);
        try {
            $path = $generate->handle($item, $sentence);
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'This sentence is being prepared. Try again shortly.'], 409);
        } catch (AudioSpeechGenerationException) {
            return response()->json(['message' => 'Sato audio is temporarily unavailable. Please try again.'], 503);
        }

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

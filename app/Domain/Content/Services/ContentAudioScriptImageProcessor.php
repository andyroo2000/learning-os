<?php

namespace App\Domain\Content\Services;

use App\Domain\Content\Data\ClaimedContentAudioScriptGeneration;
use App\Domain\Content\Data\GeneratedContentAudioScriptImage;
use App\Domain\Content\Models\ContentAudioScriptSegment;
use App\Domain\Content\Support\ContentAudioScriptGeneratedImagePath;
use App\Support\Images\ImageGenerator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class ContentAudioScriptImageProcessor
{
    public const MAX_IMAGE_BYTES = 20 * 1024 * 1024;

    public const FAILURE_MESSAGE = 'Script image generation failed.';

    public const BATCH_SIZE = 40;

    public function __construct(
        private ImageGenerator $imageGenerator,
        private ContentAudioScriptMediaCleaner $mediaCleaner,
        private ContentAudioScriptImageState $state,
    ) {}

    public function process(ClaimedContentAudioScriptGeneration $claim): void
    {
        $targets = ContentAudioScriptSegment::query()
            ->where('script_id', $claim->attempt->scriptId)
            ->where('image_status', 'generating')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get();

        foreach ($targets as $segment) {
            if (! $this->processSegment($claim, $segment)) {
                return;
            }
            $this->state->updateProgress($claim);
        }

        if ($this->hasRemainingTargets($claim)) {
            $this->state->requeue($claim);

            return;
        }
        $this->state->complete($claim);
    }

    private function processSegment(
        ClaimedContentAudioScriptGeneration $claim,
        ContentAudioScriptSegment $segment,
    ): bool {
        $mediaId = (string) Str::uuid();
        $path = ContentAudioScriptGeneratedImagePath::storagePath(
            $claim->attempt->episodeId,
            $segment->id,
            $claim->attempt->number,
            $mediaId,
        );
        $bytes = $this->generate($claim, $segment);
        if ($bytes === null) {
            return true;
        }
        $image = new GeneratedContentAudioScriptImage($claim, $segment, $mediaId, $path);

        try {
            if (! Storage::disk('media')->put($path, $bytes)) {
                throw new RuntimeException('Script image could not be persisted.');
            }
            $accepted = $this->state->persist($image);
        } catch (Throwable $exception) {
            $this->mediaCleaner->deleteFiles([$path]);

            throw $exception;
        }
        if (! $accepted) {
            $this->mediaCleaner->deleteFiles([$path]);
        }

        return $accepted;
    }

    private function generate(
        ClaimedContentAudioScriptGeneration $claim,
        ContentAudioScriptSegment $segment,
    ): ?string {
        try {
            $bytes = $this->imageGenerator->generate($segment->image_prompt ?: $segment->text);
            if (! $this->isWebp($bytes) || strlen($bytes) > self::MAX_IMAGE_BYTES) {
                throw new RuntimeException(self::FAILURE_MESSAGE);
            }

            return $bytes;
        } catch (Throwable $exception) {
            report($exception);
            $this->state->recordFailure($claim, $segment);

            return null;
        }
    }

    private function isWebp(string $bytes): bool
    {
        return strlen($bytes) >= 12
            && substr($bytes, 0, 4) === 'RIFF'
            && substr($bytes, 8, 4) === 'WEBP';
    }

    private function hasRemainingTargets(ClaimedContentAudioScriptGeneration $claim): bool
    {
        return ContentAudioScriptSegment::query()
            ->where('script_id', $claim->attempt->scriptId)
            ->where('image_status', 'generating')
            ->exists();
    }
}

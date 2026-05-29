<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Exceptions\MediaAssetConflictException;
use App\Domain\Media\Exceptions\MediaAssetValidationException;
use App\Domain\Media\Models\MediaAsset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\StoreMediaAssetRequest;
use App\Http\Resources\Media\MediaAssetResource;
use App\Support\Database\IntegrityConstraintViolation;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class StoreMediaAssetController extends Controller
{
    public function __invoke(StoreMediaAssetRequest $request, CreateMediaAssetAction $createMediaAsset): JsonResponse
    {
        $data = $request->validated();

        try {
            $mediaAsset = $createMediaAsset->handle(CreateMediaAssetData::fromInput(
                userId: $request->user()->id,
                disk: $data['disk'],
                path: $data['path'],
                mimeType: $data['mime_type'],
                sizeBytes: $data['size_bytes'],
                publicUrl: $data['public_url'] ?? null,
                checksumSha256: $data['checksum_sha256'] ?? null,
                originalFilename: $data['original_filename'] ?? null,
                id: $data['id'] ?? null,
            ));
        } catch (MediaAssetConflictException $exception) {
            if (! $this->conflictsWithCurrentUsersAsset($data, $request->user()->id)) {
                abort(404);
            }

            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        } catch (QueryException $exception) {
            if (! IntegrityConstraintViolation::matches($exception)) {
                throw $exception;
            }

            return response()->json([
                'message' => 'Media asset already exists.',
            ], 409);
        } catch (MediaAssetValidationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => $exception->getMessage(),
            ]);
        }

        return MediaAssetResource::make($mediaAsset)
            ->response()
            ->setStatusCode($mediaAsset->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function conflictsWithCurrentUsersAsset(array $data, int $userId): bool
    {
        $id = $data['id'] ?? null;

        return is_string($id)
            && MediaAsset::query()
                ->whereKey(strtolower($id))
                ->where('user_id', $userId)
                ->exists();
    }
}

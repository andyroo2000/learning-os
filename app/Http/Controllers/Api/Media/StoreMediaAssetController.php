<?php

namespace App\Http\Controllers\Api\Media;

use App\Domain\Media\Actions\CreateMediaAssetAction;
use App\Domain\Media\Data\CreateMediaAssetData;
use App\Domain\Media\Exceptions\MediaAssetConflictException;
use App\Domain\Media\Exceptions\MediaAssetValidationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\StoreMediaAssetRequest;
use App\Http\Resources\Media\MediaAssetResource;
use App\Http\Support\AuthenticatedUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class StoreMediaAssetController extends Controller
{
    public function __invoke(StoreMediaAssetRequest $request, CreateMediaAssetAction $createMediaAsset): JsonResponse
    {
        $data = $request->validated();
        $userId = AuthenticatedUser::id($request);

        try {
            $result = $createMediaAsset->handle(CreateMediaAssetData::fromInput(
                userId: $userId,
                disk: $data['disk'],
                path: $data['path'],
                mimeType: $data['mime_type'],
                sizeBytes: $request->sizeBytes(),
                publicUrl: $data['public_url'] ?? null,
                checksumSha256: $data['checksum_sha256'] ?? null,
                originalFilename: $data['original_filename'] ?? null,
                id: $data['id'] ?? null,
            ));
        } catch (MediaAssetConflictException $exception) {
            if ($exception->shouldBeHiddenFrom($userId)) {
                return response()->json(['message' => 'Not Found'], 404);
            }

            // Conflict is visible to the requesting user; safe to include a reason.
            return response()->json([
                'message' => $exception->getMessage(),
                'reason' => $exception->reason(),
            ], 409);
        } catch (MediaAssetValidationException $exception) {
            throw ValidationException::withMessages([
                $exception->field() => $exception->getMessage(),
            ]);
        }

        return MediaAssetResource::make($result->mediaAsset)
            ->response()
            ->setStatusCode($result->wasCreated ? 201 : 200);
    }
}

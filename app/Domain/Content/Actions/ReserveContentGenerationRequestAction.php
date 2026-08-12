<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Exceptions\ContentGenerationRequestConflictException;
use App\Domain\Content\Models\ContentGenerationRequest;
use App\Domain\Content\Results\ReservedContentGenerationRequest;
use App\Domain\Content\Support\ContentSourceLock;
use App\Domain\Content\Support\ConvoLabUserId;
use App\Support\Database\IntegrityConstraintViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReserveContentGenerationRequestAction
{
    /** @param array<string, mixed> $inputPayload */
    public function handle(
        int $userId,
        string $convoLabUserId,
        ?string $clientRequestId,
        string $operation,
        string $resourceType,
        string $resourceId,
        string $inputFingerprint,
        array $inputPayload,
    ): ReservedContentGenerationRequest {
        $convoLabUserId = ConvoLabUserId::normalize($convoLabUserId);
        $clientRequestId = $clientRequestId === null
            ? (string) Str::uuid()
            : Str::lower(trim($clientRequestId));

        try {
            return DB::transaction(function () use (
                $clientRequestId,
                $convoLabUserId,
                $inputFingerprint,
                $inputPayload,
                $operation,
                $resourceId,
                $resourceType,
                $userId,
            ): ReservedContentGenerationRequest {
                // Every participating content path takes the global source lock first, then the
                // request row, then its resource/job rows. Keeping that order prevents deadlocks.
                ContentSourceLock::acquireConvoLab(DB::connection());
                $existing = ContentGenerationRequest::query()
                    ->where('convolab_user_id', $convoLabUserId)
                    ->where('client_request_id', $clientRequestId)
                    ->lockForUpdate()
                    ->first();
                if ($existing instanceof ContentGenerationRequest) {
                    return new ReservedContentGenerationRequest(
                        $this->matchingExisting(
                            $existing,
                            $userId,
                            $operation,
                            $resourceType,
                            $resourceId,
                            $inputFingerprint,
                        ),
                        false,
                    );
                }

                $request = new ContentGenerationRequest;
                $request->id = (string) Str::uuid();
                $request->user_id = $userId;
                $request->convolab_user_id = $convoLabUserId;
                $request->client_request_id = $clientRequestId;
                $request->operation = $operation;
                $request->resource_type = $resourceType;
                $request->resource_id = $resourceId;
                $request->input_fingerprint = $inputFingerprint;
                $request->input_payload = $inputPayload;
                $request->state = 'pending';
                $request->save();

                return new ReservedContentGenerationRequest($request, true);
            });
        } catch (QueryException $exception) {
            // Recover outside the failed PostgreSQL transaction. Cooperative generation callers
            // serialize on ContentSourceLock; this also protects against imports/older writers.
            if (! IntegrityConstraintViolation::matchesUniqueKey($exception)) {
                throw $exception;
            }

            $existing = ContentGenerationRequest::query()
                ->where('convolab_user_id', $convoLabUserId)
                ->where('client_request_id', $clientRequestId)
                ->first();
            if (! $existing instanceof ContentGenerationRequest) {
                throw $exception;
            }

            return new ReservedContentGenerationRequest(
                $this->matchingExisting(
                    $existing,
                    $userId,
                    $operation,
                    $resourceType,
                    $resourceId,
                    $inputFingerprint,
                ),
                false,
            );
        }
    }

    private function matchingExisting(
        ContentGenerationRequest $request,
        int $userId,
        string $operation,
        string $resourceType,
        string $resourceId,
        string $inputFingerprint,
    ): ContentGenerationRequest {
        if ((int) $request->user_id !== $userId
            || ! hash_equals((string) $request->operation, $operation)
            || ! hash_equals((string) $request->resource_type, $resourceType)
            || ! hash_equals((string) $request->resource_id, $resourceId)
            || ! hash_equals((string) $request->input_fingerprint, $inputFingerprint)) {
            throw new ContentGenerationRequestConflictException;
        }

        return $request;
    }
}

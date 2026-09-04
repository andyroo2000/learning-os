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
        $requestData = [
            'user_id' => $userId,
            'convolab_user_id' => $convoLabUserId,
            'client_request_id' => $clientRequestId,
            'operation' => $operation,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'input_fingerprint' => $inputFingerprint,
        ];

        try {
            return DB::transaction(fn (): ReservedContentGenerationRequest => $this->reserve(
                $requestData,
                $inputPayload,
            ));
        } catch (QueryException $exception) {
            // Recover outside the failed PostgreSQL transaction. Cooperative generation callers
            // serialize on ContentSourceLock; this also protects against imports/older writers.
            if (! IntegrityConstraintViolation::matchesUniqueKey($exception)) {
                throw $exception;
            }

            $existing = ContentGenerationRequest::query()
                ->where('convolab_user_id', $requestData['convolab_user_id'])
                ->where('client_request_id', $requestData['client_request_id'])
                ->first();
            if (! $existing instanceof ContentGenerationRequest) {
                throw $exception;
            }

            return new ReservedContentGenerationRequest(
                $this->matchingExisting($existing, $requestData),
                false,
            );
        }
    }

    /**
     * @param  array{user_id: int, convolab_user_id: string, client_request_id: string, operation: string, resource_type: string, resource_id: string, input_fingerprint: string}  $requestData
     * @param  array<string, mixed>  $inputPayload
     */
    private function reserve(array $requestData, array $inputPayload): ReservedContentGenerationRequest
    {
        // Every participating content path takes the global source lock first, then the
        // request row, then its resource/job rows. Keeping that order prevents deadlocks.
        ContentSourceLock::acquireConvoLab(DB::connection());
        $existing = ContentGenerationRequest::query()
            ->where('convolab_user_id', $requestData['convolab_user_id'])
            ->where('client_request_id', $requestData['client_request_id'])
            ->lockForUpdate()
            ->first();
        if ($existing instanceof ContentGenerationRequest) {
            return new ReservedContentGenerationRequest(
                $this->matchingExisting($existing, $requestData),
                false,
            );
        }

        $request = new ContentGenerationRequest;
        $request->id = (string) Str::uuid();
        $request->user_id = $requestData['user_id'];
        $request->convolab_user_id = $requestData['convolab_user_id'];
        $request->client_request_id = $requestData['client_request_id'];
        $request->operation = $requestData['operation'];
        $request->resource_type = $requestData['resource_type'];
        $request->resource_id = $requestData['resource_id'];
        $request->input_fingerprint = $requestData['input_fingerprint'];
        $request->input_payload = $inputPayload;
        $request->state = 'pending';
        $request->save();

        return new ReservedContentGenerationRequest($request, true);
    }

    /**
     * @param  array{user_id: int, convolab_user_id: string, client_request_id: string, operation: string, resource_type: string, resource_id: string, input_fingerprint: string}  $requestData
     */
    private function matchingExisting(
        ContentGenerationRequest $request,
        array $requestData,
    ): ContentGenerationRequest {
        $this->assertMatchingUser($request, $requestData['user_id']);
        $this->assertMatchingValue((string) $request->operation, $requestData['operation']);
        $this->assertMatchingValue((string) $request->resource_type, $requestData['resource_type']);
        $this->assertMatchingValue((string) $request->resource_id, $requestData['resource_id']);
        $this->assertMatchingValue((string) $request->input_fingerprint, $requestData['input_fingerprint']);

        return $request;
    }

    private function assertMatchingUser(ContentGenerationRequest $request, int $userId): void
    {
        if ((int) $request->user_id !== $userId) {
            throw new ContentGenerationRequestConflictException;
        }
    }

    private function assertMatchingValue(string $actual, string $expected): void
    {
        if (! hash_equals($actual, $expected)) {
            throw new ContentGenerationRequestConflictException;
        }
    }
}

<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Models\ContentGenerationRequest;

final class ContentGenerationRequestTerminalState
{
    public static function complete(ContentGenerationRequest $request): void
    {
        self::write(
            $request,
            ContentGenerationRequestState::COMPLETED,
            200,
            null,
            null,
        );
    }

    public static function fail(
        ContentGenerationRequest $request,
        int $status,
        string $code,
        string $message,
    ): void {
        self::write(
            $request,
            ContentGenerationRequestState::FAILED,
            $status,
            $code,
            trim($message),
        );
    }

    private static function write(
        ContentGenerationRequest $request,
        string $state,
        int $status,
        ?string $code,
        ?string $message,
    ): void {
        $request->state = $state;
        $request->input_payload = [];
        $request->response_status = $status;
        $request->error_code = $code;
        $request->error_message = $message;
        $request->finished_at ??= now();
        $request->save();
    }
}

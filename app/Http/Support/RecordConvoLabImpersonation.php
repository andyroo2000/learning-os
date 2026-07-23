<?php

namespace App\Http\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class RecordConvoLabImpersonation
{
    public function __invoke(
        Request $request,
        ?string $adminConvoLabUserId,
        string $targetConvoLabUserId,
    ): void {
        if ($adminConvoLabUserId === null) {
            return;
        }

        try {
            DB::table('admin_audit_logs')->insert([
                'id' => (string) Str::uuid(),
                'adminUserId' => $adminConvoLabUserId,
                'action' => 'impersonate_start',
                'targetUserId' => $targetConvoLabUserId,
                'metadata' => $this->metadata($request),
                'ipAddress' => $request->ip(),
                'userAgent' => $request->userAgent(),
                'createdAt' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @throws JsonException
     */
    private function metadata(Request $request): string
    {
        return json_encode([
            'path' => $request->path(),
            'method' => $request->method(),
            'query' => $request->query(),
        ], JSON_THROW_ON_ERROR);
    }
}

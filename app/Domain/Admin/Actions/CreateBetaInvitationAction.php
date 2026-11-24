<?php

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Models\ConvoLabBetaRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class CreateBetaInvitationAction
{
    public function __construct(private readonly ApproveBetaRequestAction $approve) {}

    public function handle(string $email, string $actor): ConvoLabBetaRequest
    {
        // Direct callers share the HTTP validation boundary before any record is saved.
        $data = Validator::make(['email' => Str::lower(trim($email)), 'actor' => $actor], [
            'email' => ['required', 'string', 'email:rfc,strict', 'max:254'],
            'actor' => ['required', 'string', 'uuid'],
        ])->validate();
        // The unique email index and existing approval lock keep retries on one code.
        $record = ConvoLabBetaRequest::query()->firstOrCreate(
            ['email' => $data['email']],
            ['devices' => [], 'message' => ''],
        );

        return $this->approve->handle($record->id, $data['actor']);
    }
}

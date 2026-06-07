<?php

namespace App\Http\Requests\Media;

use App\Http\Support\AuthenticatedUser;
use App\Support\Identifiers\CanonicalUlid;
use Illuminate\Foundation\Http\FormRequest;

class DeleteMediaAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Ownership is enforced by the delete action's scoped query so repeat deletes,
        // missing assets, and cross-user IDs all return the same idempotent 204 outcome.
        // Wire a MediaAssetPolicy here later if delete semantics stop being outcome-based.
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    public function userId(): int
    {
        return AuthenticatedUser::id($this);
    }

    public function mediaAssetId(): string
    {
        return CanonicalUlid::normalize((string) $this->route('mediaAssetId'));
    }
}

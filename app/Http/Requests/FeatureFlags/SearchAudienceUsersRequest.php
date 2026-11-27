<?php

namespace App\Http\Requests\FeatureFlags;

use App\Http\Requests\Admin\ConvoLabAdminReadRequest;
use App\Http\Requests\Concerns\NormalizesStringInputs;
use Illuminate\Validation\Rule;

final class SearchAudienceUsersRequest extends ConvoLabAdminReadRequest
{
    use NormalizesStringInputs;

    protected function prepareForValidation(): void
    {
        $this->mergeNormalizedStringInputs(['search', 'role', 'groupId'], lowercase: ['groupId'], blankToNull: ['search', 'role', 'groupId']);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'role' => ['sometimes', 'nullable', Rule::in(['user', 'admin'])],
            'groupId' => ['sometimes', 'nullable', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}

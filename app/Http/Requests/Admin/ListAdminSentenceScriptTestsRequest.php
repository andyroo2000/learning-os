<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Actions\ListAdminSentenceScriptTestsAction;

final class ListAdminSentenceScriptTestsRequest extends ConvoLabAdminReadRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['limit', 'cursor'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $normalized[$field] = strtolower(trim($value));
            }
        }
        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'filled', 'integer', 'min:1', 'max:'.ListAdminSentenceScriptTestsAction::MAX_LIMIT],
            'cursor' => ['sometimes', 'filled', 'string', 'uuid'],
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated()['limit'] ?? ListAdminSentenceScriptTestsAction::DEFAULT_LIMIT);
    }

    public function cursor(): ?string
    {
        return $this->validated('cursor');
    }
}

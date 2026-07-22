<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Actions\ListAdminSentenceScriptTestsAction;
use App\Domain\Admin\Support\AdminSentenceScriptCursor;
use Closure;
use InvalidArgumentException;

final class ListAdminSentenceScriptTestsRequest extends ConvoLabAdminActorReadRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $normalized = [];
        foreach (['limit', 'cursor'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $normalized[$field] = trim($value);
            }
        }
        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'limit' => ['sometimes', 'filled', 'integer', 'min:1', 'max:'.ListAdminSentenceScriptTestsAction::MAX_LIMIT],
            'cursor' => ['sometimes', 'filled', 'string', 'max:160', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value)) {
                    return;
                }
                try {
                    AdminSentenceScriptCursor::decode($value);
                } catch (InvalidArgumentException) {
                    $fail('The cursor is invalid.');
                }
            }],
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

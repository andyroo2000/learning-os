<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Data\UpdateAdminCoursePipelineData;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

final class UpdateAdminCoursePipelineRequest extends ConvoLabAdminWriteRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (is_string($this->input('stage'))) {
            $this->merge(['stage' => trim($this->input('stage'))]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'stage' => ['required', 'string', Rule::in(['exchanges', 'script'])],
            'data' => ['present', 'array'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('stage') || $validator->errors()->has('data')) {
                return;
            }

            $validated = $validator->safe()->all();

            try {
                UpdateAdminCoursePipelineData::fromInput(
                    $validated['stage'],
                    $validated['data'],
                );
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('data', $exception->getMessage());
            }
        }];
    }

    public function pipelineData(): UpdateAdminCoursePipelineData
    {
        $validated = $this->validated();

        return UpdateAdminCoursePipelineData::fromInput(
            $validated['stage'],
            $validated['data'],
        );
    }
}

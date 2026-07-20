<?php

namespace App\Http\Requests\Media;

use App\Domain\Media\Support\StaticMediaPath;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ResolveToolAudioUrlsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'paths' => [
                'required',
                'array',
                'min:1',
                'max:'.StaticMediaPath::MAX_TOOL_AUDIO_PATHS,
            ],
            'paths.*' => [
                'required',
                'string',
                'max:'.StaticMediaPath::MAX_TOOL_AUDIO_PATH_LENGTH,
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && StaticMediaPath::isToolAudio($value)) {
                        return;
                    }

                    $fail("The {$attribute} field must be a valid tool-audio path.");
                },
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        $validated = $this->validated();

        return array_values(array_unique($validated['paths']));
    }

    protected function prepareForValidation(): void
    {
        $paths = $this->input('paths');
        if (! is_array($paths)) {
            return;
        }

        $this->merge([
            'paths' => array_map(
                fn (mixed $path): mixed => is_string($path)
                    ? StaticMediaPath::normalizeToolAudio($path)
                    : $path,
                $paths,
            ),
        ]);
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'error' => 'paths must be an array of 1-'.StaticMediaPath::MAX_TOOL_AUDIO_PATHS
                .' valid /tools-audio/*.mp3 values',
        ], 400));
    }
}

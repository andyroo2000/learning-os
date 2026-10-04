<?php

namespace App\Domain\Readings\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ReadingDocument
{
    public static function validate(array $input): array
    {
        $document = Validator::make($input, [
            'title' => ['required', 'string', 'max:200'],
            'author' => ['required', 'string', 'max:200'],
            'authorReading' => ['nullable', 'string', 'max:300'],
            'illustrator' => ['nullable', 'string', 'max:300'],
            'tagline' => ['nullable', 'string', 'max:500'],
            'category' => ['nullable', 'string', 'max:100'],
            ...self::pageRules(),
            'sentences' => ['required', 'array', 'min:1', 'max:5000'],
            'sentences.*.text' => ['required', 'string', 'max:15000'],
            'sentences.*.speechText' => ['sometimes', 'required', 'string', 'max:15000'],
            'sentences.*.translation' => ['required', 'string', 'max:15000'],
        ])->validate();
        self::validateReferences($document);

        return $document;
    }

    private static function pageRules(): array
    {
        return [
            'pages' => ['required', 'array', 'min:1', 'max:100'],
            'pages.*.number' => ['required', 'integer', 'min:1'],
            'pages.*.kind' => ['required', 'in:title,text,illustration'],
            'pages.*.columns' => ['present', 'array', 'max:50'],
            'pages.*.columns.*' => ['required', 'array', 'min:1', 'max:100'],
            'pages.*.columns.*.*' => ['required', 'array', 'size:2'],
            'pages.*.columns.*.*.0' => ['required', 'integer', 'min:0'],
            'pages.*.columns.*.*.1' => ['required', 'string', 'max:2000'],
            'pages.*.imageFile' => ['required_if:pages.*.kind,illustration', 'string', 'max:2000'],
            'pages.*.imageAspectRatio' => ['sometimes', 'numeric', 'min:0.1', 'max:10'],
            'pages.*.imageCrop' => ['sometimes', 'array:left,top,width,height'],
            'pages.*.imageCrop.left' => ['required_with:pages.*.imageCrop', 'numeric', 'min:0', 'max:99'],
            'pages.*.imageCrop.top' => ['required_with:pages.*.imageCrop', 'numeric', 'min:0', 'max:99'],
            'pages.*.imageCrop.width' => ['required_with:pages.*.imageCrop', 'numeric', 'min:1', 'max:100'],
            'pages.*.imageCrop.height' => ['required_with:pages.*.imageCrop', 'numeric', 'min:1', 'max:100'],
            'pages.*.notes' => ['sometimes', 'array', 'max:20'],
            'pages.*.notes.*' => ['array', 'size:2'],
            'pages.*.notes.*.0' => ['required', 'integer', 'min:0'],
            'pages.*.notes.*.1' => ['required', 'string', 'max:2000'],
            'pages.*.notesLeft' => ['sometimes', 'numeric', 'min:0', 'max:90'],
            'pages.*.biography' => ['sometimes', 'array', 'size:2'],
            'pages.*.biography.0' => ['required_with:pages.*.biography', 'integer', 'min:0'],
            'pages.*.biography.1' => ['required_with:pages.*.biography', 'string', 'max:3000'],
            'pages.*.source' => ['sometimes', 'string', 'max:1000'],
        ];
    }

    private static function pageParts(array $page): array
    {
        $parts = collect($page['columns'])->flatten(1)->all();
        $parts = [...$parts, ...($page['notes'] ?? [])];
        if (isset($page['biography'])) {
            $parts[] = $page['biography'];
        }

        return $parts;
    }

    private static function validateReferences(array $document): void
    {
        $parts = collect($document['pages'])->flatMap(fn (array $page) => self::pageParts($page));
        foreach ($parts as $part) {
            if (! array_key_exists($part[0], $document['sentences'])) {
                throw ValidationException::withMessages(['pages' => 'A column refers to an unknown sentence.']);
            }
        }
    }
}

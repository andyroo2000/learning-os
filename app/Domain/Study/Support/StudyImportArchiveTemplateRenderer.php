<?php

namespace App\Domain\Study\Support;

final class StudyImportArchiveTemplateRenderer
{
    /**
     * @param  array{name: string, fields: list<string>, templates: array<int, array{name: string, front: string, back: string}>}  $noteType
     * @return array{front: string, back: string}
     */
    public function render(array $noteType, int $templateOrdinal, string $noteFields): array
    {
        $fieldValues = explode("\x1f", $noteFields);
        $fieldsByName = [];

        foreach ($noteType['fields'] as $index => $fieldName) {
            $fieldsByName[$fieldName] = $fieldValues[$index] ?? '';
        }

        $template = $noteType['templates'][$templateOrdinal] ?? null;

        if ($template === null) {
            return [
                'front' => $this->fallbackFrontText($fieldValues, $templateOrdinal),
                'back' => $this->fallbackBackText($fieldValues, $templateOrdinal),
            ];
        }

        $front = $this->renderTemplateText($template['front'], $fieldsByName);
        $back = $this->renderTemplateText($template['back'], $fieldsByName, $front);

        return [
            'front' => $front !== '' ? $front : $this->fallbackFrontText($fieldValues, $templateOrdinal),
            'back' => $back !== '' ? $back : $this->fallbackBackText($fieldValues, $templateOrdinal),
        ];
    }

    /**
     * @param  array<string, string>  $fieldsByName
     */
    private function renderTemplateText(string $template, array $fieldsByName, string $frontSide = ''): string
    {
        $rendered = str_replace('{{FrontSide}}', $frontSide, $template);
        $rendered = preg_replace_callback(
            '/{{#([^}]+)}}(.*?){{\/\1}}/s',
            fn (array $matches): string => trim($fieldsByName[trim((string) $matches[1])] ?? '') !== '' ? (string) $matches[2] : '',
            $rendered,
        );
        $rendered = preg_replace_callback(
            '/{{\^([^}]+)}}(.*?){{\/\1}}/s',
            fn (array $matches): string => trim($fieldsByName[trim((string) $matches[1])] ?? '') === '' ? (string) $matches[2] : '',
            (string) $rendered,
        );
        $rendered = preg_replace_callback(
            '/{{([^}]+)}}/',
            function (array $matches) use ($fieldsByName): string {
                $fieldName = trim((string) $matches[1]);

                if (str_contains($fieldName, ':')) {
                    [, $fieldName] = explode(':', $fieldName, 2);
                    $fieldName = trim($fieldName);
                }

                return $fieldsByName[$fieldName] ?? '';
            },
            (string) $rendered,
        );

        return $this->plainCardText((string) $rendered);
    }

    /**
     * @param  list<string>  $fieldValues
     */
    private function fallbackFrontText(array $fieldValues, int $templateOrdinal): string
    {
        if ($templateOrdinal === 1 && isset($fieldValues[1])) {
            return $this->plainCardText($fieldValues[1]);
        }

        return $this->plainCardText($fieldValues[0] ?? implode(' ', $fieldValues));
    }

    /**
     * @param  list<string>  $fieldValues
     */
    private function fallbackBackText(array $fieldValues, int $templateOrdinal): string
    {
        if ($templateOrdinal === 1 && isset($fieldValues[0])) {
            return $this->plainCardText($fieldValues[0]);
        }

        return $this->plainCardText($fieldValues[1] ?? $fieldValues[0] ?? implode(' ', $fieldValues));
    }

    private function plainCardText(string $value): string
    {
        $value = preg_replace('/\[sound:[^\]\r\n]+\]/i', '', $value) ?? '';
        $value = preg_replace('/{{c\d+::(.*?)(?:::[^}]*)?}}/s', '$1', $value) ?? '';
        $value = preg_replace('/<(?:br|hr)\b[^>]*>/i', "\n", $value) ?? '';
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}

<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Models\StudyCardDraft;

final class StudyFieldMediaReferences
{
    /**
     * @return list<array{filename: string, mediaKind: 'audio'|'image', source: string}>
     */
    public static function fromText(string $value): array
    {
        return [
            ...self::audioReferencesFromText($value),
            ...self::imageReferencesFromText($value),
        ];
    }

    /**
     * @return list<string>
     */
    public static function filenamesFromText(string $value): array
    {
        $filenames = [];

        foreach (self::fromText($value) as $reference) {
            $filenames[] = $reference['filename'];
        }

        return $filenames;
    }

    /**
     * @return array{id?: string|null, filename: string, url?: string|null, mediaKind: string, source: string}|null
     */
    public static function audioFromValue(mixed $value): ?array
    {
        if (is_array($value)) {
            return self::typedMediaReference($value, 'audio');
        }

        if (! is_scalar($value)) {
            return null;
        }

        // The browser field contract is a single nullable media object; keep the first legacy marker.
        return self::audioReferencesFromText((string) $value)[0] ?? null;
    }

    /**
     * @return array{id?: string|null, filename: string, url?: string|null, mediaKind: string, source: string}|null
     */
    public static function imageFromValue(mixed $value): ?array
    {
        if (is_array($value)) {
            return self::typedMediaReference($value, 'image');
        }

        if (! is_scalar($value)) {
            return null;
        }

        // The browser field contract is a single nullable media object; keep the first legacy marker.
        return self::imageReferencesFromText((string) $value)[0] ?? null;
    }

    /**
     * @return list<array{filename: string, mediaKind: 'audio', source: 'imported'}>
     */
    private static function audioReferencesFromText(string $value): array
    {
        preg_match_all('/\[sound:([^\]\r\n]+)\]/i', $value, $soundMatches);

        $references = [];

        foreach ($soundMatches[1] ?? [] as $filename) {
            $reference = self::textReference($filename, 'audio', 'imported');

            if ($reference !== null) {
                $references[] = $reference;
            }
        }

        return $references;
    }

    /**
     * @return list<array{filename: string, mediaKind: 'image', source: 'imported_image'}>
     */
    private static function imageReferencesFromText(string $value): array
    {
        preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^"\'\s>]+))/i', $value, $imageMatches, PREG_SET_ORDER);

        $references = [];

        foreach ($imageMatches as $imageMatch) {
            $filename = ($imageMatch[1] ?? '') !== ''
                ? $imageMatch[1]
                : (($imageMatch[2] ?? '') !== '' ? $imageMatch[2] : ($imageMatch[3] ?? ''));
            $reference = self::textReference($filename, 'image', 'imported_image');

            if ($reference !== null) {
                $references[] = $reference;
            }
        }

        return $references;
    }

    /**
     * @param  'audio'|'image'  $mediaKind
     * @param  'imported'|'imported_image'  $source
     * @return array{filename: string, mediaKind: 'audio'|'image', source: string}|null
     */
    private static function textReference(string $filename, string $mediaKind, string $source): ?array
    {
        $filename = trim(html_entity_decode($filename, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($filename === '') {
            return null;
        }

        return [
            'filename' => $filename,
            'mediaKind' => $mediaKind,
            'source' => $source,
        ];
    }

    /**
     * @return array{id?: string|null, filename: string, url?: string|null, mediaKind: string, source: string}|null
     */
    private static function typedMediaReference(array $value, string $mediaKind): ?array
    {
        if (($value['mediaKind'] ?? null) !== $mediaKind) {
            return null;
        }

        $filename = $value['filename'] ?? null;
        $source = $value['source'] ?? null;

        if (! is_string($filename)
            || trim($filename) === ''
            || ! is_string($source)
            || ! in_array($source, StudyCardDraft::MEDIA_SOURCES, true)) {
            return null;
        }

        $reference = [];

        foreach (StudyCardDraft::MEDIA_REF_ALLOWED_KEYS as $key) {
            if (! array_key_exists($key, $value)) {
                continue;
            }

            $fieldValue = $value[$key];

            if ($fieldValue !== null && ! is_string($fieldValue)) {
                return null;
            }

            $reference[$key] = $key === 'filename' ? trim($fieldValue) : $fieldValue;
        }

        return $reference;
    }
}

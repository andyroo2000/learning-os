<?php

namespace App\Domain\Study\Support;

use App\Domain\Study\Models\StudyCardDraft;

final class StudyFieldMediaReferences
{
    /**
     * @return list<array{id: null, filename: string, url: null, mediaKind: 'audio'|'image', source: string}>
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

        return array_values(array_unique($filenames));
    }

    /**
     * @return array{audio: array{id: string|null, filename: string, url: string|null, mediaKind: 'audio'|'image', source: 'imported'|'generated'|'missing'|'imported_image'|'imported_other'}|null, image: array{id: string|null, filename: string, url: string|null, mediaKind: 'audio'|'image', source: 'imported'|'generated'|'missing'|'imported_image'|'imported_other'}|null}
     */
    public static function fromValue(mixed $value): array
    {
        if (is_array($value)) {
            return [
                'audio' => self::typedMediaReference($value, 'audio'),
                'image' => self::typedMediaReference($value, 'image'),
            ];
        }

        if (! is_scalar($value)) {
            return [
                'audio' => null,
                'image' => null,
            ];
        }

        // Browser fields expose one nullable media object per kind; import extraction keeps all refs via fromText().
        return [
            'audio' => self::audioReferencesFromText((string) $value)[0] ?? null,
            'image' => self::imageReferencesFromText((string) $value)[0] ?? null,
        ];
    }

    /**
     * @return array{id: string|null, filename: string, url: string|null, mediaKind: 'audio'|'image', source: 'imported'|'generated'|'missing'|'imported_image'|'imported_other'}|null
     */
    public static function audioFromValue(mixed $value): ?array
    {
        return self::fromValue($value)['audio'];
    }

    /**
     * @return array{id: string|null, filename: string, url: string|null, mediaKind: 'audio'|'image', source: 'imported'|'generated'|'missing'|'imported_image'|'imported_other'}|null
     */
    public static function imageFromValue(mixed $value): ?array
    {
        return self::fromValue($value)['image'];
    }

    /**
     * @return list<array{id: null, filename: string, url: null, mediaKind: 'audio', source: 'imported'}>
     */
    private static function audioReferencesFromText(string $value): array
    {
        preg_match_all('/\[sound:([^\]\r\n]+)\]/i', $value, $soundMatches);

        $references = [];

        foreach ($soundMatches[1] ?? [] as $filename) {
            $reference = self::textReference($filename, 'audio', StudyCardDraft::MEDIA_SOURCE_IMPORTED);

            if ($reference !== null) {
                $references[] = $reference;
            }
        }

        return $references;
    }

    /**
     * @return list<array{id: null, filename: string, url: null, mediaKind: 'image', source: 'imported_image'}>
     */
    private static function imageReferencesFromText(string $value): array
    {
        // Unlike the old import-local regex, quoted src values may contain spaces.
        preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^"\'\s>]+))/i', $value, $imageMatches, PREG_SET_ORDER);

        $references = [];

        foreach ($imageMatches as $imageMatch) {
            $filename = ($imageMatch[1] ?? '') !== ''
                ? $imageMatch[1]
                : (($imageMatch[2] ?? '') !== '' ? $imageMatch[2] : ($imageMatch[3] ?? ''));
            $reference = self::textReference($filename, 'image', StudyCardDraft::MEDIA_SOURCE_IMPORTED_IMAGE);

            if ($reference !== null) {
                $references[] = $reference;
            }
        }

        return $references;
    }

    /**
     * @param  'audio'|'image'  $mediaKind
     * @param  'imported'|'imported_image'  $source
     * @return array{id: null, filename: string, url: null, mediaKind: 'audio'|'image', source: 'imported'|'imported_image'}|null
     */
    private static function textReference(string $filename, string $mediaKind, string $source): ?array
    {
        $filename = trim(html_entity_decode($filename, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($filename === '') {
            return null;
        }

        return [
            'id' => null,
            'filename' => $filename,
            'url' => null,
            'mediaKind' => $mediaKind,
            'source' => $source,
        ];
    }

    /**
     * @return array{id: string|null, filename: string, url: string|null, mediaKind: 'audio'|'image', source: 'imported'|'generated'|'missing'|'imported_image'|'imported_other'}|null
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

        $id = $value['id'] ?? null;
        $url = $value['url'] ?? null;

        if ($id !== null && ! is_string($id)) {
            return null;
        }

        if ($url !== null && ! is_string($url)) {
            return null;
        }

        return [
            'id' => $id,
            'filename' => trim($filename),
            'url' => $url,
            'mediaKind' => $mediaKind,
            'source' => $source,
        ];
    }
}

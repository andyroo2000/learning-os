<?php

namespace App\Domain\Study\Support;

final class StudyClozeRuby
{
    public static function maskedRuby(?string $display, ?string $restored, ?string $ruby): ?string
    {
        if (! self::hasMaskInputs($display, $restored, $ruby)) {
            return null;
        }

        if (! self::maskMatchesRestoredText($display, $restored, $ruby)) {
            return null;
        }

        [$prefix, $suffix] = explode('[...]', $display, 2);
        if (! str_starts_with($restored, $prefix) || ! str_ends_with($restored, $suffix)) {
            return null;
        }

        // Imported readings may add word-separating spaces. Slice in the reading's
        // own plain-text coordinates, never offsets from the unspaced answer.
        $rubyPlain = StudyRubyText::rubyPlainText($ruby);
        $prefixLength = self::rubyAffixLength($rubyPlain, $prefix, fromStart: true);
        $suffixLength = self::rubyAffixLength($rubyPlain, $suffix, fromStart: false);
        $length = mb_strlen($rubyPlain);
        if ($prefixLength === null || $suffixLength === null) {
            return null;
        }
        if ($prefixLength + $suffixLength > $length) {
            return null;
        }

        return StudyRubyText::sliceRuby($ruby, 0, $prefixLength)
            .'[...]'.StudyRubyText::sliceRuby($ruby, $length - $suffixLength, $length);
    }

    private static function rubyAffixLength(string $rubyPlain, string $affix, bool $fromStart): ?int
    {
        if ($affix === '') {
            return 0;
        }
        $characters = mb_str_split(StudyRubyText::withoutWhitespace($affix));
        $quoted = array_map(fn (string $character): string => preg_quote($character, '/'), $characters);
        $pattern = '\\s*'.implode('\\s*', $quoted).'\\s*';
        $pattern = $fromStart ? '/\\A'.$pattern.'/u' : '/'.$pattern.'\\z/u';

        return preg_match($pattern, $rubyPlain, $matches) === 1 ? mb_strlen($matches[0]) : null;
    }

    private static function hasMaskInputs(?string $display, ?string $restored, ?string $ruby): bool
    {
        return $display !== null && $restored !== null && $ruby !== null && StudyRubyText::hasRuby($ruby);
    }

    private static function maskMatchesRestoredText(string $display, string $restored, string $ruby): bool
    {
        return substr_count($display, '[...]') === 1
            && StudyRubyText::withoutWhitespace(StudyRubyText::rubyPlainText($ruby)) === StudyRubyText::withoutWhitespace($restored);
    }
}

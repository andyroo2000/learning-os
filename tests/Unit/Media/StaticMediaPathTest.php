<?php

namespace Tests\Unit\Media;

use App\Domain\Media\Support\StaticMediaPath;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaticMediaPathTest extends TestCase
{
    #[DataProvider('toolAudioNormalizationCases')]
    public function test_tool_audio_paths_are_normalized_without_accepting_query_or_fragment_data(
        string $input,
        string $expected,
    ): void {
        $this->assertSame($expected, StaticMediaPath::normalizeToolAudio($input));
    }

    #[DataProvider('avatarCases')]
    public function test_avatar_paths_use_the_shared_allowlist(string $path, bool $expected): void
    {
        $this->assertSame($expected, StaticMediaPath::isAvatar($path));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function toolAudioNormalizationCases(): iterable
    {
        yield 'absolute URL' => [
            ' https://cdn.example/tools-audio/japanese/minute/44.mp3 ',
            '/tools-audio/japanese/minute/44.mp3',
        ];
        yield 'relative path' => [
            ' /tools-audio/japanese/minute/44.mp3 ',
            '/tools-audio/japanese/minute/44.mp3',
        ];
        yield 'query retained for validation failure' => [
            '/tools-audio/japanese/minute/44.mp3?token=secret',
            '/tools-audio/japanese/minute/44.mp3?token=secret',
        ];
        yield 'fragment retained for validation failure' => [
            '/tools-audio/japanese/minute/44.mp3#secret',
            '/tools-audio/japanese/minute/44.mp3#secret',
        ];
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function avatarCases(): iterable
    {
        yield 'voice avatar' => ['voices/ja-shohei.jpg', true];
        yield 'root avatar' => ['ja-male-casual.jpg', true];
        yield 'traversal' => ['voices/../../secret.jpg', false];
        yield 'wrong extension' => ['voices/ja-shohei.png', false];
    }
}

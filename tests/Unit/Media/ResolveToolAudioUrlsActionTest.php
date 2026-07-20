<?php

namespace Tests\Unit\Media;

use App\Domain\Media\Actions\ResolveToolAudioUrlsAction;
use App\Domain\Media\Contracts\StaticMediaObjectStore;
use App\Domain\Media\Support\StaticMediaPath;
use App\Domain\Media\Support\StaticMediaSettings;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResolveToolAudioUrlsActionTest extends TestCase
{
    #[DataProvider('invalidDirectCallerPaths')]
    public function test_it_rejects_invalid_direct_caller_paths(array $paths): void
    {
        $action = $this->actionWithoutStorageAccess();

        $this->expectException(InvalidArgumentException::class);

        $action->handle($paths);
    }

    public function test_it_accepts_the_maximum_number_of_allowlisted_paths(): void
    {
        config(['static_media.tool_audio.signed_urls_enabled' => false]);
        $action = $this->actionWithoutStorageAccess();
        $paths = array_map(
            fn (int $index): string => "/tools-audio/japanese/minute/{$index}.mp3",
            range(1, StaticMediaPath::MAX_TOOL_AUDIO_PATHS),
        );

        $result = $action->handle($paths);

        $this->assertSame('passthrough', $result['mode']);
        $this->assertCount(StaticMediaPath::MAX_TOOL_AUDIO_PATHS, $result['urls']);
        $this->assertSame($paths[0], $result['urls'][$paths[0]]['url']);
        $this->assertSame($paths[59], $result['urls'][$paths[59]]['url']);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidDirectCallerPaths(): iterable
    {
        yield 'empty' => [[]];
        yield 'unsafe path' => [['/tools-audio/../../secret.mp3']];
        yield 'trailing newline' => [["/tools-audio/valid.mp3\n"]];
        yield 'non-string path' => [[42]];
        yield 'too many paths' => [[
            ...array_map(
                fn (int $index): string => "/tools-audio/japanese/minute/{$index}.mp3",
                range(0, StaticMediaPath::MAX_TOOL_AUDIO_PATHS),
            ),
        ]];
    }

    private function actionWithoutStorageAccess(): ResolveToolAudioUrlsAction
    {
        $store = Mockery::mock(StaticMediaObjectStore::class);
        $store->shouldNotReceive('exists');
        $store->shouldNotReceive('signedReadUrl');

        return new ResolveToolAudioUrlsAction($store, new StaticMediaSettings);
    }
}

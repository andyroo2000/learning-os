<?php

namespace Tests\Feature\Storage;

use App\Support\Storage\PrivateGoogleCloudFilesystem;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Storage\StorageObject;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class PrivateGoogleCloudFilesystemTest extends TestCase
{
    public function test_upload_uses_private_cache_metadata_and_no_public_acl(): void
    {
        $bucket = $this->bucket();
        $bucket->shouldReceive('upload')->once()->with('ID3audio', Mockery::on(function (array $options): bool {
            $this->assertSame('production/private/journal/entry/voice.mp3', $options['name']);
            $this->assertArrayNotHasKey('predefinedAcl', $options);
            $this->assertSame('private, no-store', $options['metadata']['cacheControl']);

            return true;
        }));
        $disk = Storage::build($this->configuration());
        $this->assertTrue($disk->put('journal/entry/voice.mp3', 'ID3audio'));
    }

    public function test_read_stream_uses_cloud_streaming_and_preserves_binary_bytes(): void
    {
        $bucket = $this->bucket();
        $object = Mockery::mock(StorageObject::class);
        $bucket->shouldReceive('object')->with('production/private/journal/entry/voice.mp3')->once()->andReturn($object);
        $object->shouldReceive('downloadAsStream')->with(['restOptions' => ['stream' => true]])->once()
            ->andReturn(Utils::streamFor("ID3\0\xffaudio"));
        $stream = Storage::build($this->configuration())->readStream('journal/entry/voice.mp3');
        try {
            $this->assertSame("ID3\0\xffaudio", stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    public function test_migration_uploads_use_a_generation_precondition(): void
    {
        $bucket = $this->bucket();
        $bucket->shouldReceive('upload')->once()->with(Mockery::type('resource'), Mockery::on(function (array $options): bool {
            $this->assertSame(0, $options['ifGenerationMatch']);
            $this->assertSame('production/private/track.mp3', $options['name']);
            $this->assertSame('audio/mpeg', $options['metadata']['contentType']);
            $this->assertArrayNotHasKey('predefinedAcl', $options);

            return true;
        }));
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, 'audio');
        rewind($stream);
        try {
            $this->assertTrue(Storage::build($this->configuration())->put('track.mp3', $stream, [
                'create_only' => true, 'mimetype' => 'audio/mpeg',
            ]));
        } finally {
            fclose($stream);
        }
    }

    public function test_cloud_storage_requires_an_explicit_environment_prefix(): void
    {
        $this->expectException(RuntimeException::class);
        (new PrivateGoogleCloudFilesystem)->make(['bucket' => 'private-test-bucket']);
    }

    public function test_missing_bucket_fails_instead_of_falling_back_to_local_storage(): void
    {
        $this->expectException(RuntimeException::class);
        (new PrivateGoogleCloudFilesystem)->make(['gcs_prefix' => 'production/private']);
    }

    private function configuration(): array
    {
        return ['driver' => 'gcs-private', 'bucket' => 'private-test-bucket', 'gcs_prefix' => 'production/private', 'throw' => true];
    }

    private function bucket(): Bucket
    {
        $client = Mockery::mock(StorageClient::class);
        $bucket = Mockery::mock(Bucket::class);
        $client->shouldReceive('bucket')->with('private-test-bucket')->once()->andReturn($bucket);
        $this->app->instance(StorageClient::class, $client);

        return $bucket;
    }
}

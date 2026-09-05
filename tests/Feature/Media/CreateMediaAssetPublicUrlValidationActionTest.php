<?php

namespace Tests\Feature\Media;

use App\Domain\Media\Models\MediaAsset;

class CreateMediaAssetPublicUrlValidationActionTest extends CreateMediaAssetActionTestCase
{
    private const PRIVATE_HOST_MESSAGE = 'Media asset public URL must not use a private or reserved host.';

    public function test_it_rejects_invalid_public_url(): void
    {
        $this->assertMediaAssetInputRejected(
            ['publicUrl' => 'not-a-url'],
            'Media asset public URL must be a valid URL.',
        );
    }

    public function test_it_rejects_non_http_public_url(): void
    {
        $this->assertMediaAssetInputRejected(
            ['publicUrl' => 'ftp://cdn.example.test/uploads/example.jpg'],
            'Media asset public URL must use the http or https scheme.',
        );
    }

    public function test_it_rejects_localhost_public_url(): void
    {
        $this->assertMediaAssetInputRejected(
            ['publicUrl' => 'https://localhost/uploads/example.jpg'],
            self::PRIVATE_HOST_MESSAGE,
        );
    }

    public function test_it_rejects_private_ip_public_url(): void
    {
        $this->assertMediaAssetInputRejected(['publicUrl' => 'https://10.0.0.1/uploads/example.jpg'], self::PRIVATE_HOST_MESSAGE);
    }

    public function test_it_rejects_decimal_ip_public_url(): void
    {
        $this->assertMediaAssetInputRejected(['publicUrl' => 'https://2130706433/uploads/example.jpg'], self::PRIVATE_HOST_MESSAGE);
    }

    public function test_it_rejects_hex_ip_public_url(): void
    {
        $this->assertMediaAssetInputRejected(['publicUrl' => 'https://0x7f000001/uploads/example.jpg'], self::PRIVATE_HOST_MESSAGE);
    }

    public function test_it_rejects_zero_address_public_url(): void
    {
        $this->assertMediaAssetInputRejected(['publicUrl' => 'https://0.0.0.0/uploads/example.jpg'], self::PRIVATE_HOST_MESSAGE);
    }

    public function test_it_rejects_link_local_public_url(): void
    {
        $this->assertMediaAssetInputRejected(
            ['publicUrl' => 'https://169.254.169.254/uploads/example.jpg'],
            self::PRIVATE_HOST_MESSAGE,
        );
    }

    public function test_it_rejects_ipv6_loopback_public_url(): void
    {
        $this->assertMediaAssetInputRejected(['publicUrl' => 'https://[::1]/uploads/example.jpg'], self::PRIVATE_HOST_MESSAGE);
    }

    public function test_it_rejects_ipv4_mapped_ipv6_public_url(): void
    {
        $this->assertMediaAssetInputRejected(
            ['publicUrl' => 'https://[::ffff:10.0.0.1]/uploads/example.jpg'],
            self::PRIVATE_HOST_MESSAGE,
        );
    }

    public function test_it_rejects_non_canonical_ipv4_mapped_ipv6_public_url(): void
    {
        $this->assertMediaAssetInputRejected(
            ['publicUrl' => 'https://[0:0:0:0:0:ffff:a00:1]/uploads/example.jpg'],
            self::PRIVATE_HOST_MESSAGE,
        );
    }

    public function test_it_rejects_siit_public_url(): void
    {
        $this->assertMediaAssetInputRejected(
            ['publicUrl' => 'https://[::ffff:0:10.0.0.1]/uploads/example.jpg'],
            self::PRIVATE_HOST_MESSAGE,
        );
    }

    public function test_it_rejects_nat64_public_url(): void
    {
        $this->assertMediaAssetInputRejected(
            ['publicUrl' => 'https://[64:ff9b::10.0.0.1]/uploads/example.jpg'],
            self::PRIVATE_HOST_MESSAGE,
        );
    }

    public function test_it_rejects_6to4_public_url(): void
    {
        $this->assertMediaAssetInputRejected(
            ['publicUrl' => 'https://[2002:0a00:0001::]/uploads/example.jpg'],
            self::PRIVATE_HOST_MESSAGE,
        );
    }

    public function test_it_rejects_teredo_public_url(): void
    {
        $this->assertMediaAssetInputRejected(
            ['publicUrl' => 'https://[2001:0000:0a00:0001::]/uploads/example.jpg'],
            self::PRIVATE_HOST_MESSAGE,
        );
    }

    public function test_it_rejects_public_url_longer_than_column_limit(): void
    {
        $prefix = 'https://cdn.example.test/';
        $url = $prefix.str_repeat('a', MediaAsset::MAX_PUBLIC_URL_LENGTH - mb_strlen($prefix) + 1);

        $this->assertMediaAssetInputRejected(
            ['publicUrl' => $url],
            'Media asset public URL must not exceed '.MediaAsset::MAX_PUBLIC_URL_LENGTH.' characters.',
        );
    }
}

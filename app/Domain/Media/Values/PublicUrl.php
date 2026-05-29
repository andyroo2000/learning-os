<?php

namespace App\Domain\Media\Values;

use InvalidArgumentException;

final class PublicUrl
{
    private function __construct() {}

    public static function assertValid(string $value, int $maxLength): void
    {
        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException('Media asset public URL must not exceed '.$maxLength.' characters.');
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Media asset public URL must be a valid URL.');
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Media asset public URL must use the http or https scheme.');
        }

        self::assertUsesPublicHost($value);
    }

    private static function assertUsesPublicHost(string $value): void
    {
        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException('Media asset public URL must include a host.');
        }

        $host = strtolower(trim($host, '[]'));

        if (
            $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || self::usesNonCanonicalIpNotation($host)
            || self::usesPrivateAddressEncoding($host)
        ) {
            throw new InvalidArgumentException('Media asset public URL must not use a private or reserved host.');
        }

        if (
            filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        ) {
            throw new InvalidArgumentException('Media asset public URL must not use a private or reserved host.');
        }
    }

    private static function usesNonCanonicalIpNotation(string $host): bool
    {
        $integerPart = '(?:0x[0-9a-f]+|0[0-7]+|[0-9]+)';

        return preg_match('/^'.$integerPart.'$/i', $host) === 1
            || preg_match('/^'.$integerPart.'(?:\.'.$integerPart.'){1,3}$/i', $host) === 1;
    }

    private static function usesPrivateAddressEncoding(string $host): bool
    {
        $packed = @inet_pton($host);

        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }

        $embeddedIpv4 = self::embeddedIpv4Address($packed);

        return $embeddedIpv4 !== null
            && filter_var($embeddedIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private static function embeddedIpv4Address(string $packed): ?string
    {
        $hex = bin2hex($packed);

        if (str_starts_with($hex, '00000000000000000000ffff')) {
            $bytes = substr($packed, 12, 4);
        } elseif (str_starts_with($hex, '0000000000000000ffff0000')) {
            $bytes = substr($packed, 12, 4);
        } elseif (str_starts_with($hex, '0064ff9b0000000000000000')) {
            $bytes = substr($packed, 12, 4);
        } elseif (str_starts_with($hex, '2002')) {
            $bytes = substr($packed, 2, 4);
        } elseif (str_starts_with($hex, '20010000')) {
            // Block all Teredo hosts; the embedded client IPv4 is obfuscated.
            return '0.0.0.0';
        } else {
            return null;
        }

        return implode('.', array_values(unpack('C4', $bytes)));
    }
}

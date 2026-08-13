<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\ICE;

use Random\RandomException;
use function parse_url;

/**
 * Utility class for ICE-related operations.
 */
class Utils
{
    /**
     * Generate a random alphanumeric string of a given length.
     *
     * @param int $length The desired length of the string.
     * @return string The generated random string.
     * @throws RandomException If randomness cannot be generated securely.
     */
    public static function getRandomString(int $length): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $index = random_int(0, $charactersLength - 1);
            $randomString .= $characters[$index];
        }

        return $randomString;
    }

    /**
     * Determine the IP version of a given IP address.
     *
     * @param string $ip The IP address to check.
     * @return int|false Returns 4 for IPv4, 6 for IPv6, or false if invalid.
     */
    public static function IPVersion(string $ip): int|bool
    {
        $packedIp = @inet_pton($ip);
        if ($packedIp === false) {
            return false;
        }

        return isset($packedIp[4]) ? 6 : 4;
    }

    /**
     * Generate a random positive 64-bit integer.
     *
     * @return string The random 64-bit integer as a decimal string.
     * @throws RandomException If randomness cannot be generated securely.
     */
    public static function generateRandom64BitInt(): string
    {
        $high = random_int(0, 0x7FFFFFFF);
        $low = random_int(0, 0xFFFFFFFF);

        $random64BitInt = ($high << 32) | $low;

        return gmp_strval(gmp_init($random64BitInt, 10));
    }

    /**
     * Parse an address string to extract host and port.
     *
     * @param string $address The input address (with or without a scheme).
     * @return array{0: string|null, 1: int|null} Array of host and port.
     */
    public static function parseAddressToHostPort(string $address): array
    {
        if (!str_contains($address, '://')) {
            $address = 'udp://' . $address;
        }

        $address = parse_url($address);

        return [trim($address['host'], '[]') ?? null, $address['port'] ?? null];
    }
}

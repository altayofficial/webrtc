<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SCTP;

use Random\RandomException;

/**
 * SCTP Utility class.
 *
 * Provides various helper methods for SCTP protocol operations including
 * - Parameter encoding/decoding
 * - Checksum calculation
 * - Sequence number arithmetic
 * - Random number generation
 * - Binary data handling
 *
 * All methods are static as this is a utility class with no instance state.
 */
class SctpUtility
{
    /**
     * Gets the type/class name of a chunk object.
     *
     * @param object $chunk The chunk object to inspect
     * @return string The fully qualified class name of the chunk
     */
    public static function chunkType(object $chunk): string
    {
        return get_class($chunk);
    }

    /**
     * Decodes SCTP parameters from a binary string.
     *
     * Parses the binary parameter format defined in RFC 4960 Section 3.2.1:
     * - 16-bit parameter type
     * - 16-bit parameter length (including header)
     * - Parameter value
     * - Optional padding to 4-byte boundary
     *
     * @param string $body The binary string containing the parameters
     * @return array<array{0: int, 1: string}> Array of parameter tuples, each containing:
     *         - [0] Parameter types (16-bit unsigned integer)
     *         - [1] Parameter value (binary string)
     */
    public static function decodeParams(string $body): array
    {
        $params = [];
        $pos = 0;
        $length = strlen($body);

        while ($pos <= $length - 4) {
            $paramType = unpack("n", substr($body, $pos, 2))[1];
            $paramLength = unpack("n", substr($body, $pos + 2, 2))[1];
            $paramValue = substr($body, $pos + 4, $paramLength - 4);
            $params[] = [$paramType, $paramValue];
            $pos += $paramLength + self::padl($paramLength);
        }

        return $params;
    }

    /**
     * Encodes SCTP parameters into a binary string.
     *
     * Creates the binary parameter format defined in RFC 4960 Section 3.2.1:
     * - 16-bit parameter type
     * - 16-bit parameter length (including header)
     * - Parameter value
     * - Optional padding to 4-byte boundary
     *
     * @param array<array{0: int, 1: string}> $params Array of parameter tuples, each containing:
     *        - [0] Parameter types (16-bit unsigned integer)
     *        - [1] Parameter value (binary string)
     * @return string The binary string representation of the parameters
     */
    public static function encodeParams(array $params): string
    {
        $body = "";
        $padding = "";
        foreach ($params as [$paramType, $paramValue]) {
            $paramLength = strlen($paramValue) + 4;
            $body .= $padding;
            $body .= pack("nn", $paramType, $paramLength) . $paramValue;
            $padding = str_repeat("\x00", self::padl($paramLength));
        }

        return $body;
    }

    /**
     * Calculates required padding length for SCTP parameters.
     *
     * SCTP parameters must be padded to 4-byte boundaries as per RFC 4960.
     *
     * @param int $length The length of the parameter (including header)
     * @return int The number of padding bytes needed (0-3)
     */
    public static function padl(int $length): int
    {
        $m = $length % 4;
        return $m ? 4 - $m : 0;
    }

    /**
     * Generates a cryptographically secure random 16-bit unsigned integer.
     *
     * @return int Random value between 0 and 65535 (inclusive)
     * @throws RandomException If random bytes cannot be generated
     */
    public static function random16(): int
    {
        return unpack("n", random_bytes(2))[1];
    }

    /**
     * Generates a cryptographically secure random 32-bit unsigned integer.
     *
     * @return int Random value between 0 and 4294967295 (inclusive)
     * @throws RandomException If random bytes cannot be generated
     */
    public static function random32(): int
    {
        return unpack("N", random_bytes(4))[1];
    }

    /**
     * Decrements a TSN (Transmission Sequence Number) with proper modulo arithmetic.
     *
     * Handles wrap-around of 32-bit TSN space as defined by SCTP_TSN_MODULO.
     *
     * @param int $a The TSN value to decrement
     * @return int (a - 1) modulo SCTP_TSN_MODULO
     */
    public static function tsnMinusOne(int $a): int
    {
        return ($a - 1 + SctpConstant::SCTP_TSN_MODULO) % SctpConstant::SCTP_TSN_MODULO;
    }

    /**
     * Increments a TSN (Transmission Sequence Number) with proper modulo arithmetic.
     *
     * Handles wrap-around of 32-bit TSN space as defined by SCTP_TSN_MODULO.
     *
     * @param int $a The TSN value to increment
     * @return int (a + 1) modulo SCTP_TSN_MODULO
     */
    public static function tsnPlusOne(int $a): int
    {
        return ($a + 1) % SctpConstant::SCTP_TSN_MODULO;
    }

    /**
     * Calculates CRC32C checksum (Castagnoli variant) for SCTP packets.
     *
     * Implements the checksum algorithm defined in RFC 3309 for SCTP.
     * Uses polynomial 0x1EDC6F41 (reversed to 0x82F63B78).
     *
     * @param string $data The data to calculate checksum for
     * @return int The 32-bit CRC32C checksum
     */
    public static function crc32c(string $data): int
    {
        static $table;
        if (!$table) {
            for ($n = 0; $n < 256; $n++) {
                $c = $n;
                for ($k = 0; $k < 8; $k++) {
                    if ($c & 1) {
                        $c = 0x82F63B78 ^ ($c >> 1);
                    } else {
                        $c >>= 1;
                    }
                }
                $table[$n] = $c;
            }
        }

        $crc = 0xFFFFFFFF;
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $crc = $table[($crc ^ ord($data[$i])) & 0xFF] ^ ($crc >> 8);
        }

        return $crc ^ 0xFFFFFFFF;
    }

    /**
     * Compares two unsigned 32-bit integers accounting for wrap-around.
     *
     * @param int $a First unsigned 32-bit integer
     * @param int $b Second unsigned 32-bit integer
     * @return bool True if $a is greater than $b considering wrap-around
     */
    public static function uint32Gt(int $a, int $b): bool
    {
        $halfMod = 0x80000000; // 2^31
        return (($a < $b) && (($b - $a) > $halfMod)) || (($a > $b) && (($a - $b) < $halfMod));
    }

    /**
     * Compares two unsigned 32-bit integers accounting for wrap-around.
     *
     * @param int $a First unsigned 32-bit integer
     * @param int $b Second unsigned 32-bit integer
     * @return bool True, if $a is greater than or equal to $b considering wrap-around
     */
    public static function uint32Gte(int $a, int $b): bool
    {
        return ($a === $b) || self::uint32Gt($a, $b);
    }

    /**
     * Compares two unsigned 16-bit integers accounting for wrap-around.
     *
     * @param int $a First unsigned 16-bit integer
     * @param int $b Second unsigned 16-bit integer
     * @return bool True if $a is greater than $b considering wrap-around
     */
    public static function uint16Gt(int $a, int $b): bool
    {
        $halfMod = 0x8000;
        return (($a < $b) && (($b - $a) > $halfMod)) || (($a > $b) && (($a - $b) < $halfMod));
    }

    /**
     * Adds two unsigned 16-bit integers with proper wrap-around.
     *
     * @param int $a First unsigned 16-bit integer
     * @param int $b Second unsigned 16-bit integer
     * @return int ($a + $b) modulo 65536
     */
    public static function uint16Add(int $a, int $b): int
    {
        return ($a + $b) & 0xFFFF;
    }

    /**
     * Checks if a string contains binary (non-ASCII) data.
     *
     * @param string $str The string to check
     * @return bool True if the string contains bytes outside ASCII printable range
     */
    public static function isBinary(string $str): bool
    {
        return preg_match('~[^\x09\x0A\x0D\x20-\x7E]~', $str) > 0;
    }
}
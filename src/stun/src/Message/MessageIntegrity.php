<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\STUN\Message;

/**
 * Class to handle message integrity and fingerprint calculations.
 */
class MessageIntegrity
{
    /**
     * @var int
     */
    public const HEADER_LENGTH = 20;
    /**
     * @var int
     */
    private const INTEGRITY_LENGTH = 24;
    /**
     * @var int
     */
    private const FINGERPRINT_LENGTH = 8;
    /**
     * @var int
     */
    private const FINGERPRINT_XOR = 0x5354554E;

    /**
     * Set the body length in the message data.
     *
     * @param string $data The message data.
     * @param int $length The body length to set.
     * @return string The modified message data with the new length.
     */
    private static function setBodyLength(string $data, int $length): string
    {
        return substr($data, 0, 2) . pack("n", $length) . substr($data, 4);
    }

    /**
     * Calculate the message fingerprint.
     *
     * @param string $data The message data.
     * @return int The calculated fingerprint.
     */
    public static function messageFingerprint(string $data): int
    {
        $checkData = static::setBodyLength($data, strlen($data) - self::HEADER_LENGTH + self::FINGERPRINT_LENGTH);
        return crc32($checkData) ^ self::FINGERPRINT_XOR;
    }

    /**
     * Calculate the message integrity.
     *
     * @param string $data The message data.
     * @param string $key The key for HMAC.
     * @return string The calculated HMAC.
     */
    public static function messageIntegrity(string $data, string $key): string
    {
        $checkData = static::setBodyLength($data, strlen($data) - self::HEADER_LENGTH + self::INTEGRITY_LENGTH);
        return hash_hmac('sha1', $checkData, $key, true);
    }
}
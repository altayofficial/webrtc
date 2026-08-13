<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP;

use Webrtc\Exception\InvalidArgumentException;

/**
 * Provides utility functions for RTP/RTCP packet processing.
 *
 * This class contains static helper methods for various RTP/RTCP operations including
 * - Packet loss calculation and clamping
 * - Header extension processing
 * - Audio level computation
 * - RTX packet wrapping/unwrapping
 * - REMB feedback message handling
 */
class RtpUtility
{
    /**
     * @var int
     */
    private const MAX_SAMPLE_VALUE = 32767;
    /**
     * @var int
     */
    private const MAX_AUDIO_LEVEL = 0;
    /**
     * @var int
     */
    private const MIN_AUDIO_LEVEL = -127;

    /**
     * Clamps the packets lost count to valid range.
     *
     * @param int $count The raw packets lost count.
     * @return int The clamped value between PACKETS_LOST_MIN and PACKETS_LOST_MAX.
     */
    public static function clampPacketsLost(int $count): int
    {
        return max(RtpConstants::PACKETS_LOST_MIN, min($count, RtpConstants::PACKETS_LOST_MAX));
    }

    /**
     * Packs the cumulative packets lost into a 24-bit binary string.
     *
     * @param int $packetsLost The cumulative number of packets lost.
     * @return string The 24-bit binary representation of the packets lost.
     */
    public static function packPacketsLost(int $packetsLost): string
    {
        return substr(pack("N", $packetsLost), 1);
    }

    /**
     * Unpacks the cumulative packets lost from a 24-bit binary string.
     *
     * @param string $data The 24-bit binary data.
     * @return int The cumulative number of packets lost.
     */
    public static function unpackPacketsLost(string $data): int
    {
        $data = (ord($data[0]) & 0x80) ? "\xFF" . $data : "\x00" . $data;
        $value = unpack("N", $data)[1];

        // Convert to signed 32-bit integer
        if ($value & 0x80000000) {
            $value -= 0x100000000;
        }

        return $value;
    }

    /**
     * Constructs the RTCP packet header and combines it with the payload.
     *
     * This helper method creates the RTCP packet header based on the provided parameters
     * and appends the payload to it.
     *
     * @param int $packetType The RTCP packet type (e.g., RTCP_SDES).
     * @param int $count The number of source description chunks.
     * @param string $payload The binary payload of the RTCP packet.
     * @return string The complete RTCP packet as a binary string.
     */
    public static function packRtcpPacket(int $packetType, int $count, string $payload): string
    {
        if (strlen($payload) % 4 !== 0) {
            throw new InvalidArgumentException("Payload length must be a multiple of 4");
        }
        return pack("CCn", (2 << 6) | $count, $packetType, strlen($payload) / 4) . $payload;
    }

    public static function packRembFci(int $bitrate, array $ssrcs): string
    {
        $data = "REMB";
        $exponent = 0;

        if (abs($bitrate) >= abs(0x3FFFF << 63)) {
            $bitrate = 0x3FFFF;
            $exponent = 63;
        } else {
            while ($bitrate > 0x3FFFF) {
                $bitrate >>= 1;
                $exponent += 1;
            }
        }

        $data .= pack("CCn", count($ssrcs), ($exponent << 2) | ($bitrate >> 16), ($bitrate & 0xFFFF));

        foreach ($ssrcs as $ssrc) {
            $data .= pack("N", $ssrc);
        }

        return $data;
    }

    /**
     * Combines two 32-bit integers into a 64-bit integer.
     *
     * This helper method ensures correct handling of 64-bit integers on all platforms.
     *
     * @param int $high The high 32 bits.
     * @param int $low The low 32 bits.
     * @return int The combined 64-bit integer.
     */
    public static function combine64Bit(int $high, int $low): int
    {
        // On 64-bit systems, this works directly
        if (PHP_INT_SIZE >= 8) {
            return ($high << 32) | $low;
        }

        // On 32-bit systems, use bcmath for 64-bit arithmetic
        return (int)bcadd(bcmul($high, '4294967296'), $low);
    }

    public static function unpackRembFci(string $data): array
    {
        if (strlen($data) < 8 || !str_starts_with($data, "REMB")) {
            throw new InvalidArgumentException("Invalid REMB prefix");
        }

        $exponent = (ord($data[5]) & 0xFC) >> 2;
        $mantissa = ((ord($data[5]) & 0x03) << 16) | (ord($data[6]) << 8) | ord($data[7]);
        $bitrate = $mantissa << $exponent;

        $pos = 8;
        $ssrcs = [];
        for ($i = 0; $i < ord($data[4]); $i++) {
            $ssrcs[] = unpack("N", substr($data, $pos, 4))[1];
            $pos += 4;
        }

        return [$bitrate, $ssrcs];
    }

    public static function isRtcp(string $msg): bool
    {
        return strlen($msg) >= 2 && ord($msg[1]) >= 192 && ord($msg[1]) <= 208;
    }

    /**
     * Parse header extensions according to RFC 5285.
     */
    public static function unpackHeaderExtensions(int $extensionProfile, ?string $extensionValue): array
    {
        $extensions = [];
        $pos = 0;
        $length = strlen($extensionValue ?? "");

        if ($extensionProfile === 0xBEDE) {
            // One-Byte Header
            while ($pos < $length) {
                if (ord($extensionValue[$pos]) === 0) {
                    $pos++;
                    continue;
                }
                $xId = (ord($extensionValue[$pos]) & 0xF0) >> 4;
                $xLength = (ord($extensionValue[$pos]) & 0x0F) + 1;
                $pos++;
                if ($length < $pos + $xLength) {
                    throw new InvalidArgumentException("RTP one-byte header extension value is truncated");
                }
                $xValue = substr($extensionValue, $pos, $xLength);
                $extensions[] = [$xId, $xValue];
                $pos += $xLength;
            }
        } elseif ($extensionProfile === 0x1000) {
            // Two-Byte Header
            while ($pos < $length) {
                if (ord($extensionValue[$pos]) === 0) {
                    $pos++;
                    continue;
                }
                if ($length < $pos + 2) {
                    throw new InvalidArgumentException("RTP two-byte header extension is truncated");
                }

                // Extract ID and length correctly
                $xId = ord($extensionValue[$pos]);        // First byte is the ID
                $xLength = ord($extensionValue[$pos + 1]); // The Second byte is the length
                $pos += 2;

                // Ensure valid length
                if ($xLength === 0) {
                    continue; // Ignore empty extensions
                }

                if ($length < $pos + $xLength) {
                    throw new InvalidArgumentException("RTP two-byte header extension value is truncated");
                }

                $xValue = substr($extensionValue, $pos, $xLength);
                $extensions[] = [$xId, $xValue];
                $pos += $xLength;
            }
        }

        return $extensions;
    }


    /**
     * Serialize header extensions according to RFC 5285.
     */
    public static function packHeaderExtensions(array $extensions): array
    {
        if (empty($extensions)) {
            return [0, ""];
        }
        $oneByte = true;
        foreach ($extensions as [$xId, $xValue]) {
            $xLength = strlen($xValue);
            if ($xId > 14 || $xLength === 0 || $xLength > 16) {
                $oneByte = false;
            }
        }
        $extensionProfile = $oneByte ? 0xBEDE : 0x1000;
        $extensionValue = "";

        foreach ($extensions as [$xId, $xValue]) {
            $xLength = strlen($xValue);
            if ($oneByte) {
                $extensionValue .= pack("C", ($xId << 4) | ($xLength - 1));
            } else {
                $extensionValue .= pack("CC", $xId, $xLength);
            }
            $extensionValue .= $xValue;
        }

        return [$extensionProfile, $extensionValue . str_repeat("\x00", (4 - (strlen($extensionValue) % 4)) % 4)];
    }

    /**
     * Compute the energy level as spelled out in RFC 6465, Appendix A.
     *
     * @param string $data
     * @param int $samples
     * @return int The computed audio level in dBov, rounded to the nearest integer.
     */
    public static function computeAudioLevelDbov(string $data, int $samples): int
    {
        $rms = 0.0;

        // Iterate through the buffer in 16-bit (2-byte) chunks
        for ($i = 0; $i < strlen($data); $i += 2) {
            // Unpack 16-bit signed little-endian sample
            $sample = unpack('v', substr($data, $i, 2))[1];
            // Convert unsigned 16-bit to signed
            if ($sample >= 32768) {
                $sample -= 65536;
            }
            $rms += $sample * $sample; // Accumulate squared samples
        }

        // Calculate RMS (Root Mean Square)
        $rms = sqrt($rms / ($samples * self::MAX_SAMPLE_VALUE * self::MAX_SAMPLE_VALUE));

        // Convert RMS to dBov
        if ($rms > 0) {
            $db = 20 * log10($rms);
            $db = max($db, self::MIN_AUDIO_LEVEL); // Clamp to minimum level
            $db = min($db, self::MAX_AUDIO_LEVEL); // Clamp to maximum level
        } else {
            $db = self::MIN_AUDIO_LEVEL; // If RMS is 0, return minimum level
        }

        return (int)round($db); // Round to the nearest integer
    }

    public static function unwrapRtx(RtpPacket $rtx, int $payloadType, int $ssrc): RtpPacket
    {
        $packet = new RtpPacket;
        $packet->setPayloadType($payloadType);
        $packet->setMarker($rtx->getMarker());
        $packet->setSequenceNumber(unpack("n", substr($rtx->getPayload(), 0, 2))[1]);
        $packet->setTimestamp($rtx->getTimestamp());
        $packet->setSsrc($ssrc);
        $packet->setPayload(substr($rtx->getPayload(), 2));
        $packet->setCsrc($rtx->getCsrc());
        $packet->setExtensions($rtx->getExtensions());

        return $packet;
    }

    public static function wrapRtx(RtpPacket $packet, int $payloadType, int $sequenceNumber, int $ssrc): RtpPacket
    {
        $rtx = new RtpPacket;
        $rtx->setPayloadType($payloadType);
        $rtx->setSequenceNumber($sequenceNumber);
        $rtx->setSsrc($ssrc);
        $rtx->setMarker($packet->getMarker());
        $rtx->setCsrc($packet->getCsrc());
        $rtx->setTimestamp($packet->getTimestamp());
        $rtx->setExtensions($packet->getExtensions());
        $rtx->setPayload(pack("n", $packet->getSequenceNumber()) . $packet->getPayload());

        return $rtx;
    }
}

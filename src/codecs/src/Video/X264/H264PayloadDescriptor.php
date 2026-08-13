<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Codecs\Video\X264;

use Webrtc\Codecs\PayloadDescriptorInterface;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\NotImplementedException;

/**
 * H.264 Payload Descriptor Class
 *
 * Handles parsing and reconstruction of H.264 payloads in RTP packets, according to RFC 6184.
 * Supports three packetization modes: Single NAL Unit, Fragmentation Unit (FU-A), and
 * Single-Time Aggregation Packet (STAP-A).
 *
 * @package Webrtc\Codecs\Video\X264
 */
class H264PayloadDescriptor implements PayloadDescriptorInterface
{
    /**
     * @var int NAL_HEADER_SIZE Size of NAL unit header (1 byte)
     */
    private const NAL_HEADER_SIZE = 1;

    /**
     * @var int NAL_TYPE_FU_A Fragmentation Unit type value (28)
     */
    private const NAL_TYPE_FU_A = 28;

    /**
     * @var int NAL_TYPE_STAP_A Aggregation Packet type value (24)
     */
    private const NAL_TYPE_STAP_A = 24;

    /**
     * @var int LENGTH_FIELD_SIZE Size of length field in STAP-A (2 bytes)
     */
    private const LENGTH_FIELD_SIZE = 2;

    /**
     * Parses H.264 payload and reconstructs NAL units
     *
     * @param string $data RTP payload data
     * @return array [bool, string] Tuple containing:
     *               - Bool: Whether this is a start fragment
     *               - string: Reconstructed NAL unit(s) with start codes
     * @throws InvalidArgumentException For malformed packets
     */
    public static function decode(string $data): array
    {
        if (strlen($data) < 2) {
            throw new InvalidArgumentException("NAL unit is too short");
        }

        $nalType = ord($data[0]) & 0x1F;
        $fNri = ord($data[0]) & (0x80 | 0x60);

        return match (true) {
            $nalType >= 1 && $nalType <= 23 => self::handleSingleNalUnit($data),
            $nalType === self::NAL_TYPE_FU_A => self::handleFragmentationUnit($data, $fNri),
            $nalType === self::NAL_TYPE_STAP_A => self::handleStapA($data),
            default => throw new InvalidArgumentException("NAL unit type $nalType is not supported"),
        };

    }

    /**
     * Handles Single NAL Unit packets
     *
     * @param string $data Payload data
     * @return array [true, string] Always treats as complete unit
     */
    private static function handleSingleNalUnit(string $data): array
    {
        $output = chr(0) . chr(0) . chr(0) . chr(1) . $data;

        return [true, $output];
    }

    /**
     * Handles Fragmentation Unit (FU-A) packets
     *
     * @param string $data Payload data
     * @param int $fNri Forbidden and NRI bits
     * @return array [bool, string] Start fragment flag and reconstructed data
     */
    private static function handleFragmentationUnit(string $data, int $fNri): array
    {
        $pos = self::NAL_HEADER_SIZE;
        $originalNalType = ord($data[$pos]) & 0x1F;
        $firstFragment = (bool)(ord($data[$pos]) & 0x80);
        $pos++;

        $output = "";
        if ($firstFragment) {
            $originalNalHeader = chr($fNri | $originalNalType);
            $output .= chr(0) . chr(0) . chr(0) . chr(1) . $originalNalHeader;
        }
        $output .= substr($data, $pos);

        return [$firstFragment, $output];
    }

    /**
     * Handles Aggregation Packet (STAP-A)
     *
     * @param string $data Payload data
     * @return array [true, string] Always treats as complete units
     * @throws InvalidArgumentException For truncated packets
     */
    private static function handleStapA(string $data): array
    {
        $pos = self::NAL_HEADER_SIZE;
        $offsets = [];
        $output = "";

        while ($pos < strlen($data)) {
            if (strlen($data) < $pos + self::LENGTH_FIELD_SIZE) {
                throw new InvalidArgumentException("STAP-A length field is truncated");
            }

            $naluSize = unpack("n", substr($data, $pos, self::LENGTH_FIELD_SIZE))[1];
            $pos += self::LENGTH_FIELD_SIZE;
            $offsets[] = $pos;

            $pos += $naluSize;
            if (strlen($data) < $pos) {
                throw new InvalidArgumentException("STAP-A data is truncated");
            }
        }

        $offsets[] = strlen($data) + self::LENGTH_FIELD_SIZE;
        for ($i = 0; $i < count($offsets) - 1; $i++) {
            $start = $offsets[$i];
            $end = $offsets[$i + 1] - self::LENGTH_FIELD_SIZE;
            $output .= chr(0) . chr(0) . chr(0) . chr(1) . substr($data, $start, $end - $start);
        }

        return [true, $output];
    }

    /**
     * Not implemented - encoding not supported
     *
     * @throws NotImplementedException Always throws
     */
    public function encode(): string
    {
        throw new NotImplementedException("encoding not supported!");
    }
}

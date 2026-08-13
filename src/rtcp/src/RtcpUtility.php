<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTCP;

use Webrtc\RTCP\Exception\RtcpPacketException;

/**
 * RTCP Utility Functions
 *
 * Provides helper methods for RTCP packet manipulation:
 * - Packet loss encoding/decoding
 * - RTCP packet construction
 */
class RtcpUtility
{
    /**
     * Encodes packet loss count into 24-bit format
     *
     * @param int $packetsLost 24-bit signed packet loss count
     * @return string 3-byte binary representation
     */
    public static function packPacketsLost(int $packetsLost): string
    {
        return substr(pack("N", $packetsLost), 1);
    }

    /**
     * Decodes 24-bit packet loss count
     *
     * @param string $data 3-byte binary packet loss data
     * @return int Signed packet loss count
     */
    public static function unpackPacketsLost(string $data): int
    {
        $data = (ord($data[0]) & 0x80) ? "\xFF" . $data : "\x00" . $data;
        $value = unpack("N", $data)[1];

        if ($value & 0x80000000) {
            $value -= 0x100000000;
        }

        return $value;
    }

    /**
     * Constructs RTCP packet header
     *
     * @param int $packetType RTCP packet type constant
     * @param int $count Report count or subtype
     * @param string $payload Packet payload
     * @return string Complete RTCP packet
     * @throws RtcpPacketException If payload length isn't 32-bit aligned
     */
    public static function packRtcpPacket(int $packetType, int $count, string $payload): string
    {
        if (strlen($payload) % 4 !== 0) {
            throw new RtcpPacketException("Payload length must be a multiple of 4");
        }
        return pack("CCn", (2 << 6) | $count, $packetType, strlen($payload) / 4) . $payload;
    }
}
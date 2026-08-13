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
 * RTCP Packet Parser
 *
 * This class provides functionality to decode RTCP (RTP Control Protocol) packets
 * from binary data into their respective RTCP packet types. It handles the common
 * RTCP header parsing- and delegate-specific packet type parsing to specialized
 * packet classes.
 *
 * RTCP packets are used alongside RTP to provide quality feedback, participant
 * information, and control information in multimedia streaming sessions.
 */
class RtcpPacket
{

    /**
     * Decodes binary RTCP data into an array of RTCP packet objects
     *
     * This method processes compound RTCP packets (which may contain multiple
     * individual RTCP packets) and returns an array of parsed packet objects.
     *
     * @param string $data The binary RTCP packet data to decode
     * @return RtcpPacketInterface[] Array of decoded RTCP packet objects
     * @throws RtcpPacketException If the packet is malformed or invalid
     */
    public static function decode(string $data): array
    {
        $pos = 0;
        $packets = [];

        while ($pos < strlen($data)) {
            $header = self::extractHeader($data, $pos);
            $payload = self::extractPayload($data, $pos, $header['length'], $header['padding']);
            $packets[] = self::createPacket($header['packet_type'], $payload, $header['count']);
        }

        return $packets;
    }

    /**
     * Extracts and validates the RTCP header from packet data
     *
     * Parses the common RTCP header fields including version, padding,
     * packet type, and length. Validates the header structure.
     *
     * @param string $data The binary packet data
     * @param int $pos Current position in the data (updated by reference)
     * @return array Associative array containing parsed header fields:
     *               - Version: RTCP protocol version (must be 2)
     *               - padding: Whether padding is present
     *               - count: Report count or packet-specific field
     *               - packet_type: RTCP packet type identifier
     *               - length: Length of the packet in 32-bit words minus one
     * @throws RtcpPacketException If header is invalid or truncated
     */
    private static function extractHeader(string $data, int &$pos): array
    {
        if (strlen($data) < $pos + RtcpConstants::RTCP_HEADER_LENGTH) {
            throw new RtcpPacketException("RTCP packet length is less than " . RtcpConstants::RTCP_HEADER_LENGTH . " bytes");
        }

        $header = unpack("Cv_p_count/Cpacket_type/nlength", substr($data, $pos, 4));
        $pos += 4;

        $version = $header['v_p_count'] >> 6;
        if ($version != 2) {
            throw new RtcpPacketException("RTCP packet has invalid version");
        }

        return [
            'version' => $version,
            'padding' => ($header['v_p_count'] >> 5) & 1,
            'count' => $header['v_p_count'] & 0x1F,
            'packet_type' => $header['packet_type'],
            'length' => $header['length'],
        ];
    }

    /**
     * Extracts the payload from an RTCP packet
     *
     * Retrieves the payload data based on the length specified in the header
     * and handles padding if present.
     *
     * @param string $data The binary packet data
     * @param int $pos Current position in the data (updated by reference)
     * @param int $length Length field from header (in 32-bit words minus one)
     * @param int $padding Whether padding is present
     * @return string The extracted payload data (with padding removed if present)
     * @throws RtcpPacketException If payload is truncated or padding is invalid
     */
    private static function extractPayload(string $data, int &$pos, int $length, int $padding): string
    {
        $end = $pos + $length * 4;
        if (strlen($data) < $end) {
            throw new RtcpPacketException("RTCP packet is truncated");
        }

        $payload = substr($data, $pos, $end - $pos);
        $pos = $end;

        if ($padding) {
            self::validatePadding($payload);
            $payload = substr($payload, 0, -ord($payload[strlen($payload) - 1]));
        }

        return $payload;
    }

    /**
     * Validates padding in an RTCP packet
     *
     * Checks that the padding length byte is valid and doesn't exceed
     * the packet length.
     *
     * @param string $payload The packet payload including padding
     * @return void
     * @throws RtcpPacketException If padding length is invalid
     */
    private static function validatePadding(string $payload): void
    {
        if (strlen($payload) == 0 || ord($payload[strlen($payload) - 1]) == 0 || ord($payload[strlen($payload) - 1]) > strlen($payload)) {
            throw new RtcpPacketException("RTCP packet padding length is invalid");
        }
    }

    /**
     * Creates a specific RTCP packet object based on a packet type
     *
     * Factory method that delegates to specialized packet decoders based
     * on the RTCP packet type.
     *
     * @param int $packetType RTCP packet type identifier
     * @param string $payload The decoded payload data
     * @param int $count Report count or packet-specific field
     * @return object Instance of the appropriate RTCP packet type
     * @throws RtcpPacketException If a packet type is unknown
     */
    private static function createPacket(int $packetType, string $payload, int $count): object
    {
        return match ($packetType) {
            RtcpConstants::RTCP_BYE => RtcpByePacket::decode($payload, $count),
            RtcpConstants::RTCP_SDES => RtcpSdesPacket::decode($payload, $count),
            RtcpConstants::RTCP_SR => RtcpSrPacket::decode($payload, $count),
            RtcpConstants::RTCP_RR => RtcpRrPacket::decode($payload, $count),
            RtcpConstants::RTCP_RTPFB => RtcpRtpfbPacket::decode($payload, $count),
            RtcpConstants::RTCP_PSFB => RtcpPsfbPacket::decode($payload, $count),
            default => throw new RtcpPacketException("Unknown RTCP packet type: $packetType"),
        };
    }
}
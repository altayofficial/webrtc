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
 * Represents an RTCP BYE (Goodbye) packet used in RTP communication.
 *
 * This class implements the RtcpPacketInterface and provides functionality to:
 * - Construct a new BYE packet with a list of SSRCs
 * - Encode the packet into binary format for transmission
 * - Decode binary data into a packet instance
 * - Access the list of SSRCs included in the packet
 *
 * The RTCP BYE packet indicates that one or more sources are no longer active.
 */
class RtcpByePacket implements RtcpPacketInterface
{

    /**
     * Constructs a new RtcpByePacket instance.
     *
     * @param array $sources List of synchronization sources (SSRCs) to include in the packet.
     */
    public function __construct(private readonly array $sources = [])
    {
    }

    /**
     * Encodes the RTCP BYE packet into a binary string.
     *
     * This method converts the list of SSRCs into a binary payload and constructs the RTCP packet
     * header. The resulting binary string can be transmitted over the network.
     *
     * @return string The binary representation of the RTCP BYE packet.
     */
    public function encode(): string
    {
        $payload = '';
        foreach ($this->sources as $ssrc) {
            $payload .= pack('N', $ssrc); // Pack as 32-bit unsigned big-endian
        }
        return $this->packRtcpPacket(count($this->sources), $payload);
    }

    /**
     * Decodes a binary string into an RtcpByePacket instance.
     *
     * This method parses the binary data of an RTCP BYE packet, extracts the list of SSRCs,
     * and returns a new RtcpByePacket instance.
     *
     * @param string $data The binary data of the RTCP BYE packet.
     * @param int $count The number of SSRCs expected in the packet.
     * @return self A new RtcpByePacket instance.
     * @throws RtcpPacketException If the binary data is invalid or the length is incorrect.
     */
    public static function decode(string $data, int $count): self
    {
        if (strlen($data) < 4 * $count) {
            throw new RtcpPacketException("RTCP bye length is invalid");
        }

        $sources = [];
        if ($count > 0) {
            $format = 'N' . $count; // Unpack as 32-bit unsigned big-endian
            $sources = array_values(unpack($format, $data));
        }

        return new self($sources);
    }

    /**
     * Constructs the RTCP packet header and combines it with the payload.
     *
     * This helper method creates the RTCP packet header based on the provided parameters
     * and appends the payload to it.
     *
     * @param int $count The number of SSRCs or other count-specific data.
     * @param string $payload The binary payload of the RTCP packet.
     * @return string The complete RTCP packet as a binary string.
     */
    private function packRtcpPacket(int $count, string $payload): string
    {
        $version = 2; // RTCP version
        $padding = 0; // No padding
        $length = strlen($payload) / 4; // Length in 32-bit words

        // Pack header: version (2 bits), padding (1 bit), count (5 bits), packet type (8 bits), length (16 bits)
        $header = pack('CCn', ($version << 6) | ($padding << 5) | $count, RtcpConstants::RTCP_BYE, $length);

        return $header . $payload;
    }

    /**
     * @return array
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return __CLASS__;
    }
}
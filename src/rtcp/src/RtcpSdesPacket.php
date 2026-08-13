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
 * RTCP Source Description (SDES) Packet
 *
 * Provides additional information about participants in an RTP session.
 * Contains one or more source description chunks, each describing one
 * synchronization source (SSRC).
 *
 * Each chunk contains:
 * - SSRC identifier
 * - Zero or more SDES items (type-length-value format)
 * - Null terminator
 *
 * Defined in RFC 3550 section 6.5
 */
class RtcpSdesPacket implements RtcpPacketInterface
{
    /**
     * Constructs a new SDES packet
     *
     * @param RtcpSourceInfo[] $chunks Array of source description chunks
     */
    public function __construct(private readonly array $chunks = [])
    {
    }

    /**
     * Encodes SDES packet into binary format
     *
     * Constructs a packet with:
     * - RTCP header (version, padding, count, packet type, length)
     * - One or more SDES chunks (SSRC + items)
     * - Padding to 32-bit boundary
     *
     * @return string Binary RTCP SDES packet
     * @throws RtcpPacketException If encoding fails
     */
    public function encode(): string
    {
        $payload = '';
        foreach ($this->chunks as $chunk) {
            $payload .= pack('N', $chunk->getSsrc());
            foreach ($chunk->getItems() as $item) {
                $payload .= pack('CC', $item[0], strlen($item[1])) . $item[1];
            }
            $payload .= "\x00\x00"; // End of chunk
        }

        // Pad to 32-bit boundary
        while (strlen($payload) % 4 != 0) {
            $payload .= "\x00";
        }

        return RtcpUtility::packRtcpPacket(RtcpConstants::RTCP_SDES, count($this->chunks), $payload);
    }

    /**
     * Decodes binary data into SDES packet
     *
     * @param string $data Binary SDES packet data (without header)
     * @param int $count Number of chunks expected
     * @return self New RtcpSdesPacket instance
     * @throws RtcpPacketException If data is invalid or truncated
     */
    public static function decode(string $data, int $count): self
    {
        $pos = 0;
        $chunks = [];

        for ($i = 0; $i < $count; $i++) {
            if (strlen($data) < $pos + 4) {
                throw new RtcpPacketException("RTCP SDES source is truncated");
            }

            $ssrc = unpack('N', substr($data, $pos, 4))[1];
            $pos += 4;

            $items = [];
            while ($pos < strlen($data) - 1) {
                $unpacked = unpack('Ctype/Clength', substr($data, $pos, 2));
                $type = $unpacked['type'];
                $length = $unpacked['length'];
                $pos += 2;

                if (strlen($data) < $pos + $length) {
                    throw new RtcpPacketException("RTCP SDES item is truncated");
                }

                $value = substr($data, $pos, $length);
                $pos += $length;

                if ($type == 0) {
                    break; // End of chunk
                }
                $items[] = [$type, $value];
            }

            $chunks[] = new RtcpSourceInfo($ssrc, $items);
        }

        return new self($chunks);
    }

    /**
     * Get source description chunks
     *
     * @return RtcpSourceInfo[] Array of source description chunks
     */
    public function getChunks(): array
    {
        return $this->chunks;
    }

    public function __toString(): string
    {
        return __CLASS__;
    }
}
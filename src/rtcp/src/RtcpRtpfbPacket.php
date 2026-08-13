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
 * RTCP RTP Feedback Packet (RTPFB)
 *
 * Provides transport-layer feedback information about RTP packets.
 * Used for:
 * - Generic NACK (Negative ACKnowledgement)
 * - Temporary Maximum Media Stream Bitrate Request (TMMBR)
 * - Temporary Maximum Media Stream Bitrate Notification (TMMBN)
 *
 * @see https://datatracker.ietf.org/doc/html/rfc4585#section-6.2 Defined in RFC 4585 section 6.2
 */
class RtcpRtpfbPacket implements RtcpPacketInterface
{
    /**
     * Constructs a new RTP Feedback packet
     *
     * @param int $fmt Feedback message type:
     *                 - 1: Generic NACK
     *                 - 2: Reserved
     *                 - 3: TMMBR
     *                 - 4: TMMBN
     * @param int $ssrc SSRC of feedback sender
     * @param int $mediaSsrc SSRC of a media source being reported
     * @param int[] $lost Array of lost packet sequence numbers (for NACK)
     */
    public function __construct(
        private readonly int   $fmt,
        private readonly int   $ssrc,
        private readonly int   $mediaSsrc,
        private readonly array $lost = []
    ) {
    }

    /**
     * Encodes RTPFB packet into binary format
     *
     * For NACK packets, encodes lost packets using PID (Packet ID) and
     * BLP (Bitmask of Following Lost Packets) format
     *
     * @return string Binary RTCP RTPFB packet
     */
    public function encode(): string
    {
        $payload = pack('NN', $this->ssrc, $this->mediaSsrc);

        if (!empty($this->lost)) {
            $pid = $this->lost[0];
            $blp = 0;

            foreach (array_slice($this->lost, 1) as $p) {
                $d = $p - $pid - 1;
                if ($d < 16) {
                    $blp |= 1 << $d;
                } else {
                    $payload .= pack('nn', $pid, $blp);
                    $pid = $p;
                    $blp = 0;
                }
            }

            $payload .= pack('nn', $pid, $blp);
        }

        return $this->packRtcpPacket($this->fmt, $payload);
    }

    /**
     * Decodes binary data into RTPFB packet
     *
     * @param string $data Binary RTPFB packet data (without header)
     * @param int $fmt Feedback message type from header
     * @return self New RtcpRtpfbPacket instance
     * @throws RtcpPacketException If data length is invalid
     */
    public static function decode(string $data, int $fmt): self
    {
        if (strlen($data) < 8 || strlen($data) % 4 != 0) {
            throw new RtcpPacketException("RTCP RTP feedback length is invalid");
        }

        $unpacked = unpack('Nssrc/NmediaSsrc', substr($data, 0, 8));
        $ssrc = $unpacked['ssrc'];
        $mediaSsrc = $unpacked['mediaSsrc'];

        $lost = [];
        for ($pos = 8; $pos < strlen($data); $pos += 4) {
            $unpacked = unpack('npid/nblp', substr($data, $pos, 4));
            $pid = $unpacked['pid'];
            $blp = $unpacked['blp'];

            $lost[] = $pid;
            for ($d = 0; $d < 16; $d++) {
                if (($blp >> $d) & 1) {
                    $lost[] = $pid + $d + 1;
                }
            }
        }

        return new self($fmt, $ssrc, $mediaSsrc, $lost);
    }

    /**
     * Constructs RTCP packet header and combines with payload
     *
     * @param int $fmt Feedback message type
     * @param string $payload Packet payload
     * @return string Complete RTCP packet
     */
    private function packRtcpPacket(int $fmt, string $payload): string
    {
        $version = 2;
        $padding = 0;
        $length = strlen($payload) / 4;

        $header = pack('CCn', ($version << 6) | ($padding << 5) | $fmt, RtcpConstants::RTCP_RTPFB, $length);
        return $header . $payload;
    }

    /**
     * Get a feedback message type
     *
     * @return int FMT field value
     */
    public function getFmt(): int
    {
        return $this->fmt;
    }

    /**
     * Get sender SSRC
     *
     * @return int Synchronization source identifier of sender
     */
    public function getSsrc(): int
    {
        return $this->ssrc;
    }

    /**
     * Get media SSRC
     *
     * @return int SSRC of a media source being reported
     */
    public function getMediaSsrc(): int
    {
        return $this->mediaSsrc;
    }

    /**
     * Get lost packet IDs
     *
     * For NACK packets, returns array of lost sequence numbers
     *
     * @return int[] Array of lost packet sequence numbers
     */
    public function getLost(): array
    {
        return $this->lost;
    }

    public function __toString(): string
    {
        return __CLASS__;
    }
}
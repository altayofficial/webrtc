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
 * RTCP Receiver Report (RR) Packet
 *
 * Sent by participants to report reception statistics for one or more sources.
 * Contains:
 * - SSRC of reporting endpoint
 * - Zero or more receiver report blocks (RtcpReceiverInfo)
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3550#section-6.4.2 Defined in RFC 3550 section 6.4.2
 */
class RtcpRrPacket implements RtcpPacketInterface
{
    /**
     * Constructs a new Receiver Report packet
     *
     * @param int $ssrc SSRC of reporting endpoint
     * @param RtcpReceiverInfo[] $reports Array of receiver report blocks
     */
    public function __construct(private readonly int $ssrc, private readonly array $reports = [])
    {
    }

    /**
     * Encodes RR packet into binary format
     *
     * @return string Binary RTCP RR packet
     */
    public function encode(): string
    {
        $payload = pack('N', $this->ssrc);
        foreach ($this->reports as $report) {
            $payload .= $report->encode();
        }
        return $this->packRtcpPacket(count($this->reports), $payload);
    }

    /**
     * Decodes binary data into RR packet
     *
     * @param string $data Binary RR packet data (without header)
     * @param int $count Number of report blocks expected
     * @return self New RtcpRrPacket instance
     * @throws RtcpPacketException If data length doesn't match the expected size
     */
    public static function decode(string $data, int $count): self
    {
        $expectedLength = 4 + 24 * $count;
        if (strlen($data) != $expectedLength) {
            throw new RtcpPacketException("RTCP receiver report length is invalid");
        }

        $ssrc = unpack('N', substr($data, 0, 4))[1];
        $reports = [];
        $pos = 4;

        for ($i = 0; $i < $count; $i++) {
            $reportData = substr($data, $pos, 24);
            $reports[] = RtcpReceiverInfo::decode($reportData);
            $pos += 24;
        }

        return new self($ssrc, $reports);
    }

    /**
     * Constructs RTCP packet header and combines with payload
     *
     * @param int $count Number of report blocks
     * @param string $payload Packet payload (SSRC + report blocks)
     * @return string Complete RTCP packet
     */
    private function packRtcpPacket(int $count, string $payload): string
    {
        $version = 2;
        $padding = 0;
        $length = strlen($payload) / 4;

        $header = pack('CCn', ($version << 6) | ($padding << 5) | $count, RtcpConstants::RTCP_RR, $length);
        return $header . $payload;
    }

    /**
     * Get reporting endpoint SSRC
     *
     * @return int Synchronization source identifier
     */
    public function getSsrc(): int
    {
        return $this->ssrc;
    }

    /**
     * Get receiver report blocks
     *
     * @return RtcpReceiverInfo[] Array of report blocks
     */
    public function getReports(): array
    {
        return $this->reports;
    }

    public function __toString(): string
    {
        return __CLASS__;
    }
}
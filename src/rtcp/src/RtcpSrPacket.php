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
 * RTCP Sender Report (SR) Packet
 *
 * Sent by active senders to report transmission and reception statistics.
 * Contains:
 * - SSRC of reporting endpoint
 * - Sender information block
 * - Zero or more receiver report blocks
 *
 * Defined in RFC 3550 section 6.4.1
 */
class RtcpSrPacket implements RtcpPacketInterface
{
    /**
     * Constructs new Sender Report packet
     *
     * @param int $ssrc SSRC of reporting endpoint
     * @param RtcpSenderInfo $senderInfo Transmission statistics
     * @param RtcpReceiverInfo[] $reports Reception statistics
     */
    public function __construct(
        private readonly int            $ssrc,
        private readonly RtcpSenderInfo $senderInfo,
        private readonly array          $reports = []
    ) {
    }

    /**
     * Encodes SR packet into binary format
     *
     * @return string Binary RTCP SR packet
     */
    public function encode(): string
    {
        $payload = pack('N', $this->ssrc);
        $payload .= $this->senderInfo->encode();
        foreach ($this->reports as $report) {
            $payload .= $report->encode();
        }
        return $this->packRtcpPacket(count($this->reports), $payload);
    }

    /**
     * Decodes binary data into SR packet
     *
     * @param string $data Binary SR packet data (without header)
     * @param int $count Number of report blocks expected
     * @return self New RtcpSrPacket instance
     * @throws RtcpPacketException If data length is invalid
     */
    public static function decode(string $data, int $count): self
    {
        $expectedLength = 24 + 24 * $count;
        if (strlen($data) != $expectedLength) {
            throw new RtcpPacketException("RTCP sender report length is invalid");
        }

        $ssrc = unpack('N', substr($data, 0, 4))[1];
        $senderInfo = RtcpSenderInfo::decode(substr($data, 4, 20));
        $reports = [];
        $pos = 24;

        for ($i = 0; $i < $count; $i++) {
            $reportData = substr($data, $pos, 24);
            $reports[] = RtcpReceiverInfo::decode($reportData);
            $pos += 24;
        }

        return new self($ssrc, $senderInfo, $reports);
    }

    /**
     * Constructs RTCP packet header and combines with payload
     *
     * @param int $count Number of report blocks
     * @param string $payload Packet payload
     * @return string Complete RTCP packet
     */
    private function packRtcpPacket(int $count, string $payload): string
    {
        $version = 2;
        $padding = 0;
        $length = strlen($payload) / 4;

        $header = pack('CCn', ($version << 6) | ($padding << 5) | $count, RtcpConstants::RTCP_SR, $length);
        return $header . $payload;
    }

    /**
     * Get sender SSRC
     *
     * @return int Synchronization source identifier
     */
    public function getSsrc(): int
    {
        return $this->ssrc;
    }

    /**
     * Get sender information
     *
     * @return RtcpSenderInfo Transmission statistics
     */
    public function getSenderInfo(): RtcpSenderInfo
    {
        return $this->senderInfo;
    }

    /**
     * Get receiver reports
     *
     * @return RtcpReceiverInfo[] Reception statistics
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
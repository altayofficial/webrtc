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
 * RTCP Payload-Specific Feedback Packet (PSFB)
 *
 * Represents an RTCP Payload-Specific Feedback packet as defined in RFC 4585.
 * These packets provide feedback specific to a particular payload format,
 * such as picture loss indications or slice loss indications in video streams.
 *
 * Common PSFB message types include:
 * - PLI (Picture Loss Indication)
 * - SLI (Slice Loss Indication)
 * - RPSI (Reference Picture Selection Indication)
 * - FIR (Full Intra Request)
 */
class RtcpPsfbPacket implements RtcpPacketInterface
{
    /**
     * Constructs a new RtcpPsfbPacket instance.
     *
     * @param int $fmt Feedback message type (FMT) - specific to payload format
     * @param int $ssrc The synchronization source identifier of the feedback sender
     * @param int $mediaSsrc The synchronization source identifier of the media source
     * @param string $fci Feedback control information (format depends on FMT)
     */
    public function __construct(
        private readonly int    $fmt,
        private readonly int    $ssrc,
        private readonly int    $mediaSsrc,
        private readonly string $fci = ''
    )
    {
    }

    /**
     * Encodes the PSFB packet into binary format
     *
     * Constructs the complete RTCP packet including header and payload:
     * - 4-byte header (version, padding, count, packet type, length)
     * - 8-byte sender and media SSRC fields
     * - Variable length FCI data
     *
     * @return string Binary representation of the PSFB packet
     */
    public function encode(): string
    {
        $payload = pack('NN', $this->ssrc, $this->mediaSsrc) . $this->fci;
        return $this->packRtcpPacket($this->fmt, $payload);
    }

    /**
     * Decodes binary data into an RtcpPsfbPacket instance
     *
     * @param string $data Binary PSFB packet data (without header)
     * @param int $fmt Feedback message type from header
     * @return self New RtcpPsfbPacket instance
     * @throws RtcpPacketException If data is too short (less than 8 bytes)
     */
    public static function decode(string $data, int $fmt): self
    {
        if (strlen($data) < 8) {
            throw new RtcpPacketException("RTCP payload-specific feedback length is invalid");
        }

        $unpacked = unpack('Nssrc/NmediaSsrc', $data);
        $ssrc = $unpacked['ssrc'];
        $mediaSsrc = $unpacked['mediaSsrc'];
        $fci = substr($data, 8);

        return new self($fmt, $ssrc, $mediaSsrc, $fci);
    }

    /**
     * Constructs RTCP packet header and combines with payload
     *
     * @param int $fmt Feedback message type
     * @param string $payload Packet payload (SSRCs + FCI)
     * @return string Complete RTCP packet
     */
    private function packRtcpPacket(int $fmt, string $payload): string
    {
        $version = 2; // RTCP version
        $padding = 0; // No padding
        $length = strlen($payload) / 4; // Length in 32-bit words

        $header = pack('CCn', ($version << 6) | ($padding << 5) | $fmt, RtcpConstants::RTCP_PSFB, $length);
        return $header . $payload;
    }

    /**
     * Get a feedback message type (FMT)
     *
     * @return int Feedback message type identifier
     */
    public function getFmt(): int
    {
        return $this->fmt;
    }

    /**
     * Get sender SSRC
     *
     * @return int Synchronization source identifier of feedback sender
     */
    public function getSsrc(): int
    {
        return $this->ssrc;
    }

    /**
     * Get media SSRC
     *
     * @return int Synchronization source identifier of a media source
     */
    public function getMediaSsrc(): int
    {
        return $this->mediaSsrc;
    }

    /**
     * Get feedback control information
     *
     * @return string Format-specific feedback data
     */
    public function getFci(): string
    {
        return $this->fci;
    }

    public function __toString(): string
    {
        return __CLASS__;
    }
}
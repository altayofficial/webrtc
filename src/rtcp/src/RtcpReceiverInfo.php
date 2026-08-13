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
 * RTCP Receiver Report Block
 *
 * Represents a single receiver report block within an RTCP Receiver Report (RR) packet.
 * Contains statistics about reception from a single synchronization source.
 *
 * Each block contains:
 * - Fraction of packets lost
 * - Cumulative number of packets lost
 * - Highest sequence number received
 * - Interarrival jitter
 * - Last SR timestamp (LSR)
 * - Delay since last SR (DLSR)
 */
class RtcpReceiverInfo implements RtcpPacketInterface
{
    /**
     * Constructs a new receiver report block
     *
     * @param int $ssrc The SSRC identifier of the source
     * @param int $fractionLost Fraction of packets lost (8-bit fixed point, 0-255)
     * @param int $packetsLost Cumulative number of packets lost (24-bit signed)
     * @param int $highestSequence Extended highest sequence number received
     * @param int $jitter Interarrival jitter estimate (32-bit unsigned)
     * @param int $lsr Last SR timestamp (middle 32 bits of NTP timestamp)
     * @param int $dlsr Delay since last SR (1/65536 seconds)
     */
    public function __construct(
        private readonly int $ssrc,
        private readonly int $fractionLost,
        private readonly int $packetsLost,
        private readonly int $highestSequence,
        private readonly int $jitter,
        private readonly int $lsr,
        private readonly int $dlsr
    )
    {
    }

    /**
     * Encodes receiver report block into binary format
     *
     * @return string 24-byte binary report block
     */
    public function encode(): string
    {
        $data = pack('NC', $this->ssrc, $this->fractionLost);
        $data .= RtcpUtility::packPacketsLost($this->packetsLost);
        $data .= pack('NNNN', $this->highestSequence, $this->jitter, $this->lsr, $this->dlsr);
        return $data;
    }

    /**
     * Decodes binary data into receiver report block
     *
     * @param string $data 24-byte report block data
     * @param int|null $count Unused parameter (for interface compatibility)
     * @return self New RtcpReceiverInfo instance
     * @throws RtcpPacketException If data length is not exactly 24 bytes
     */
    public static function decode(string $data, ?int $count = null): self
    {
        if (strlen($data) != 24) {
            throw new RtcpPacketException("Receiver report block length is invalid");
        }

        $unpacked = unpack('Nssrc/CfractionLost', $data);
        $ssrc = $unpacked['ssrc'];
        $fractionLost = $unpacked['fractionLost'];

        $packetsLost = RtcpUtility::unpackPacketsLost(substr($data, 5, 3));

        $unpacked = unpack('NhighestSequence/Njitter/Nlsr/Ndlsr', substr($data, 8));
        $highestSequence = $unpacked['highestSequence'];
        $jitter = $unpacked['jitter'];
        $lsr = $unpacked['lsr'];
        $dlsr = $unpacked['dlsr'];

        return new self($ssrc, $fractionLost, $packetsLost, $highestSequence, $jitter, $lsr, $dlsr);
    }

    /**
     * Get delay since last SR (DLSR)
     *
     * @return int Delay in 1/65536 second units
     */
    public function getDlsr(): int
    {
        return $this->dlsr;
    }

    /**
     * Get a fraction of packets lost
     *
     * @return int 8-bit fixed point number (0-255)
     */
    public function getFractionLost(): int
    {
        return $this->fractionLost;
    }

    /**
     * Get cumulative packets lost
     *
     * @return int 24-bit signed integer
     */
    public function getPacketsLost(): int
    {
        return $this->packetsLost;
    }

    /**
     * Get the highest sequence number received
     *
     * @return int Extended highest sequence number
     */
    public function getHighestSequence(): int
    {
        return $this->highestSequence;
    }

    /**
     * Get interarrival jitter estimate
     *
     * @return int Jitter in timestamp units
     */
    public function getJitter(): int
    {
        return $this->jitter;
    }

    /**
     * Get last SR timestamp (LSR)
     *
     * @return int Middle 32 bits of last SR's NTP timestamp
     */
    public function getLsr(): int
    {
        return $this->lsr;
    }

    /**
     * Get source SSRC
     *
     * @return int Synchronization source identifier
     */
    public function getSsrc(): int
    {
        return $this->ssrc;
    }

    public function __toString(): string
    {
        return __CLASS__;
    }
}
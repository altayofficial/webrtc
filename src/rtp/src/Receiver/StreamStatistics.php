<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP\Receiver;

use Webrtc\RTP\RtpPacket;

/**
 * StreamStatistics collects and calculates metrics from incoming RTP packets,
 * including packet loss, jitter, and sequence number tracking.
 *
 * This class supports analysis of RTP stream quality over time by keeping track
 * of received packets, estimating jitter based on RFC 3550, and handling 16-bit
 * sequence number wrap-around. It's useful for generating RTCP reports or debugging.
 */
class StreamStatistics
{
    private ?int $baseSeq = null;
    private ?int $maxSeq = null;
    private int $cycles = 0;
    private int $packetsReceived = 0;

    // Jitter variables
    private int $clockRate;
    private int $jitterQ4 = 0;
    private ?int $lastArrival = null;
    private ?int $lastTimestamp = null;

    // Fraction lost tracking
    private int $expectedPrior = 0;
    private int $receivedPrior = 0;

    /**
     * StreamStatistics constructor.
     *
     * @param int $clockRate The clock rate of the RTP stream.
     */
    public function __construct(int $clockRate)
    {
        $this->clockRate = $clockRate;
    }

    /**
     * Adds an RTP packet to the statistics tracker.
     *
     * @param RtpPacket $packet The received RTP packet.
     */
    public function add(RtpPacket $packet): void
    {
        $inOrder = $this->maxSeq === null || $this->uint16Gt($packet->getSequenceNumber(), $this->maxSeq);
        $this->packetsReceived++;

        if ($this->baseSeq === null) {
            $this->baseSeq = $packet->getSequenceNumber();
        }

        if ($inOrder) {
            $arrival = intval(microtime(true) * $this->clockRate);

            if ($this->maxSeq !== null && $packet->getSequenceNumber() < $this->maxSeq) {
                $this->cycles += (1 << 16);
            }
            $this->maxSeq = $packet->getSequenceNumber();

            // Jitter calculation
            if ($packet->getTimestamp() !== $this->lastTimestamp && $this->packetsReceived > 1) {
                $diff = abs(
                    ($arrival - $this->lastArrival) - ($packet->getTimestamp() - $this->lastTimestamp)
                );
                $this->jitterQ4 += $diff - (($this->jitterQ4 + 8) >> 4);
            }

            $this->lastArrival = $arrival;
            $this->lastTimestamp = $packet->getTimestamp();
        }
    }

    /**
     * Calculates the fraction of lost RTP packets.
     *
     * @return int The fraction lost as a percentage (0-255).
     */
    public function getFractionLost(): int
    {
        $expectedInterval = $this->getPacketsExpected() - $this->expectedPrior;
        $this->expectedPrior = $this->getPacketsExpected();
        $receivedInterval = $this->packetsReceived - $this->receivedPrior;
        $this->receivedPrior = $this->packetsReceived;
        $lostInterval = $expectedInterval - $receivedInterval;

        if ($expectedInterval === 0 || $lostInterval <= 0) {
            return 0;
        }
        return intval(($lostInterval << 8) / $expectedInterval);
    }

    /**
     * Gets the current jitter value.
     *
     * @return int The estimated jitter value.
     */
    public function getJitter(): int
    {
        return $this->jitterQ4 >> 4;
    }

    /**
     * Gets the total number of packets expected based on sequence numbers.
     *
     * @return int The number of expected packets.
     */
    public function getPacketsExpected(): int
    {
        if ($this->baseSeq === null || $this->maxSeq === null) {
            return 0;
        }
        return $this->cycles + $this->maxSeq - $this->baseSeq + 1;
    }

    /**
     * Gets the total number of lost packets.
     *
     * @return int The number of lost packets.
     */
    public function getPacketsLost(): int
    {
        return $this->clampPacketsLost($this->getPacketsExpected() - $this->packetsReceived);
    }

    /**
     * Checks if a 16-bit sequence number is greater than another, considering wrap-around.
     *
     * @param int $a First sequence number.
     * @param int $b Second sequence number.
     * @return bool True if $a > $b, considering 16-bit overflow.
     */
    private function uint16Gt(int $a, int $b): bool
    {
        $halfMod = 0x8000;
        return (($a < $b) && (($b - $a) > $halfMod)) || (($a > $b) && (($a - $b) < $halfMod));
    }

    /**
     * Ensures the packet loss count does not go negative.
     *
     * @param int $packetsLost The computed number of lost packets.
     * @return int Clamped value ensuring non-negative loss count.
     */
    private function clampPacketsLost(int $packetsLost): int
    {
        return max(0, $packetsLost);
    }

    /**
     * @return int
     */
    public function getPacketsReceived(): int
    {
        return $this->packetsReceived;
    }

    /**
     * @return int|null
     */
    public function getMaxSeq(): ?int
    {
        return $this->maxSeq;
    }

    /**
     * @return int
     */
    public function getJitterQ4(): int
    {
        return $this->jitterQ4;
    }
}

<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP\Receiver\Rate;

/**
 * Class InterArrival
 *
 * Tracks and calculates inter-arrival deltas between RTP timestamp groups for bandwidth estimation.
 * This class groups RTP packets based on their timestamps and arrival times, detecting bursts and
 * computing deltas only when appropriate new timestamp groups are formed.
 *
 * Key responsibilities:
 * - Form timestamp groups for incoming packets.
 * - Identify burst packets that belong to the same group.
 * - Compute deltas in timestamp, arrival time, and packet size between successive groups.
 * - Handle 32-bit unsigned integer wrap-around safely.
 *
 * This is a core part of WebRTC bandwidth estimation used in delay-based bitrate control.
 */

class InterArrival
{
    /**
     * @var int
     */
    private const BURST_DELTA_THRESHOLD_MS = 5;
    private int $groupLength;
    private float $timestampToMs;
    private ?TimestampGroup $currentGroup = null;
    private ?TimestampGroup $previousGroup = null;

    public function __construct(int $groupLength, float $timestampToMs)
    {
        $this->groupLength = $groupLength;
        $this->timestampToMs = $timestampToMs;
    }

    /**
     * Computes the inter-arrival deltas between timestamp groups.
     * @param int $timestamp The current packet timestamp.
     * @param int $arrivalTime The arrival time of the packet.
     * @param int $packetSize The size of the packet.
     * @return InterArrivalDelta|null The computed deltas or null if not applicable.
     */
    public function computeDeltas(int $timestamp, int $arrivalTime, int $packetSize): ?InterArrivalDelta
    {
        $deltas = null;
        if ($this->currentGroup === null) {
            $this->currentGroup = new TimestampGroup($timestamp);
        } elseif ($this->packetOutOfOrder($timestamp)) {
            return null;
        } elseif ($this->newTimestampGroup($timestamp, $arrivalTime)) {
            if ($this->previousGroup !== null) {
                $deltas = new InterArrivalDelta(
                    $this->uint32Add($this->currentGroup->lastTimestamp, -$this->previousGroup->lastTimestamp),
                    $this->currentGroup->arrivalTime - $this->previousGroup->arrivalTime,
                    $this->currentGroup->size - $this->previousGroup->size
                );
            }

            $this->previousGroup = $this->currentGroup;
            $this->currentGroup = new TimestampGroup($timestamp);
        } elseif ($this->uint32Gt($timestamp, $this->currentGroup->lastTimestamp)) {
            $this->currentGroup->lastTimestamp = $timestamp;
        }

        $this->currentGroup->size += $packetSize;
        $this->currentGroup->arrivalTime = $arrivalTime;

        return $deltas;
    }

    /**
     * Determines if a packet belongs to a burst-based-on-timestamp and arrival time deltas.
     * @param int $timestamp The timestamp of the packet.
     * @param int $arrivalTime The arrival time of the packet.
     * @return bool True if the packet belongs to a burst, false otherwise.
     */
    private function belongsToBurst(int $timestamp, int $arrivalTime): bool
    {
        $timestampDelta = $this->uint32Add($timestamp, -$this->currentGroup->lastTimestamp);
        $timestampDeltaMs = round($this->timestampToMs * $timestampDelta);
        $arrivalTimeDelta = $arrivalTime - $this->currentGroup->arrivalTime;
        return $timestampDeltaMs == 0 ||
            (($arrivalTimeDelta - $timestampDeltaMs) < 0 && $arrivalTimeDelta <= self::BURST_DELTA_THRESHOLD_MS);
    }

    /**
     * Determines if a new timestamp group should be created.
     * @param int $timestamp The current packet timestamp.
     * @param int $arrivalTime The arrival time of the packet.
     * @return bool True if a new timestamp group should be created, false otherwise.
     */
    private function newTimestampGroup(int $timestamp, int $arrivalTime): bool
    {
        if ($this->belongsToBurst($timestamp, $arrivalTime)) {
            return false;
        }
        $timestampDelta = $this->uint32Add($timestamp, -$this->currentGroup->firstTimestamp);
        return $timestampDelta > $this->groupLength;
    }

    /**
     * Checks if a packet is out of order.
     * @param int $timestamp The timestamp of the packet.
     * @return bool True if the packet is out of order, false otherwise.
     */
    private function packetOutOfOrder(int $timestamp): bool
    {
        $timestampDelta = $this->uint32Add($timestamp, -$this->currentGroup->firstTimestamp);
        return $timestampDelta >= 0x80000000;
    }

    /**
     * Performs an unsigned 32-bit addition.
     * @param int $a First operand.
     * @param int $b Second operand.
     * @return int The result of the addition.
     */
    private function uint32Add(int $a, int $b): int
    {
        return ($a + $b) & 0xFFFFFFFF;
    }

    /**
     * Compares two unsigned 32-bit integers to check if one is greater than the other.
     * @param int $a First operand.
     * @param int $b Second operand.
     * @return bool True if $a is greater than $b, false otherwise.
     */
    function uint32Gt(int $a, int $b): bool
    {
        $halfMod = 0x80000000;
        return (($a < $b) && (($b - $a) > $halfMod)) || (($a > $b) && (($a - $b) < $halfMod));
    }
}

<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP\Jitter;

use Webrtc\Exception\InvalidArgumentException;
use Webrtc\RTP\RtpPacket;

/**
 * JitterBuffer buffers incoming RTP packets and reconstructs complete frames,
 * handling misordering and jitter in the packet stream. It is designed to be
 * used for both audio and video streams.
 *
 * The buffer has a fixed capacity and supports prefetching of frames to allow
 * smoother playout. Misordered packets beyond a defined threshold trigger a
 * buffer reset and (for video) may prompt a Picture Loss Indication (PLI).
 */
class JitterBuffer
{
    /**
     * Maximum sequence number misordering tolerated before the buffer resets.
     * @var int
     */
    private const MAX_MISORDER = 100;

    private ?int $origin = null;

    /** @var array<int, RtpPacket|null> Ring buffer of RTP packets. */
    private array $packets = [];

    /**
     * JitterBuffer constructor.
     *
     * @param int $capacity Size of the buffer; must be a power of 2.
     * @param int $prefetch Number of complete frames to prefetch before outputting.
     * @param bool $isVideo Whether the buffer handles video (affects PLI triggering).
     *
     * @throws InvalidArgumentException If capacity is not a power of 2.
     */
    public function __construct(private readonly int $capacity, private readonly int $prefetch = 0, private readonly bool $isVideo = false)
    {
        if (($capacity & ($capacity - 1)) !== 0) {
            throw new InvalidArgumentException("Capacity must be a power of 2.");
        }

        $this->packets = array_fill(0, $capacity, null);
    }

    /**
     * Get the buffer capacity.
     *
     * @return int Buffer capacity.
     */
    public function getCapacity(): int
    {
        return $this->capacity;
    }

    /**
     * Add an RTP packet to the jitter buffer.
     *
     * Handles misordering, buffer overflows, and triggers PLI when necessary.
     * Returns a completed frame if a prefetch threshold is met.
     *
     * @param RtpPacket $packet The incoming RTP packet.
     * @return array{bool, JitterFrame|null} Tuple of (PLI flag, completed frame).
     */
    public function add(RtpPacket $packet): array
    {
        $pliFlag = false;
        $seqNum = $packet->getSequenceNumber();

        if ($this->origin === null) {
            $this->origin = $seqNum;
            $delta = $misorder = 0;
        } else {
            $delta = $this->uint16Add($seqNum, -$this->origin);
            $misorder = $this->uint16Add($this->origin, -$seqNum);
        }

        if ($misorder < $delta) {
            if ($misorder >= self::MAX_MISORDER) {
                $this->clearBuffer($seqNum);
                if ($this->isVideo) {
                    $pliFlag = true;
                }
            } else {
                return [$pliFlag, null];
            }
        }

        if ($delta >= $this->capacity) {
            if ($this->smartRemove($delta - $this->capacity + 1)) {
                $this->origin = $packet->getSequenceNumber();
            }

            if ($this->isVideo) {
                $pliFlag = true;
            }
        }

        $this->packets[$seqNum % $this->capacity] = $packet;
        return [$pliFlag, $this->extractFrame()];
    }

    /**
     * Extracts and removes a completed frame from the buffer if available.
     *
     * Waits until enough frames are prefetched before returning a frame.
     *
     * @return JitterFrame|null Completed frame if available.
     */
    private function extractFrame(): ?JitterFrame
    {
        $packets = [];
        $frame = null;
        $framesCount = 0;
        $removeCount = 0;
        $timestamp = null;

        for ($count = 0; $count < $this->capacity; $count++) {
            $pos = ($this->origin + $count) % $this->capacity;
            $packet = $this->packets[$pos] ?? null;

            if ($packet === null) {
                break;
            }

            if ($timestamp === null) {
                $timestamp = $packet->getTimestamp();
            } elseif ($packet->getTimestamp() !== $timestamp) {
                if ($frame === null) {
                    $frame = new JitterFrame(
                        implode("", array_map(fn(RtpPacket $p) => $p->getDecodedData(), $packets)),
                        $timestamp
                    );
                    $removeCount = $count;
                }

                $framesCount++;
                if ($framesCount >= $this->prefetch) {
                    $this->remove($removeCount);
                    return $frame;
                }

                $packets = [];
                $timestamp = $packet->getTimestamp();
            }

            $packets[] = $packet;
        }

        return null;
    }

    /**
     * Removes a specific number of packets from the buffer.
     *
     * @param int $count Number of packets to remove.
     *
     * @throws InvalidArgumentException If count exceeds buffer capacity.
     */
    public function remove(int $count): void
    {
        if ($count > $this->capacity) {
            throw new InvalidArgumentException("Count cannot exceed buffer capacity.");
        }

        for ($i = 0; $i < $count; $i++) {
            $pos = $this->origin % $this->capacity;
            $this->packets[$pos] = null;
            $this->origin = $this->uint16Add($this->origin, 1);
        }
    }

    /**
     * Attempts to remove old packets while preserving complete frames.
     *
     * Removes up to a given count but keeps timestamp continuity.
     *
     * @param int $count Number of packets to remove.
     * @return bool True if the entire buffer was cleared.
     */
    public function smartRemove(int $count): bool
    {
        $timestamp = null;

        for ($i = 0; $i < $this->capacity; $i++) {
            $pos = $this->origin % $this->capacity;
            $packet = $this->packets[$pos] ?? null;

            if ($packet !== null) {
                if ($i >= $count && $timestamp !== $packet->getTimestamp()) {
                    break;
                }
                $timestamp = $packet->getTimestamp();
            }

            $this->packets[$pos] = null;
            $this->origin = $this->uint16Add($this->origin, 1);

            if ($i === $this->capacity - 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clears the entire buffer and resets the origin to a new sequence number.
     *
     * @param int $newOrigin New origin sequence number.
     */
    private function clearBuffer(int $newOrigin): void
    {
        $this->packets = array_fill(0, $this->capacity, null);
        $this->origin = $newOrigin;
    }

    /**
     * Adds two 16-bit sequence numbers, wrapping around as needed.
     *
     * @param int $a Base value.
     * @param int $b Value to add (maybe negative).
     * @return int Result wrapped within the 16-bit unsigned range.
     */
    private function uint16Add(int $a, int $b): int
    {
        return ($a + $b) & 0xFFFF;
    }

    /**
     * Get the current sequence number origin of the buffer.
     *
     * @return int|null Origin sequence number or null if unset.
     */
    public function getOrigin(): ?int
    {
        return $this->origin;
    }

    /**
     * Get all packets currently in the jitter buffer.
     *
     * @return array<int, RtpPacket|null> Array of packets indexed by position.
     */
    public function getPackets(): array
    {
        return $this->packets;
    }
}

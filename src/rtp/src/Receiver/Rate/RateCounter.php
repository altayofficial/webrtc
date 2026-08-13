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
 * Rate counter, which stores the amount received in 1 ms buckets.
 */
class RateCounter
{
    private int $originIndex = 0;
    private ?int $originMs = null;
    private int $scale;
    private int $windowSize;
    private array $buckets;
    private RateBucket $total;

    /**
     * Constructor to initialize the RateCounter with a window size and an optional scale factor.
     *
     * @param int $windowSize The size of the window in milliseconds (number of buckets).
     * @param int $scale The scale factor for the rate calculation (default is 8000).
     */
    public function __construct(int $windowSize, int $scale = 8000)
    {
        $this->scale = $scale;
        $this->windowSize = $windowSize;
        $this->reset();
    }

    /**
     * Adds a value to the rate counter, updating the respective bucket and total count.
     * If the window size is exceeded, old data is erased.
     *
     * @param int $value The value to be added to the rate counter.
     * @param int $nowMs The current time in milliseconds.
     */
    public function add(int $value, int $nowMs): void
    {
        if ($this->originMs === null) {
            $this->originMs = $nowMs;
        } else {
            $this->eraseOld($nowMs);
        }

        // Ensure positive index calculation
        $index = ($this->originIndex + $nowMs - $this->originMs) % $this->windowSize;
        if ($index < 0) {
            $index += $this->windowSize;
        }

        // Ensure bucket exists
        if (!isset($this->buckets[$index])) {
            $this->buckets[$index] = new RateBucket();
        }

        $this->buckets[$index]->count++;
        $this->buckets[$index]->value += $value;
        $this->total->count++;
        $this->total->value += $value;
    }

    /**
     * Calculates the current rate based on the time window and total values.
     * The rate is scaled and rounded.
     *
     * @param int $nowMs The current time in milliseconds.
     * @return int|null The calculated rate or null if no data is available.
     */
    public function rate(int $nowMs): ?int
    {
        if ($this->originMs !== null) {
            $this->eraseOld($nowMs);
            $activeWindowSize = $nowMs - $this->originMs + 1;

            if ($this->total->count > 0 && $activeWindowSize > 1) {
                return (int) round(($this->scale * $this->total->value) / $activeWindowSize);
            }
        }
        return null;
    }

    /**
     * Resets the rate counter, clearing all buckets and resetting the total count.
     */
    public function reset(): void
    {
        $this->buckets = [];
        for ($i = 0; $i < $this->windowSize; $i++) {
            $this->buckets[$i] = new RateBucket();  // Correct way to initialize separate instances
        }
        $this->originIndex = 0;
        $this->originMs = null;
        $this->total = new RateBucket();
    }

    /**
     * Erases old data from the rate counter, removing values that fall outside the window size.
     * This adjusts the total count and shifts the window of data.
     *
     * @param int $nowMs The current time in milliseconds.
     */
    private function eraseOld(int $nowMs): void
    {
        $newOriginMs = $nowMs - $this->windowSize + 1;
        while ($this->originMs < $newOriginMs) {
            $bucket = $this->buckets[$this->originIndex];
            $this->total->count -= $bucket->count;
            $this->total->value -= $bucket->value;
            $bucket->count = 0;
            $bucket->value = 0;

            $this->originIndex = ($this->originIndex + 1) % $this->windowSize;
            $this->originMs++;
        }
    }

    /**
     * @return array
     */
    public function getBuckets(): array
    {
        return $this->buckets;
    }

    /**
     * @return int|null
     */
    public function getOriginMs(): ?int
    {
        return $this->originMs;
    }

    /**
     * @return int
     */
    public function getOriginIndex(): int
    {
        return $this->originIndex;
    }

    /**
     * @return RateBucket
     */
    public function getTotal(): RateBucket
    {
        return $this->total;
    }
}

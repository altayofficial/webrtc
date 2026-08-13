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

use Webrtc\RTP\Enum\BandwidthUsage;

/**
 * Bandwidth overuse detector.
 * Adapted from the webrtc.org codebase.
 */
class OveruseDetector {
    /**
     * @var int
     */
    const MAX_ADAPT_OFFSET_MS = 15;
    /**
     * @var int
     */
    const MIN_NUM_DELTAS = 60;
    private BandwidthUsage $hypothesis = BandwidthUsage::NORMAL;
    private ?int $lastUpdateMs = null;
    private float $kUp = 0.0087;
    private float $kDown = 0.039;
    private int $overuseCounter = 0;
    private ?float $overuseTime = null;
    private float $overuseTimeThreshold = 10.0;
    private float $previousOffset = 0.0;
    private float $threshold = 12.5;

    /**
     * Detects bandwidth overuse based on network conditions.
     *
     * @param float $offset The current bandwidth offset.
     * @param float $timestampDeltaMs The time difference in milliseconds.
     * @param int $numOfDeltas The number of deltas available.
     * @param int $nowMs The current timestamp in milliseconds.
     * @return BandwidthUsage The detected bandwidth usage state.
     */
    public function detect(float $offset, float $timestampDeltaMs, int $numOfDeltas, int $nowMs): BandwidthUsage {
        if ($numOfDeltas < 2) {
            return BandwidthUsage::NORMAL;
        }

        $T = min($numOfDeltas, self::MIN_NUM_DELTAS) * $offset;

        if ($T > $this->threshold) {
            if ($this->overuseTime === null) {
                $this->overuseTime = $timestampDeltaMs / 2;
            } else {
                $this->overuseTime += $timestampDeltaMs;
            }

            $this->overuseCounter++;

            if ($this->overuseTime > $this->overuseTimeThreshold && $this->overuseCounter > 1 && $offset >= $this->previousOffset) {
                $this->overuseCounter = 0;
                $this->overuseTime = 0;
                $this->hypothesis = BandwidthUsage::OVERUSING;
            }
        } elseif ($T < -$this->threshold) {
            $this->overuseCounter = 0;
            $this->overuseTime = null;
            $this->hypothesis = BandwidthUsage::UNDERUSING;
        } else {
            $this->overuseCounter = 0;
            $this->overuseTime = null;
            $this->hypothesis = BandwidthUsage::NORMAL;
        }

        $this->previousOffset = $offset;
        $this->updateThreshold($T, $nowMs);

        return $this->hypothesis;
    }

    /**
     * Returns the current state of bandwidth usage.
     *
     * @return BandwidthUsage The current bandwidth usage state.
     */
    public function state(): BandwidthUsage {
        return $this->hypothesis;
    }

    /**
     * Updates the overuse detection threshold based on network conditions.
     *
     * @param float $modifiedOffset The adjusted offset value.
     * @param int $nowMs The current timestamp in milliseconds.
     */
    private function updateThreshold(float $modifiedOffset, int $nowMs): void {
        if ($this->lastUpdateMs === null) {
            $this->lastUpdateMs = $nowMs;
        }

        if (abs($modifiedOffset) > $this->threshold + self::MAX_ADAPT_OFFSET_MS) {
            $this->lastUpdateMs = $nowMs;
            return;
        }

        $k = abs($modifiedOffset) < $this->threshold ? $this->kDown : $this->kUp;
        $timeDeltaMs = min($nowMs - $this->lastUpdateMs, 100);
        $this->threshold += $k * (abs($modifiedOffset) - $this->threshold) * $timeDeltaMs;
        $this->threshold = max(6, min($this->threshold, 600));
        $this->lastUpdateMs = $nowMs;
    }
}


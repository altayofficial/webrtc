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
use Webrtc\RTP\Enum\RateControlState;

/**
 * Class AimdRateControl
 *
 * Implements Additive-Increase/Multiplicative-Decrease (AIMD) bitrate control for RTP streams.
 * This algorithm dynamically adjusts sending bitrate based on estimated bandwidth usage to
 * optimize media delivery quality while avoiding congestion.
 *
 * The controller starts in a HOLD state and adapts between INCREASE and DECREASE states
 * based on feedback from a bandwidth estimator. It prevents rapid changes and adapts to
 * network conditions using statistical filtering and conservative rate bounding.
 *
 * Properties include:
 * - Initialization of bitrate estimates.
 * - State transitions depending on bandwidth usage (underuse, normal, overuse).
 * - Multiplicative increase and additive increase options based on proximity to maximum throughput.
 * - Exponential averaging of estimated maximum throughput and its variance.
 */
class AimdRateControl
{
    private ?float $avgMaxBitrateKbps = null;
    private float $varMaxBitrateKbps = 0.4;
    private int $currentBitrate = 30000000;
    private bool $currentBitrateInitialized = false;
    private ?int $firstEstimatedThroughputTime = null;
    private ?int $lastChangeMs = null;
    private bool $nearMax = false;
    private int $latestEstimatedThroughput = 30000000;
    private int $rtt = 200;
    private RateControlState $state = RateControlState::HOLD;

    /**
     * Returns the feedback interval in milliseconds.
     *
     * @return int Milliseconds between bitrate feedback updates.
     */
    public function feedbackInterval(): int
    {
        return 500;
    }

    /**
     * Sets the initial bitrate estimate and marks the controller as initialized.
     *
     * @param int $bitrate Initial bitrate in bits per second.
     * @param int $nowMs Current time in milliseconds.
     */
    public function setEstimate(int $bitrate, int $nowMs): void
    {
        $this->currentBitrate = $this->clampBitrate($bitrate, $bitrate);
        $this->currentBitrateInitialized = true;
        $this->lastChangeMs = $nowMs;
    }

    /**
     * Updates the controller state and bitrate estimate based on observed bandwidth usage.
     *
     * @param BandwidthUsage $bandwidthUsage Current bandwidth usage classification.
     * @param int|null $estimatedThroughput Estimated throughput in bps.
     * @param int $nowMs Current time in milliseconds.
     * @return int|null Updated bitrate, or null if not initialized.
     */
    public function update(BandwidthUsage $bandwidthUsage, ?int $estimatedThroughput, int $nowMs): ?int
    {
        if (!$this->initializeBitrate($estimatedThroughput, $nowMs)) {
            return null;
        }

        $this->updateState($bandwidthUsage, $nowMs);

        $newBitrate = $this->adjustBitrate($estimatedThroughput, $nowMs);

        $this->currentBitrate = $this->clampBitrate($newBitrate, $this->latestEstimatedThroughput);
        return $this->currentBitrate;
    }

    /**
     * Initializes the bitrate controller after receiving enough throughput data.
     *
     * @param int|null $estimatedThroughput Initially estimated throughput.
     * @param int $nowMs Current time in milliseconds.
     * @return bool Whether bitrate is now initialized.
     */
    private function initializeBitrate(?int $estimatedThroughput, int $nowMs): bool
    {
        if (!$this->currentBitrateInitialized && $estimatedThroughput !== null) {
            if ($this->firstEstimatedThroughputTime === null) {
                $this->firstEstimatedThroughputTime = $nowMs;
            } elseif ($nowMs - $this->firstEstimatedThroughputTime > 3000) {
                $this->currentBitrate = $estimatedThroughput;
                $this->currentBitrateInitialized = true;
            }
        }

        return $this->currentBitrateInitialized || $estimatedThroughput !== null;
    }

    /**
     * Transitions the internal AIMD state based on the given bandwidth usage.
     *
     * @param BandwidthUsage $bandwidthUsage The observed bandwidth usage state.
     * @param int $nowMs Current time in milliseconds.
     */
    private function updateState(BandwidthUsage $bandwidthUsage, int $nowMs): void
    {
        if ($bandwidthUsage === BandwidthUsage::NORMAL && $this->state === RateControlState::HOLD) {
            $this->lastChangeMs = $nowMs;
            $this->state = RateControlState::INCREASE;
        } elseif ($bandwidthUsage === BandwidthUsage::OVERUSING) {
            $this->state = RateControlState::DECREASE;
        } elseif ($bandwidthUsage === BandwidthUsage::UNDERUSING) {
            $this->state = RateControlState::HOLD;
        }
    }

    /**
     * Calculates the new bitrate based on current state and throughput.
     *
     * @param int|null $estimatedThroughput Current estimated throughput.
     * @param int $nowMs Current time in milliseconds.
     * @return int Newly calculated bitrate.
     */
    private function adjustBitrate(?int $estimatedThroughput, int $nowMs): int
    {
        $newBitrate = $this->currentBitrate;
        $this->latestEstimatedThroughput = $estimatedThroughput ?? $this->latestEstimatedThroughput;
        $estimatedThroughputKbps = $this->latestEstimatedThroughput / 1000;

        if ($this->state === RateControlState::INCREASE) {
            $newBitrate = $this->increaseBitrate($estimatedThroughputKbps, $nowMs);
        } elseif ($this->state === RateControlState::DECREASE) {
            $newBitrate = $this->decreaseBitrate($estimatedThroughputKbps, $nowMs);
        }

        return $newBitrate;
    }

    /**
     * Increases the current bitrate using additive or multiplicative strategy.
     *
     * @param float $estimatedThroughputKbps Estimated throughput in kbps.
     * @param int $nowMs Current time in milliseconds.
     * @return int New increased bitrate.
     */
    private function increaseBitrate(float $estimatedThroughputKbps, int $nowMs): int
    {
        if ($this->avgMaxBitrateKbps !== null) {
            $sigmaKbps = sqrt($this->varMaxBitrateKbps * $this->avgMaxBitrateKbps);
            if ($estimatedThroughputKbps >= $this->avgMaxBitrateKbps + 3 * $sigmaKbps) {
                $this->nearMax = false;
                $this->avgMaxBitrateKbps = null;
            }
        }

        $increaseAmount = $this->nearMax
            ? $this->additiveRateIncrease($this->lastChangeMs, $nowMs)
            : $this->multiplicativeRateIncrease($this->currentBitrate, $this->lastChangeMs, $nowMs);

        $this->lastChangeMs = $nowMs;
        return $this->currentBitrate + $increaseAmount;
    }

    /**
     * Decreases the bitrate in response to overuse, updating internal estimates.
     *
     * @param float $estimatedThroughputKbps Estimated throughput in kbps.
     * @param int $nowMs Current time in milliseconds.
     * @return int New reduced bitrate.
     */
    private function decreaseBitrate(float $estimatedThroughputKbps, int $nowMs): int
    {
        if ($this->avgMaxBitrateKbps !== null) {
            $sigmaKbps = sqrt($this->varMaxBitrateKbps * $this->avgMaxBitrateKbps);
            if ($estimatedThroughputKbps < $this->avgMaxBitrateKbps - 3 * $sigmaKbps) {
                $this->avgMaxBitrateKbps = null;
            }
        }

        $this->updateMaxThroughputEstimate($estimatedThroughputKbps);

        $this->nearMax = true;
        $this->lastChangeMs = $nowMs;
        $this->state = RateControlState::HOLD;

        return round(0.85 * $this->latestEstimatedThroughput);
    }

    /**
     * Calculates the additive rate increase based on elapsed time.
     *
     * @param int $lastMs Last change timestamp.
     * @param int $nowMs Current time.
     * @return int Bitrate increase amount.
     */
    private function additiveRateIncrease(int $lastMs, int $nowMs): int
    {
        return intval(($nowMs - $lastMs) * $this->nearMaxRateIncrease() / 1000);
    }

    /**
     * Calculates multiplicative bitrate increase based on time and current rate.
     *
     * @param int|null $newBitrate Current bitrate.
     * @param int|null $lastMs Last time bitrate changed.
     * @param int $nowMs Current time.
     * @return int Increase amount.
     */
    private function multiplicativeRateIncrease(?int $newBitrate, ?int $lastMs, int $nowMs): int
    {
        $alpha = 1.08;
        if ($lastMs !== null) {
            $elapsedMs = min($nowMs - $lastMs, 1000);
            $alpha = pow($alpha, $elapsedMs / 1000);
        }
        return intval(max(($alpha - 1) * $newBitrate, 1000));
    }

    /**
     * Limits the bitrate to a range based on estimated throughput.
     *
     * @param int $newBitrate Proposed new bitrate.
     * @param int $estimatedThroughput Measured throughput in bps.
     * @return int Clamped bitrate.
     */
    private function clampBitrate(int $newBitrate, int $estimatedThroughput): int
    {
        $maxBitrate = max(intval(1.5 * $estimatedThroughput) + 10000, $this->currentBitrate);
        return min($newBitrate, $maxBitrate);
    }

    /**
     * Computes the near-max rate increase for additive control mode.
     *
     * @return int Additive increases rate in bits per second.
     */
    private function nearMaxRateIncrease(): int
    {
        $bitsPerFrame = $this->currentBitrate / 30;
        $packetsPerFrame = ceil($bitsPerFrame / (8 * 1200));
        $avgPacketSizeBits = $bitsPerFrame / $packetsPerFrame;

        $responseTime = $this->rtt + 100;
        return max(4000, intval(($avgPacketSizeBits * 1000) / $responseTime));
    }

    /**
     * Updates the average and variance of maximum throughput estimate.
     *
     * @param float $estimatedThroughputKbps Current estimated throughput in kbps.
     */
    private function updateMaxThroughputEstimate(float $estimatedThroughputKbps): void
    {
        $alpha = 0.05;
        if ($this->avgMaxBitrateKbps === null) {
            $this->avgMaxBitrateKbps = $estimatedThroughputKbps;
        } else {
            $this->avgMaxBitrateKbps = (1 - $alpha) * $this->avgMaxBitrateKbps + $alpha * $estimatedThroughputKbps;
        }

        $norm = max(1, $this->avgMaxBitrateKbps);
        $this->varMaxBitrateKbps = (1 - $alpha) * $this->varMaxBitrateKbps + $alpha *
            pow($this->avgMaxBitrateKbps - $estimatedThroughputKbps, 2) / $norm;
        $this->varMaxBitrateKbps = max(0.4, min($this->varMaxBitrateKbps, 2.5));
    }

    /**
     * Returns the current rate control state (HOLD, INCREASE, DECREASE).
     *
     * @return RateControlState Current state.
     */
    public function getState(): RateControlState
    {
        return $this->state;
    }

    /**
     * Returns the average of the estimated maximum throughput in kbps.
     *
     * @return float|null Estimated average max throughput or null if uninitialized.
     */
    public function getAvgMaxBitrateKbps(): ?float
    {
        return $this->avgMaxBitrateKbps;
    }

    /**
     * Returns the variance of the estimated maximum throughput in kbps.
     *
     * @return float Variance of max throughput.
     */
    public function getVarMaxBitrateKbps(): float
    {
        return $this->varMaxBitrateKbps;
    }

    /**
     * Indicates whether the current bitrate is near the estimated maximum.
     *
     * @return bool True if near max, false otherwise.
     */
    public function isNearMax(): bool
    {
        return $this->nearMax;
    }
}


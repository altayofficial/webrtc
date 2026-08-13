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
 * Estimates bandwidth usage based on delay trends.
 *
 * This class implements a delay-based bandwidth overuse estimator. It tracks
 * offset and slope values to detect network overuse or underuse patterns, based
 * on RTP packet timing and size data. The estimator uses a Kalman filter to adapt
 * over time and adjust for network noise.
 *
 * Adapted from the WebRTC project's implementation.
 */
class OveruseEstimator {

    /** Minimum number of deltas to keep
     * @var int */
    const MIN_NUM_DELTAS = 1000;

    /** Minimum number of frame intervals to store for frame period estimation
     * @var int */
    const MIN_FRAME_PERIOD_HISTORY_LENGTH = 60;

    /** Error covariance matrix for Kalman filter */
    private array $E = [[100.0, 0.0], [0.0, 0.1]];

    /** Number of inter-arrival deltas used */
    private int $numOfDeltas = 0;

    /** Current Kalman filter offset estimate */
    private float $offset = 0.0;

    /** Previous offset value used for hypothesis testing */
    private float $previousOffset = 0.0;

    /** Kalman filter slope estimate */
    private float $slope = 1 / 64;

    /** History of timestamp deltas for minimum frame period tracking */
    private array $tsDeltaHist = [];

    /** Estimated average noise */
    private float $avgNoise = 0.0;

    /** Estimated variance of noise */
    private float $varNoise = 50.0;

    /** Process noise parameters for Kalman filter */
    private array $processNoise = [1e-13, 1e-3];

    /**
     * Gets the number of inter-arrival deltas used in the estimation.
     *
     * @return int Number of deltas.
     */
    public function getNumOfDeltas(): int {
        return $this->numOfDeltas;
    }

    /**
     * Gets the current offset estimate from the Kalman filter.
     *
     * @return float Estimated offset.
     */
    public function getOffset(): float {
        return $this->offset;
    }

    /**
     * Updates the Kalman filter and noise model with a new observation.
     *
     * @param int $timeDeltaMs Time difference between arrivals (in ms).
     * @param float $timestampDeltaMs RTP timestamp difference converted to ms.
     * @param int $sizeDelta Size difference of packets (in bytes).
     * @param BandwidthUsage $currentHypothesis Current overuse/underuse state.
     */
    public function update(int $timeDeltaMs, float $timestampDeltaMs, int $sizeDelta, BandwidthUsage $currentHypothesis): void {
        $minFramePeriod = $this->updateMinFramePeriod($timestampDeltaMs);
        $tTsDelta = $timeDeltaMs - $timestampDeltaMs;
        $this->numOfDeltas = min($this->numOfDeltas + 1, self::MIN_NUM_DELTAS);

        $this->updateKalmanFilter($currentHypothesis);
        $residual = $this->calculateResidual($tTsDelta, $sizeDelta);
        $this->updateNoiseEstimateIfNeeded($residual, $minFramePeriod, $currentHypothesis);
        $this->updateState($residual, $sizeDelta);
    }

    /**
     * Updates the Kalman filter prediction error covariance matrix.
     *
     * Adjusts the process noise based on the hypothesis (normal, overuse, underuse).
     *
     * @param BandwidthUsage $currentHypothesis The current bandwidth usage hypothesis.
     */
    private function updateKalmanFilter(BandwidthUsage $currentHypothesis): void {
        $this->E[0][0] += $this->processNoise[0];
        $this->E[1][1] += $this->processNoise[1];

        if (($currentHypothesis === BandwidthUsage::OVERUSING && $this->offset < $this->previousOffset) ||
            ($currentHypothesis === BandwidthUsage::UNDERUSING && $this->offset > $this->previousOffset)) {
            $this->E[1][1] += 10 * $this->processNoise[1];
        }
    }

    /**
     * Computes the residual (error) between expected and actual time deltas.
     *
     * @param float $tTsDelta The time delta minus timestamp delta.
     * @param int $sizeDelta Packet size delta in bytes.
     * @return float The residual value.
     */
    private function calculateResidual(float $tTsDelta, int $sizeDelta): float {
        return $tTsDelta - $this->slope * $sizeDelta - $this->offset;
    }

    /**
     * Conditionally updates the noise estimate if in NORMAL state.
     *
     * @param float $residual The computed residual.
     * @param float $minFramePeriod Minimum frame period from history.
     * @param BandwidthUsage $currentHypothesis Current bandwidth usage hypothesis.
     */
    private function updateNoiseEstimateIfNeeded(float $residual, float $minFramePeriod, BandwidthUsage $currentHypothesis): void {
        if ($currentHypothesis === BandwidthUsage::NORMAL) {
            $maxResidual = 3.0 * sqrt($this->varNoise);
            $adjustedResidual = abs($residual) < $maxResidual ? $residual : ($residual < 0 ? -$maxResidual : $maxResidual);
            $this->updateNoiseEstimate($adjustedResidual, $minFramePeriod);
        }
    }

    /**
     * Updates the Kalman state with the new residual and size delta.
     *
     * @param float $residual The residual from the prediction.
     * @param int $sizeDelta Packet size delta in bytes.
     */
    private function updateState(float $residual, int $sizeDelta): void {
        $h = [$sizeDelta, 1.0];
        $Eh = [
            $this->E[0][0] * $h[0] + $this->E[0][1] * $h[1],
            $this->E[1][0] * $h[0] + $this->E[1][1] * $h[1]
        ];

        $denom = $this->varNoise + $h[0] * $Eh[0] + $h[1] * $Eh[1];
        $K = [$Eh[0] / $denom, $Eh[1] / $denom];

        $IKh = [
            [1.0 - $K[0] * $h[0], -$K[0] * $h[1]],
            [-$K[1] * $h[0], 1.0 - $K[1] * $h[1]]
        ];

        $e00 = $this->E[0][0];
        $e01 = $this->E[0][1];

        $this->E[0][0] = $e00 * $IKh[0][0] + $this->E[1][0] * $IKh[0][1];
        $this->E[0][1] = $e01 * $IKh[0][0] + $this->E[1][1] * $IKh[0][1];
        $this->E[1][0] = $e00 * $IKh[1][0] + $this->E[1][0] * $IKh[1][1];
        $this->E[1][1] = $e01 * $IKh[1][0] + $this->E[1][1] * $IKh[1][1];

        $this->previousOffset = $this->offset;
        $this->slope += $K[0] * $residual;
        $this->offset += $K[1] * $residual;
    }

    /**
     * Tracks the minimum observed frame period from a sliding window history.
     *
     * @param float $tsDelta Current timestamp delta.
     * @return float Minimum frame period observed.
     */
    private function updateMinFramePeriod(float $tsDelta): float {
        $minFramePeriod = $tsDelta;
        if (count($this->tsDeltaHist) >= self::MIN_FRAME_PERIOD_HISTORY_LENGTH) {
            array_shift($this->tsDeltaHist);
        }

        foreach ($this->tsDeltaHist as $oldTsDelta) {
            $minFramePeriod = min($oldTsDelta, $minFramePeriod);
        }

        $this->tsDeltaHist[] = $tsDelta;
        return $minFramePeriod;
    }

    /**
     * Updates the noise estimate based on the current residual and frame period.
     *
     * @param float $residual The adjusted residual.
     * @param float $tsDelta Frame period in milliseconds.
     */
    private function updateNoiseEstimate(float $residual, float $tsDelta): void {
        $alpha = $this->numOfDeltas > 300 ? 0.002 : 0.01;
        $beta = pow(1 - $alpha, $tsDelta * 30.0 / 1000.0);
        $this->avgNoise = $beta * $this->avgNoise + (1 - $beta) * $residual;
        $this->varNoise = $beta * $this->varNoise + (1 - $beta) * pow($this->avgNoise - $residual, 2);

        if ($this->varNoise < 1) {
            $this->varNoise = 1;
        }
    }
}

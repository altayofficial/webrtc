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
 * A class for estimating the remote bitrate, controlling the rate based on incoming data,
 * inter-arrival times, and overuse detection.
 */
class RemoteBitrateEstimator
{

    /** abs-send-time estimator
     * @var int */
    private const INTER_ARRIVAL_SHIFT = 26;
    /**
     * @var int
     */
    private const TIMESTAMP_GROUP_LENGTH_MS = 5;
    /**
     * @var float
     */
    private const TIMESTAMP_TO_MS = 1000.0 / (1 << self::INTER_ARRIVAL_SHIFT);
    private RateCounter $incomingBitrate;
    private bool $incomingBitrateInitialized = true;
    private InterArrival $interArrival;
    private OveruseEstimator $estimator;
    private OveruseDetector $detector;
    private AimdRateControl $rateControl;
    private ?int $lastUpdateMs = null;
    private array $ssrcs = [];

    /**
     * Constructor for the RemoteBitrateEstimator class.
     * Initializes the components for bitrate estimation, overuse detection, and rate control.
     */
    public function __construct()
    {
        $this->incomingBitrate = new RateCounter(1000, 8000);
        $this->interArrival = new InterArrival(intval((self::TIMESTAMP_GROUP_LENGTH_MS << self::INTER_ARRIVAL_SHIFT) / 1000), self::TIMESTAMP_TO_MS);
        $this->estimator = new OveruseEstimator();
        $this->detector = new OveruseDetector();
        $this->rateControl = new AimdRateControl();
    }

    /**
     * Adds a new packet arrival in the bitrate estimator and updates the estimate if necessary.
     *
     * @param int $arrivalTimeMs The timestamp of the packet arrival in milliseconds.
     * @param int $absSendTime The absolute send time of the packet.
     * @param int $payloadSize The size of the packet payload.
     * @param int $ssrc The SSRC of the stream.
     *
     * @return array|null The target bitrate and a list of SSRCs if the estimate is updated, or null if not.
     */
    public function add(int $arrivalTimeMs, int $absSendTime, int $payloadSize, int $ssrc): ?array
    {
        $this->trackSsrc($arrivalTimeMs, $ssrc);
        $this->updateIncomingBitrate($arrivalTimeMs, $payloadSize);

        $deltas = $this->computeInterArrivalDeltas($arrivalTimeMs, $absSendTime, $payloadSize);
        $this->processInterArrivalDeltas($deltas, $arrivalTimeMs);

        $updateEstimate = $this->shouldUpdateEstimate($arrivalTimeMs);

        if ($updateEstimate) {
            return $this->updateTargetBitrate($arrivalTimeMs);
        }

        return null;
    }

    /**
     * Tracks the SSRC and its associated arrival time.
     *
     * @param int $arrivalTimeMs The timestamp of the packet arrival in milliseconds.
     * @param int $ssrc The SSRC of the stream.
     */
    private function trackSsrc(int $arrivalTimeMs, int $ssrc): void
    {
        $this->ssrcs[$ssrc] = $arrivalTimeMs;
    }

    /**
     * Updates the incoming bitrate based on the packet arrival and payload size.
     *
     * @param int $arrivalTimeMs The timestamp of the packet arrival in milliseconds.
     * @param int $payloadSize The size of the packet payload.
     */
    private function updateIncomingBitrate(int $arrivalTimeMs, int $payloadSize): void
    {
        if ($this->incomingBitrate->rate($arrivalTimeMs) !== null) {
            $this->incomingBitrateInitialized = true;
        } elseif ($this->incomingBitrateInitialized) {
            $this->incomingBitrate->reset();
            $this->incomingBitrateInitialized = false;
        }
        $this->incomingBitrate->add($payloadSize, $arrivalTimeMs);
    }

    /**
     * Computes the inter-arrival deltas based on the timestamp, arrival time, and payload size.
     *
     * @param int $arrivalTimeMs The timestamp of the packet arrival in milliseconds.
     * @param int $absSendTime The absolute send time of the packet.
     * @param int $payloadSize The size of the packet payload.
     *
     * @return object|null The calculated inter-arrival deltas or null if not calculable.
     */
    private function computeInterArrivalDeltas(int $arrivalTimeMs, int $absSendTime, int $payloadSize): ?InterArrivalDelta
    {
        $timestamp = $absSendTime << 8;
        return $this->interArrival->computeDeltas($timestamp, $arrivalTimeMs, $payloadSize);
    }

    /**
     * Processes the inter-arrival deltas and updates the estimator and detector.
     *
     * @param InterArrivalDelta|null $deltas The inter-arrival deltas.
     * @param int $arrivalTimeMs The timestamp of the packet arrival in milliseconds.
     */
    private function processInterArrivalDeltas(?InterArrivalDelta $deltas, int $arrivalTimeMs): void
    {
        if ($deltas !== null) {
            $timestampDeltaMs = $deltas->timestamp * self::TIMESTAMP_TO_MS;
            $this->estimator->update(
                $deltas->arrivalTime,
                $timestampDeltaMs,
                $deltas->size,
                $this->detector->state()
            );
            $this->detector->detect(
                $this->estimator->getOffset(),
                $timestampDeltaMs,
                $this->estimator->getNumOfDeltas(),
                $arrivalTimeMs
            );
        }
    }

    /**
     * Determines if the bitrate estimate should be updated based on the current arrival time and conditions.
     *
     * @param int $arrivalTimeMs The timestamp of the packet arrival in milliseconds.
     *
     * @return bool True if the estimate should be updated, false otherwise.
     */
    private function shouldUpdateEstimate(int $arrivalTimeMs): bool
    {
        if (
            $this->lastUpdateMs === null ||
            ($arrivalTimeMs - $this->lastUpdateMs) > $this->rateControl->feedbackInterval()
        ) {
            return true;
        } elseif ($this->detector->state() === BandwidthUsage::OVERUSING) {
            return true;
        }
        return false;
    }

    /**
     * Updates the target bitrate based on the state of the detector and the incoming bitrate.
     *
     * @param int $arrivalTimeMs The timestamp of the packet arrival in milliseconds.
     *
     * @return array|null The target bitrate and a list of SSRCs if updated, or null otherwise.
     */
    private function updateTargetBitrate(int $arrivalTimeMs): ?array
    {
        $targetBitrate = $this->rateControl->update(
            $this->detector->state(),
            $this->incomingBitrate->rate($arrivalTimeMs),
            $arrivalTimeMs
        );
        if ($targetBitrate !== null) {
            $this->lastUpdateMs = $arrivalTimeMs;
            return [$targetBitrate, array_keys($this->ssrcs)];
        }
        return null;
    }
}
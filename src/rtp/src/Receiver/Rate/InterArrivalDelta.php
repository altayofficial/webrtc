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
 * Class InterArrivalDelta
 *
 * Represents the computed differences (deltas) between two RTP timestamp groups.
 * These deltas are used in delay-based bitrate estimation to analyze trends in
 * network delay and packet delivery over time.
 *
 * Properties:
 * - $timestamp: The difference in RTP timestamps between two groups.
 * - $arrivalTime: The difference in arrival times (milliseconds) between two groups.
 * - $size: The difference in total packet sizes (bytes) between two groups.
 */
class InterArrivalDelta {
    public int $timestamp;
    public int $arrivalTime;
    public int $size;

    public function __construct(int $timestamp, int $arrivalTime, int $size) {
        $this->timestamp = $timestamp;
        $this->arrivalTime = $arrivalTime;
        $this->size = $size;
    }
}
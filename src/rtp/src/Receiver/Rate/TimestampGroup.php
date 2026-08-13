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

use Webrtc\Mixin\DataClass;

#[DataClass]
class TimestampGroup {
    public ?int $arrivalTime;
    public ?int $firstTimestamp;
    public ?int $lastTimestamp;
    public int $size;

    /**
     * @param int|null $timestamp
     */
    public function __construct(?int $timestamp = null) {
        $this->arrivalTime = null;
        $this->firstTimestamp = $timestamp;
        $this->lastTimestamp = $timestamp;
        $this->size = 0;
    }
}

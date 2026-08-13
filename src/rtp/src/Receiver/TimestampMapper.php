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

/**
 * TimestampMapper normalizes RTP timestamps to a continuous timeline.
 *
 * This class handles 32-bit RTP timestamp wraparounds and produces a stable,
 * monotonic timestamp sequence starting from the first observed timestamp.
 * Useful for synchronizing media streams or aligning packet timing data.
 */
class TimestampMapper
{
    private ?int $last = null;
    private ?int $origin = null;

    /**
     * Maps an RTP timestamp to a continuous timeline.
     *
     * This function handles wraparounds to ensure continuity.
     *
     * @param int $timestamp The RTP timestamp.
     * @return int The mapped timestamp relative to the first received timestamp.
     */
    public function map(int $timestamp): int
    {
        if ($this->origin === null) {
            // First timestamp received, set as origin
            $this->origin = $timestamp;
        } elseif ($this->last !== null && $timestamp < $this->last) {
            // RTP timestamp wrapped around (32-bit overflow)
            $this->origin -= (1 << 32);
        }

        $this->last = $timestamp;
        return $timestamp - $this->origin;
    }
}

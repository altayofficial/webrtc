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
 * Rate bucket for storing count and value
 */
class RateBucket {
    public int $count;
    public int $value;

    /**
     * @param int $count
     * @param int $value
     */
    public function __construct(int $count = 0, int $value = 0) {
        $this->count = $count;
        $this->value = $value;
    }

    /**
     * Compare RateBucket with itself.
     *
     * @param RateBucket $other
     * @return bool
     */
    public function compare(RateBucket $other): bool {
        return $this->count === $other->count && $this->value === $other->value;
    }
}

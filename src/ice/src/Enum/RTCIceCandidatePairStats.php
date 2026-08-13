<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\ICE\Enum;

/**
 * Candidate pair state.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8445#section-6.1.2.6
 */
enum RTCIceCandidatePairStats: int
{
    case FROZEN = 0;
    case WAITING = 1;
    case IN_PROGRESS = 2;
    case SUCCEEDED = 3;
    case FAILED = 4;
}

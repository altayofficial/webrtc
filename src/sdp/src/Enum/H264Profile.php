<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SDP\Enum;

enum H264Profile: int
{
    case PROFILE_CONSTRAINED_BASELINE = 0;
    case PROFILE_BASELINE = 1;
    case PROFILE_MAIN = 2;
    case PROFILE_CONSTRAINED_HIGH = 3;
    case PROFILE_HIGH = 4;
    case PROFILE_PREDICTIVE_HIGH_444 = 5;
}
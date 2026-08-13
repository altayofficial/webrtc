<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP\Enum;

enum BandwidthUsage: int
{
    case     NORMAL = 0;
    case UNDERUSING = 1;
    case OVERUSING = 2;
}
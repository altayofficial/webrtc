<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Webrtc\Enum;

enum SignalingState: int
{
    case closed = 0;
    case haveLocalOffer = 1;
    case haveLocalPranswer = 2;
    case haveRemoteOffer = 3;
    case stable = 4;
    case haveRemotePranswer = 5;
}

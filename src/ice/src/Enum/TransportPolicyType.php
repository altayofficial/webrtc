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

enum TransportPolicyType: int
{
    // All ICE candidates will be considered.
    case ALL = 0;

    // Only ICE candidates whose IP addresses are being relayed,
    // such as those being passed through a STUN or TURN server,
    // will be considered.
    case RELAY = 1;

}
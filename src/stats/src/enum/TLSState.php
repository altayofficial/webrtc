<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Stats\enum;

enum TLSState: int
{
    case NEW = 0;
    case CONNECTING = 1;
    case CONNECTED = 2;
    case CLOSED = 3;
    case FAILED = 4;
}
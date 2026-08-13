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

enum ConnectionState: int
{
    case connected = 0;
    case connecting = 1;
    case closed = 2;
    case failed = 3;
    case new = 4;
}

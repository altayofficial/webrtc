<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SCTP\Enum;

enum State: int {
    case CLOSED = 1;
    case COOKIE_WAIT = 2;
    case COOKIE_ECHOED = 3;
    case ESTABLISHED = 4;
    case SHUTDOWN_PENDING = 5;
    case SHUTDOWN_SENT = 6;
    case SHUTDOWN_RECEIVED = 7;
    case SHUTDOWN_ACK_SENT = 8;
    case CONNECTING = 9;
}

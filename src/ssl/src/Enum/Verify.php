<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SSL\Enum;

enum Verify: int
{
    case  NONE = 0x00;
    case  PEER = 0x01;
    case  FAIL_IF_NO_PEER_CERT = 0x02;
    case  CLIENT_ONCE = 0x04;
    case  POST_HANDSHAKE = 0x08;
}
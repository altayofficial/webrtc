<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\STUN\Enum;


/**
 * Enum representing different message methods for WebRTC protocols.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc5389#section-18.1
 * */
enum MessageMethod: int
{
    case BINDING = 0x1;
    case SHARED_SECRET = 0x2;
    case ALLOCATE = 0x3;
    case REFRESH = 0x4;
    case SEND = 0x6;
    case DATA = 0x7;
    case CREATE_PERMISSION = 0x8;
    case CHANNEL_BIND = 0x9;
}
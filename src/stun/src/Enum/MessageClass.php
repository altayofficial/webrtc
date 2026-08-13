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
 * Enum representing different message classes for WebRTC protocols.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc5389#section-6
 * */
enum MessageClass: int
{
    case REQUEST = 0x000;
    case INDICATION = 0x010;
    case RESPONSE = 0x100;
    case ERROR = 0x110;
}
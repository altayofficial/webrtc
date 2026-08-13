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

enum StatType
{
    case Transport;
    case RemoteInboundRtpStream;
    case RemoteOutboundRtpStream;
    case InboundRtpStream;
    case OutboundRtpStream;
    case ReceivedRtpStream;
}
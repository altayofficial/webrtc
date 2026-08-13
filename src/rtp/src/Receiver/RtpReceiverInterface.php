<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP\Receiver;

use Webrtc\RTCP\RtcpPacketInterface;
use Webrtc\RTP\RtpPacket;

interface RtpReceiverInterface
{
    public function stop(): void;
    public function handleRtcpPacket(RtcpPacketInterface $packet): void;
    public function handleRtpPacket(RtpPacket $packet, int $arrivalTimeMs): void;
}
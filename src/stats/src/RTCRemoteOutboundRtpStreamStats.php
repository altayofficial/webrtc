<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Stats;

use DateTimeImmutable;
use Webrtc\Mixin\DataClass;
use Webrtc\Stats\enum\StatType;

#[DataClass]
class RTCRemoteOutboundRtpStreamStats extends RTCSentRtpStreamStats
{
    public function __construct(
        string $id,
        int $ssrc,
        string $kind,
        string $transportId,
        int $packetsSent,
        int $bytesSent,
        DateTimeImmutable $dateTime = new DateTimeImmutable(),
        StatType $type = StatType::RemoteOutboundRtpStream,
        public ?DateTimeImmutable $remoteTimestamp = null
    )
    {
        parent::__construct($id, $type, $ssrc, $kind, $transportId, $packetsSent, $bytesSent, $dateTime);
    }
}
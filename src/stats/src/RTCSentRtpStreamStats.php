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
class RTCSentRtpStreamStats extends RTCRtpStreamStats
{
    /**
     * @param string $id The id associated with this stat.
     * @param StatType $type The type of the stat.
     * @param DateTimeImmutable $dateTime The timestamp associated with this object.
     * @param int $ssrc The Synchronization Source identifier.
     * @param string $kind Stream Type (e.g. video, audio)
     * @param string $transportId The ID of DTLSTransport object.
     * @param int $packetsSent Total number of RTP packets sent for this SSRC.
     * @param int $bytesSent Total number of bytes sent for this SSRC.
     */
    public function __construct(
        string     $id,
        StatType   $type,
        int        $ssrc,
        string     $kind,
        string     $transportId,
        public int $packetsSent,
        public int $bytesSent,
        DateTimeImmutable  $dateTime = new DateTimeImmutable()
    )
    {
        parent::__construct($id, $type, $dateTime, $ssrc, $kind, $transportId);
    }
}
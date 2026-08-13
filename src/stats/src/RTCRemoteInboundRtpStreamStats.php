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
class RTCRemoteInboundRtpStreamStats extends RTCReceivedRtpStreamStats
{

    /**
     * @param string $id The id associated with this stat.
     * @param StatType $type The type of the stat.
     * @param DateTimeImmutable $dateTime The timestamp associated with this object.
     * @param int $ssrc The Synchronization Source identifier.
     * @param string $kind Stream Type (e.g. video, audio)
     * @param string $transportId The ID of DTLSTransport object.
     * @param int $packetsReceived Total number of RTP packets received for this SSRC.
     * @param int $packetsLost Total number of RTP packets lost for this SSRC.
     * @param int $jitter Jitter value.
     * @param float $roundTripTime Time of a round trip
     * @param float $fractionLost The amount of fraction packet lost
     */
    public function __construct(
        string       $id,
        int          $ssrc,
        string       $kind,
        string       $transportId,
        int          $packetsReceived,
        int          $packetsLost,
        int          $jitter,
        public float $roundTripTime,
        public float $fractionLost,
        StatType     $type = StatType::RemoteInboundRtpStream,
        DateTimeImmutable    $dateTime = new DateTimeImmutable(),
    )
    {
        parent::__construct($id, $type, $dateTime, $ssrc, $kind, $transportId, $packetsReceived, $packetsLost, $jitter);
    }
}
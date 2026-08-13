<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTPParameter;
use Webrtc\Mixin\DataClass;

#[DataClass]
abstract class RTCRtpCodingParameters
{
    /**
     * @param int $ssrc The Synchronization Source identifier.
     * @param int $payloadType The value that goes in the RTP Payload Type Field.
     * @param RTCRtpRtxParameters|null $rtx represent RTCRtpRtxParameters class
     */
    public function __construct(
        public readonly int                  $ssrc,
        public readonly int                  $payloadType,
        public readonly ?RTCRtpRtxParameters $rtx = null
    )
    {
    }
}
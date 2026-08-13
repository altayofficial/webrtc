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
class RTCRtpParameters
{
    /**
     * @param RTCRtpCodecParameters[] $codecs An array of `RTCRtpCodecParameters` to send or receive.
     * @param RTCRtpHeaderExtensionParameters[] $headerExtensions An array of `RTCRtpHeaderExtensionParameters`.
     * @param string $muxId The muxId assigned to the RTP stream, if any, empty string if unset.
     * @param RTCRtcpParameters $rtcp Parameters to configure RTCP.
     */
    public function __construct(
        public array              $codecs = [],
        public array              $headerExtensions = [],
        public string             $muxId = '',
        public RTCRtcpParameters $rtcp = new RTCRtcpParameters()
    )
    {
    }
}

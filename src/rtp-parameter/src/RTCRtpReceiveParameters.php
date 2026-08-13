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
class RTCRtpReceiveParameters extends RTCRtpParameters
{
    /**
     * @param RTCRtpCodecParameters[] $codecs An array of `RTCRtpCodecParameters` to send or receive.
     * @param RTCRtpHeaderExtensionParameters[] $headerExtensions An array of `RTCRtpHeaderExtensionParameters`.
     * @param string $muxId The muxId assigned to the RTP stream, if any, empty string if unset.
     * @param RTCRtcpParameters $rtcp Parameters to configure RTCP.
     * @param RTCRtpDecodingParameters[] $encodings An array of `RTCRtpDecodingParameters`
     */
    public function __construct(
        array $codecs = [],
        array $headerExtensions = [],
        string $muxId = '',
        RTCRtcpParameters $rtcp = new RTCRtcpParameters(),
        public array $encodings = []
    )
    {
        parent::__construct($codecs, $headerExtensions, $muxId, $rtcp);
    }
}
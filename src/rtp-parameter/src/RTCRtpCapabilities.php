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
class RTCRtpCapabilities
{
    /**
     * @param RTCRtpCodecCapability[] $codecs An array of `RTCRtpCodecCapability`.
     * @param RTCRtpHeaderExtensionCapability[] $headerExtensions An array of `RTCRtpHeaderExtensionCapability`.
     */
    public function __construct(
        public readonly array $codecs = [],
        public readonly array $headerExtensions = []
    )
    {
    }
}
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
class RTCRtpHeaderExtensionParameters
{
    /**
     * @param int $id The value that goes in the packet.
     * @param string $uri The URI of the RTP header extension.
     */
    public function __construct(
        public readonly int    $id,
        public readonly string $uri
    )
    {
    }
}
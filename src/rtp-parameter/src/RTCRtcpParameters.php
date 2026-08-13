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
class RTCRtcpParameters
{
    /**
     * @param string|null $cname The Canonical Name (CNAME) used by RTCP.
     * @param bool $mux Whether RTP and RTCP are multiplexed.
     * @param int|null $ssrc The Synchronization Source identifier.
     */
    public function __construct(
        public ?string $cname = null,
        public bool   $mux = false,
        public ?int   $ssrc = null
    )
    {
    }
}
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
class RTCRtcpFeedback
{
    /**
     * @param string $type The type of feedback
     * @param string|null $parameter Optional Parameter
     */
    public function __construct(
        public readonly string  $type,
        public readonly ?string $parameter = null
    )
    {
    }
}
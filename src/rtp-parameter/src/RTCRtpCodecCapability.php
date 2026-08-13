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
class RTCRtpCodecCapability
{
    /**
     * @param string $mimeType The codec MIME media type/subtype, for instance `'audio/PCMU'`.
     * @param int $clockRate The codec clock rate expressed in Hertz.
     * @param int|null $channels The number of channels supported (e.g., two for stereo).
     * @param array<string, int|string|null> $parameters Codec-specific parameters available for signaling.
     */
    public function __construct(
        public readonly string $mimeType,
        public readonly int    $clockRate,
        public readonly ?int   $channels = null,
        public readonly array  $parameters = []
    )
    {
    }
}
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
class RTCRtpCodecParameters
{
    /**
     * @param string $mimeType The codec MIME media type/subtype, for instance `'audio/PCMU'`.
     * @param int $clockRate The codec clock rate expressed in Hertz.
     * @param int|null $channels The number of channels supported (e.g., two for stereo).
     * @param int|null $payloadType The value that goes in the RTP Payload Type Field.
     * @param RTCRtcpFeedback[] $rtcpFeedback Transport layer and codec-specific feedback messages for this codec.
     * @param array<string, int|string|null> $parameters Codec-specific parameters available for signaling.
     */
    public function __construct(
        public string $mimeType,
        public int    $clockRate,
        public ?int   $channels = null,
        public ?int   $payloadType = null,
        public array  $rtcpFeedback = [],
        public array  $parameters = []
    )
    {
    }

    public function __toString() {
        $name = explode("/", $this->mimeType)[1];
        $s = "$name/$this->clockRate";
        if ($this->channels == 2) {
            $s .= "/2";
        }
        return $s;
    }
}
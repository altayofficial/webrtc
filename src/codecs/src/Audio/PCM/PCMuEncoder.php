<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Codecs\Audio\PCM;

use Webrtc\AVCodec\Exception\AvCodecException;

/**
 * PCM μ-law Encoder Class
 *
 * Encodes 16-bit linear PCM audio to μ-law (G.711) compressed format.
 * This encoder is optimized for telephony applications and VoIP systems,
 * providing the standard 64 kbps μ-law encoding used in North American
 * digital telephony networks.
 *
 * @package Webrtc\Codecs\Audio\PCM
 */
class PCMuEncoder extends PCMEncoder
{
    /**
     * Constructor - Initializes μ-law PCM encoder
     *
     * Configures the encoder with the following specifications:
     * - Codec: pcm_mulaw (ITU-T G.711 μ-law)
     * - Sample Rate: 8000 Hz (standard telephony)
     * - Channels: Mono
     * - Input Format: Signed 16-bit PCM
     * - Output Format: 8-bit μ-law compressed
     *
     * The encoder maintains compatibility with:
     * - PSTN systems
     * - VoIP protocols (SIP, RTP)
     * - Legacy telephony equipment
     * @throws AvCodecException
     */
    public function __construct()
    {
        parent::__construct("pcm_mulaw");
    }
}
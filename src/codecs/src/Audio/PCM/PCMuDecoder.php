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
 * PCM μ-law Decoder Class
 *
 * Decodes μ-law (mu-law) PCM audio to standard 8 kHz mono signed 16-bit PCM format.
 * Extends the base PCMDecoder functionality specifically for μ-law codec (G.711).
 *
 * @package Webrtc\Codecs\Audio\PCM
 */
class PCMuDecoder extends PCMDecoder
{
    /**
     * Constructor
     *
     * Initializes the decoder with μ-law PCM codec configuration.
     * Configures the parent PCMDecoder with:
     * - 'pcm_mulaw' codec specification
     * - 8kHz output sample rate
     * - Mono channel output
     * - Signed 16-bit PCM output
     * @throws AvCodecException
     */
    public function __construct()
    {
        parent::__construct("pcm_mulaw");
    }
}
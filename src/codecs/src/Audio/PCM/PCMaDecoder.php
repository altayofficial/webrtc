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
 * PCM A-law Decoder Class
 *
 * Decodes A-law PCM audio to standard 8 kHz mono signed 16-bit PCM format.
 * Extends the base PCMDecoder functionality specifically for A-law codec.
 *
 * @package Webrtc\Codecs\Audio\PCM
 */
class PCMaDecoder extends PCMDecoder
{
    /**
     * Constructor
     *
     * Initializes the decoder with A-law PCM codec configuration.
     * Set up the parent PCMDecoder with 'pcm_alaw' codec specification.
     * @throws AvCodecException
     */
    public function __construct()
    {
        parent::__construct("pcm_alaw");
    }
}
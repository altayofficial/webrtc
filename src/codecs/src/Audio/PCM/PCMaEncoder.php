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
 * PCM A-law Encoder Class
 *
 * Encodes standard 8 kHz mono signed 16-bit PCM audio to A-law PCM format (G.711).
 * Extends the base PCMEncoder functionality specifically for A-law encoding.
 *
 * @package Webrtc\Codecs\Audio\PCM
 */
class PCMaEncoder extends PCMEncoder
{
    /**
     * Constructor
     *
     * Initializes the encoder with A-law PCM codec configuration.
     * Set up the parent PCMEncoder with 'pcm_alaw' codec specification.
     * Configure the encoder for:
     * - 8kHz sample rate
     * - Mono channel layout
     * - A-law compression
     * @throws AvCodecException
     */
    public function __construct()
    {
        parent::__construct("pcm_alaw");
    }
}
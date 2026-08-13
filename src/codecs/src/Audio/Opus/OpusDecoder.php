<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Codecs\Audio\Opus;

use Webrtc\AVCodec\AVCodec;
use Webrtc\AVCodec\AVFilter;
use Webrtc\AVCodec\Exception\AvCodecException;
use Webrtc\AVCodec\Frame\AudioFrame;
use Webrtc\Codecs\DecoderInterface;
use Webrtc\Codecs\JitterFrameInterface;
use Webrtc\Opus\Decoder;
use Webrtc\Opus\Exception\OpusException;
use Webrtc\Opus\Opus;

/**
 * Opus Audio Decoder Class
 *
 * Provides decoding of Opus audio packets to raw PCM audio frames.
 * Handles timestamp synchronization and produces audio frames in the
 * standard Opus output format (48 kHz, 960 samples per frame).
 *
 * @package Webrtc\Codecs\Audio\Opus
 */
class OpusDecoder implements DecoderInterface
{
    /**
     * @var Decoder $decoder Opus decoder instance
     */
    private Decoder $decoder;

    /**
     * Constructor
     *
     * Initializes required libraries:
     * - Opus native library
     * - AVFilter for potential post-processing
     * - AVCodec for base functionality
     * @throws OpusException
     * @throws AvCodecException
     */
    public function __construct()
    {
        Opus::init();
        AVFilter::init();
        AVCodec::init();
        $this->decoder = new Decoder();
    }

    /**
     * Decode an Opus packet to audio frame(s)
     *
     * @param JitterFrameInterface $frame Input frame containing Opus packet and timestamp
     * @return array Array containing one decoded AudioFrame (Opus uses fixed frame sizes)
     * @throws OpusException
     */
    public function decode(JitterFrameInterface $frame): array
    {
        $audioFrame = new AudioFrame(samples: 960);
        $audioFrame->setPts($frame->getTimestamp());
        $audioFrame->setSampleRate(48000);
        $audioFrame->setTimeBase(1, 48000);
        $sampleLength = $this->decoder->decode($frame->getData(), $audioFrame);
        assert($sampleLength === 960);

        return [$audioFrame];
    }
}
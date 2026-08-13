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

use Webrtc\AVCodec\Audio\AudioResampler;
use Webrtc\AVCodec\AVCodec;
use Webrtc\AVCodec\AVFilter;
use Webrtc\AVCodec\Data\Packet;
use Webrtc\AVCodec\Exception\AvCodecException;
use Webrtc\AVCodec\Frame\AudioFrame;
use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\Codecs\Encoder as BEncoder;
use Webrtc\Codecs\EncoderInterface;
use Webrtc\Exception\RuntimeException;
use Webrtc\Opus\Encoder;
use Webrtc\Opus\Exception\OpusException;
use Webrtc\Opus\Opus;

/**
 * Opus Audio Encoder Class
 *
 * Provides encoding of audio frames to Opus format, including resampling
 * to Opus-compatible parameters (48 kHz, stereo/mono).
 *
 * @package Webrtc\Codecs\Audio\Opus
 */
class OpusEncoder extends BEncoder implements EncoderInterface
{
    /**
     * @var int SAMPLE_RATE Opus required sample rate (48kHz)
     */
    private const SAMPLE_RATE = 48000;

    /**
     * @var int SAMPLES_PER_FRAME Opus frame size (960 samples)
     */
    private const SAMPLES_PER_FRAME = 960;

    /**
     * @var Encoder $encoder Opus encoder instance
     */
    private Encoder $encoder;

    /**
     * @var AudioResampler $resampler Audio resampler instance
     */
    private AudioResampler $resampler;

    /**
     * Constructor
     *
     * Initializes Opus, AVCodec, and AVFilter libraries
     * Create encoder and resampler instances
     * @throws OpusException
     * @throws AvCodecException
     */
    public function __construct()
    {
        Opus::init();
        AVCodec::init();
        AVFilter::init();
        $this->encoder = new Encoder();
        $this->resampler = new AudioResampler("s16", "stereo", self::SAMPLE_RATE, self::SAMPLES_PER_FRAME);
    }

    /**
     * Encode an audio frame to Opus format
     *
     * @param FrameInterface|AudioFrame $frame Audio frame to encode
     * @param bool $useKeyframe Ignored for audio (kept for interface compatibility)
     * @return array|string [packets, pts] Array of encoded packets and presentation timestamp
     * @throws RuntimeException If frame validation fails
     * @throws AvCodecException
     * @throws OpusException
     */
    public function encode(FrameInterface|AudioFrame $frame, bool $useKeyframe = false): string|array
    {
        $this->validateFrame($frame);
        $frames = $this->resampler->resample($frame);

        $packets = [];
        foreach ($frames as $frame) {
            $data = $frame->getPlanes()[0]->getData();
            $packets [] = $this->encoder->encode($data);
        }

        return [$packets, $frame->getPts()];
    }

    /**
     * Package encoded data into transport format
     *
     * @param Packet $packet Encoded audio packet
     * @return array|string [packets, pts] Array containing packet data and converted timestamp
     */
    public function pack(Packet $packet): string|array
    {
        return [[$packet->getData()], $this->convertTimebase($packet->getPts(), (array)$packet->getTimeBase(), [1, 48000])];
    }

    /**
     * Validate audio frame meets Opus requirements
     *
     * @param FrameInterface $frame Frame to validate
     * @throws RuntimeException If the frame is invalid
     */
    private function validateFrame(FrameInterface $frame): void
    {
        if (!$frame instanceof AudioFrame) {
            throw new RuntimeException("frame is not audio");
        }
        if ($frame->getFormat()->getName() != "s16") {
            throw new RuntimeException("format is not valid");
        }
        if (!in_array($frame->getLayout()->getName(), ["mono", "stereo"])) {
            throw new RuntimeException("Audio frame should be either mono or stereo");
        }
    }
}
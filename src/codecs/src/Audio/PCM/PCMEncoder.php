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

use Webrtc\AVCodec\AVCodec;
use Webrtc\AVCodec\AVFilter;
use Webrtc\AVCodec\Codec;
use Webrtc\AVCodec\Context\AudioContext;
use Webrtc\AVCodec\Data\Packet;
use Webrtc\AVCodec\Exception\AvCodecException;
use Webrtc\AVCodec\Frame\AudioFrame;
use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\AVCodec\TransCoder;
use Webrtc\Codecs\Encoder;
use Webrtc\Codecs\EncoderInterface;
use Webrtc\Exception\RuntimeException;

/**
 * PCM Audio Encoder Abstract Base Class
 *
 * Provides base functionality for encoding PCM audio to various codecs.
 * Handles common PCM input configuration (8 kHz, mono, signed 16-bit)
 * and provides a standard encoding pipeline.
 *
 * @package Webrtc\Codecs\Audio\PCM
 */
abstract class PCMEncoder extends Encoder implements EncoderInterface
{
    /**
     * @var int SAMPLE_RATE Input sample rate (8kHz)
     */
    private const SAMPLE_RATE = 8000;

    /**
     * @var TransCoder|null $transcoder Audio transcoder instance
     */
    private ?TransCoder $transcoder;

    /**
     * Constructor
     *
     * @param string $codecName Name of the target audio codec
     * @throws AvCodecException
     */
    public function __construct(string $codecName)
    {
        AVCodec::init();
        AVFilter::init();

        $audioContext = AudioContext::create(new Codec($codecName, "w"));
        $audioContext->setFormat("s16");
        $audioContext->setLayout("mono");
        $audioContext->setSampleRate(self::SAMPLE_RATE);
        $audioContext->open();
        $this->transcoder = new TransCoder($audioContext);
    }

    /**
     * Encode an audio frame
     *
     * @param FrameInterface $frame Audio frame to encode
     * @param bool $useKeyframe Ignored for audio (interface compatibility)
     * @return array|string [packets, pts] Array of encoded packets and presentation timestamp
     * @throws RuntimeException If frame validation fails
     */
    public function encode(FrameInterface $frame, bool $useKeyframe = false): string|array
    {
        $this->validateFrame($frame);
        $packets = $this->transcoder->encode($frame);

        return [array_map(fn($pkt) => $pkt->getData(), $packets), $packets[0]->getPts()];
    }

    /**
     * Package encoded data for transport
     *
     * @param Packet $packet Encoded audio packet
     * @return array|string [packets, pts] Array containing packet data and converted timestamp
     */
    public function pack(Packet $packet): string|array
    {
        return [[$packet->getData()], $this->convertTimebase($packet->getPts(), (array)$packet->getTimeBase(), [1, 8000])];
    }

    /**
     * Validate input audio frame
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
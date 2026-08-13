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
use Webrtc\AVCodec\Codec;
use Webrtc\AVCodec\Context\AudioContext;
use Webrtc\AVCodec\Data\Packet;
use Webrtc\AVCodec\Exception\AvCodecException;
use Webrtc\AVCodec\TransCoder;
use Webrtc\Codecs\DecoderInterface;
use Webrtc\Codecs\JitterFrameInterface;

/**
 * PCM Audio Decoder Abstract Base Class
 *
 * Provides base functionality for decoding various audio codecs to PCM format.
 * Handles common PCM output configuration (8 kHz, mono, signed 16-bit).
 *
 * @package Webrtc\Codecs\Audio\PCM
 */
abstract class PCMDecoder implements DecoderInterface
{
    /**
     * @var int SAMPLE_RATE Output sample rate (8kHz)
     */
    private const SAMPLE_RATE = 8000;

    /**
     * @var TransCoder|null $transcoder Audio transcoder instance
     */
    private ?TransCoder $transcoder;

    /**
     * Constructor
     *
     * @param string $codecName Name of the audio codec to decode
     * @throws AvCodecException
     */
    public function __construct(string $codecName)
    {
        AVCodec::init();

        $audioContext = AudioContext::create(new Codec($codecName));
        $audioContext->setFormat("s16");
        $audioContext->setLayout("mono");
        $audioContext->setSampleRate(self::SAMPLE_RATE);
        $this->transcoder = new TransCoder($audioContext);
    }

    /**
     * Decode an audio packet to PCM frames
     *
     * @param JitterFrameInterface $frame Input frame containing encoded audio and timestamp
     * @return array Array of decoded AudioFrame objects
     */
    public function decode(JitterFrameInterface $frame): array
    {
        $packet = new Packet();
        $packet->putData($frame->getData());
        $packet->setPts($frame->getTimestamp());
        $packet->setTimeBase(1, 8000);

        return $this->transcoder->decode($packet);
    }
}
<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Codecs\Video\X264;

use Throwable;
use Webrtc\AVCodec\AVCodec;
use Webrtc\AVCodec\Codec;
use Webrtc\AVCodec\Context\VideoContext;
use Webrtc\AVCodec\Data\Packet;
use Webrtc\AVCodec\Exception\AvCodecException;
use Webrtc\AVCodec\TransCoder;
use Webrtc\Codecs\DecoderInterface;
use Webrtc\Codecs\JitterFrameInterface;

/**
 * H.264 Video Decoder Class
 *
 * Implements decoding of H.264/AVC video streams for WebRTC applications.
 * Provides efficient frame-by-frame decoding with proper timestamp handling.
 *
 * @package Webrtc\Codecs\Video\X264
 */
class H264Decoder implements DecoderInterface
{
    /**
     * @var TransCoder $transcoder H.264 transcoder instance
     */
    private TransCoder $transcoder;

    /**
     * Constructor
     *
     * Initializes the H.264 decoder with:
     * - AVCodec library
     * - H.264 codec context in read mode
     * - Default decoder configuration
     * @throws AvCodecException
     */
    public function __construct()
    {
        AVCodec::init();
        $this->transcoder = new TransCoder(VideoContext::create(new Codec("h264")));
    }

    /**
     * Decodes an H.264 encoded frame
     *
     * @param JitterFrameInterface $frame Input frame containing:
     *                          - Data: Encoded H.264 payload
     *                          - timestamp: Presentation timestamp
     * @return array Array of decoded VideoFrame objects
     *               Empty array on decoding failure
     */
    public function decode(JitterFrameInterface $frame): array
    {
        try {
            $packet = new Packet();
            $packet->putData($frame->getData());
            $packet->setPts($frame->getTimestamp()); // Set presentation timestamp
            $packet->setTimeBase(1, 90000);   // Set time base

            return $this->transcoder->decode($packet);
        } catch (Throwable) {
            return [];
        }
    }
}


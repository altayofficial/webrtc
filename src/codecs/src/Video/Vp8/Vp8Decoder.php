<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Codecs\Video\Vp8;

use FFI\CData;
use Webrtc\AVCodec\AVCodec;
use Webrtc\AVCodec\Exception\AvCodecException;
use Webrtc\AVCodec\Frame\VideoFrame;
use Webrtc\Codecs\DecoderInterface;
use Webrtc\Codecs\JitterFrameInterface;
use Webrtc\VPX\Context;
use Webrtc\VPX\Decoder;
use Webrtc\VPX\Enum\BriefInterface;
use Webrtc\VPX\Exception\VpxException;
use Webrtc\VPX\Vpx;

/**
 * VP8 Video Decoder Class
 *
 * Implements real-time decoding of VP8-encoded video frames using libvpx.
 * Provides efficient frame-by-frame decoding suitable for WebRTC applications.
 *
 * @package Webrtc\Codecs\Video\Vp8
 */
class Vp8Decoder implements DecoderInterface
{
    /**
     * @var Decoder $decoder VPX decoder instance
     */
    private Decoder $decoder;

    /**
     * Constructor
     *
     * Initializes required libraries:
     * - libvpx (VP8/VP9 codec library)
     * - AVCodec (FFmpeg codec infrastructure)
     * Creates a new VP8 decoder instance with default configuration
     * @throws AvCodecException
     * @throws VpxException
     */
    public function __construct()
    {
        Vpx::init();
        AVCodec::init();
        $this->decoder = new Decoder(new Context, BriefInterface::VP8Decoder);
    }

    /**
     * Decodes a VP8-encoded video frame
     *
     * @param JitterFrameInterface $frame Input frame containing VP8 payload and timestamp
     * @return VideoFrame[] Array of decoded video frames
     * @throws VpxException
     * @throws AvCodecException
     */
    public function decode(JitterFrameInterface $frame): array
    {
        $frames = [];
        $images = $this->decoder->decode($frame->getData());

        foreach ($images as $image) {
            $frames [] = $this->generateFrame($image, $frame);
        }

        return $frames;
    }

    /**
     * Creates a VideoFrame from decoded image data
     *
     * @param CData $image Decoded image data from libvpx
     * @param JitterFrameInterface $frame Source frame with timestamp
     * @return VideoFrame Configured video frame object
     * @throws AvCodecException
     */
    private function generateFrame(CData $image, JitterFrameInterface $frame): VideoFrame
    {
        $videoFrame = new VideoFrame($image->d_w, $image->d_h);
        $videoFrame->setPts($frame->getTimestamp());
        $videoFrame->setTimeBase(1, 90000);

        for ($p = 0; $p < 3; $p++) {
            $videoFrame->getFrame()->data[$p] = $image->planes[$p];
            $videoFrame->getFrame()->linesize[$p] = $image->stride[$p];
        }

        return $videoFrame;
    }
}
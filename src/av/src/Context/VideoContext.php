<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec\Context;

use FFI\CData;
use Webrtc\AVCodec\Codec;
use Webrtc\AVCodec\Exception\AvCodecException;
use Webrtc\AVCodec\Format\VideoFormat;
use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\AVCodec\Frame\VideoFrame;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;

/**
 * Video Context Class
 *
 * Extends the base Context class with video-specific functionality.
 * Handles video codec configuration, frame preparation, and video format management.
 *
 * @package Webrtc\AVCodec\Context
 */
class VideoContext extends Context
{
    /**
     * @var float
     */
    private const AV_NOPTS_VALUE = -9223372036854775808;
    /**
     * @var VideoFormat|null $format Current video format configuration
     */
    private ?VideoFormat $format;

    /**
     * Constructor
     *
     * @param CData $context FFI CData representing AVCodecContext
     * @param Codec $codec Associated codec instance
     */
    public function __construct(CData $context, Codec $codec)
    {
        parent::__construct($context, $codec);
    }

    /**
     * Get the current video format
     *
     * @return VideoFormat Current video format
     */
    public function getFormat(): VideoFormat
    {
        return $this->format;
    }

    /**
     * Set video format
     *
     * Updates a pixel format, width, and height in one operation
     *
     * @param VideoFormat $format Video format to set
     */
    public function setFormat(VideoFormat $format): void
    {
        $this->context->pix_fmt = $format->getFormat();
        $this->context->width = $format->getWidth();
        $this->context->height = $format->getHeight();

        $this->format = $format;
    }

    /**
     * Get video width
     *
     * @return int Current width in pixels
     */
    public function getWidth(): int
    {
        return $this->context->width;
    }

    /**
     * Set video width
     *
     * @param int $width New width in pixels
     */
    public function setWidth(int $width): void
    {
        $this->context->width = $width;
    }

    /**
     * Get video height
     *
     * @return int Current height in pixels
     */
    public function getHeight(): int
    {
        return $this->context->height;
    }

    /**
     * Set video height
     *
     * @param int $height New height in pixels
     */
    public function setHeight(int $height): void
    {
        $this->context->height = $height;
    }

    /**
     * Get pixel format name
     *
     * @return string|null Name of current pixel format
     */
    public function getPixFormat(): ?string
    {
        return $this->format->getName();
    }

    /**
     * Set pixel format by name
     *
     * @param string $pixFormat Name of pixel format to set
     */
    public function setPixFormat(string $pixFormat): void
    {
        $this->context->pix_fmt = VideoFormat::getPixFmt($pixFormat);
        $this->format = new VideoFormat($this->context->pix_fmt, $this->context->width, $this->context->height);
    }

    /**
     * Set framerate
     *
     * @param int $numerator Framerate numerator
     * @param int $denominator Framerate denominator
     */
    public function setFramerate(int $numerator, int $denominator): void
    {
        $this->context->framerate->num = $numerator;
        $this->context->framerate->den = $denominator;
    }

    /**
     * Get framerate
     *
     * @return CData FFI CData representing the framerate
     */
    public function getFramerate(): CData
    {
        return $this->context->framerate;
    }

    /**
     * Prepare frames for encoding
     *
     * @param FrameInterface|null $frame Frame to prepare
     * @return array Array of prepared frames
     * @throws InvalidArgumentException If a format isn't set
     */
    public function prepareFramesForEncode(?FrameInterface $frame): array
    {
        if (!$this->format) {
            throw new InvalidArgumentException("The format has not been set yet");
        }

        if ($frame && $frame->getFrame()->pts <= self::AV_NOPTS_VALUE) {
            $frame->getFrame()->pts = $this->encodedFrameCount;
        }

        $this->encodedFrameCount += 1;

        return [$frame];
    }

    /**
     * Create a new empty video frame
     *
     * @return FrameInterface New video frame instance
     * @throws AvCodecException
     */
    public function getNewFrame(): FrameInterface
    {
        return new VideoFrame(0, 0);
    }

    /**
     * Get GOP (Group of Pictures) size
     *
     * @return int Number of frames between keyframes
     * @throws RuntimeException If accessed in decoder mode
     */
    public function getGopSize(): int
    {
        if ($this->codec->isDecoder()) {
            throw new RuntimeException("Cannot access 'gop_size' as a decoder");
        }

        return $this->context->gop_size;
    }

    /**
     * Set GOP (Group of Pictures) size
     *
     * @param int $value Number of frames between keyframes
     * @throws RuntimeException If modified in decoder mode
     */
    public function setGopSize(int $value): void
    {
        if ($this->codec->isDecoder()) {
            throw new RuntimeException("Cannot modify 'gop_size' as a decoder");
        }

        $this->context->gop_size = $value;
    }
}

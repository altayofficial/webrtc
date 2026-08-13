<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec\Frame;

use FFI;
use Webrtc\AVCodec\Enum\ColorRange;
use Webrtc\AVCodec\Enum\Colorspace;
use Webrtc\AVCodec\Enum\Interpolation;
use Webrtc\AVCodec\Format\VideoFormat;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Mixin\SharedLibraryInterface;

/**
 * VideoFrameReformater Class
 *
 * This class handles reformatting of video frames using FFI to interface with the SWScale library.
 * It provides functionality to change video frame attributes such as dimensions, format, colorspace,
 * color range, and interpolation method.
 *
 * @package Webrtc\AVCodec
 */
class VideoFrameReformater implements SharedLibraryInterface
{
    /**
     * @var mixed SWScale context
     */
    private $ctx;

    /**
     * @var FFI SWScale library interface
     */
    private $libSWScale;

    /**
     * Constructor for VideoFrameReformater
     *
     * Initializes the shared library interface
     */
    public function __construct()
    {
        $this->initiateSharedLibrary();
    }

    /**
     * Destructor for VideoFrameReformater
     *
     * Frees the SWScale context when the object is destroyed
     */
    public function __destruct()
    {
        $this->libSWScale->sws_freeContext($this->ctx);
    }

    /**
     * Resolves the value of an enum parameter
     *
     * Returns the enum value if the parameter is an instance of the specified enum class,
     * otherwise returns the provided value or default value.
     *
     * @param mixed $value The input value to resolve
     * @param string $enumClass The expected enum class name
     * @param int $default The default value to use if $value is null
     *
     * @return int The resolved integer value
     */
    private function resolveEnumValue($value, string $enumClass, int $default): int
    {
        return $value instanceof $enumClass ? $value->value : ($value ?? $default);
    }

    /**
     * Reformats a video frame
     *
     * Creates a new video frame with the specified parameters, or uses the original
     * frame's parameters where null is specified.
     *
     * @param VideoFrame $frame The source video frame
     * @param int|null $width New width (null for source width)
     * @param int|null $height New height (null for source height)
     * @param string|null $format New pixel format (null for source format)
     * @param Colorspace|null $srcColorspace Source colorspace
     * @param Colorspace|null $dstColorspace Destination colorspace
     * @param Interpolation|null $interpolation Scaling algorithm to use
     * @param ColorRange|null $srcColorRange Source color range
     * @param ColorRange|null $dstColorRange Destination color range
     *
     * @return VideoFrame The reformatted video frame
     */
    public function reformat(VideoFrame     $frame, ?int $width = null, ?int $height = null, ?string $format = null,
                             ?Colorspace    $srcColorspace = null, ?Colorspace $dstColorspace = null,
                             ?Interpolation $interpolation = null, ?ColorRange $srcColorRange = null,
                             ?ColorRange    $dstColorRange = null): VideoFrame
    {
        $videoFormat = new VideoFormat($format ?? $frame->getVideoFormat()->getFormat(), $width ?? $frame->getVideoFormat()->getWidth(), $height ?? $frame->getVideoFormat()->getHeight());

        $cSrcColorspace = $this->resolveEnumValue($srcColorspace, Colorspace::class, $frame->getColorspace());
        $cDstColorspace = $this->resolveEnumValue($dstColorspace, Colorspace::class, $frame->getColorspace());
        $cInterpolation = $this->resolveEnumValue($interpolation, Interpolation::class, Interpolation::BILINEAR->value);
        $cSrcColorRange = $this->resolveEnumValue($srcColorRange, ColorRange::class, 0);
        $cDstColorRange = $this->resolveEnumValue($dstColorRange, ColorRange::class, 0);

        return $this->createNewFrame(
            $frame,
            $width ?? $frame->getFrame()->width,
            $height ?? $frame->getFrame()->height,
            $videoFormat,
            $cSrcColorspace,
            $cDstColorspace,
            $cInterpolation,
            $cSrcColorRange,
            $cDstColorRange
        );
    }

    /**
     * Creates a new video frame with the specified parameters
     *
     * This method performs the actual frame reformatting using SWScale functions.
     * If the source and destination parameters are identical, it returns the original frame.
     *
     * @param VideoFrame $frame The source video frame
     * @param int $width Target width
     * @param int $height Target height
     * @param VideoFormat $dstFormat Target video format
     * @param int $srcColorspace Source colorspace value
     * @param int $dstColorspace Destination colorspace value
     * @param int $interpolation Scaling algorithm to use
     * @param int $srcColorRange Source color range value
     * @param int $dstColorRange Destination color range value
     *
     * @return VideoFrame The new video frame
     * @throws InvalidArgumentException If the source frame doesn't have a format set
     */
    private function createNewFrame(VideoFrame $frame, int $width, int $height, VideoFormat $dstFormat,
                                    int        $srcColorspace, int $dstColorspace, int $interpolation,
                                    int        $srcColorRange, int $dstColorRange): VideoFrame
    {

        if ($frame->getFrame()->format < 0) {
            throw new InvalidArgumentException("Frame does not have format set.");
        }

        $srcColorRange = ($srcColorRange === ColorRange::JPEG->value) ? 1 : 0;
        $dstColorRange = ($dstColorRange === ColorRange::JPEG->value) ? 1 : 0;

        $srcFormat = $frame->getVideoFormat()->getFormat();

        if ($dstFormat->getFormat() === $srcFormat && $width === $frame->getFrame()->width &&
            $height === $frame->getFrame()->height && $dstColorspace === $srcColorspace &&
            $srcColorRange === $dstColorRange) {
            return $frame;
        }

        $this->ctx = $this->libSWScale->sws_getCachedContext(
            $this->ctx,
            $frame->getFrame()->width,
            $frame->getFrame()->height,
            $srcFormat,
            $width,
            $height,
            $dstFormat->getFormat(),
            $interpolation,
            null,
            null,
            null
        );

        if ($srcColorspace !== $dstColorspace || $srcColorRange !== $dstColorRange) {
            $invTbl = $this->libSWScale->sws_getCoefficients($srcColorspace);
            $tbl = $this->libSWScale->sws_getCoefficients($dstColorspace);

            $this->libSWScale->sws_setColorspaceDetails(
                $this->ctx,
                $invTbl,
                $srcColorRange,
                $tbl,
                $dstColorRange,
                0, 0, 0
            );
        }

        $newFrame = new VideoFrame($width, $height);
        $newFrame->setVideoFormat($dstFormat);
        $newFrame->setPts($frame->getPts());
        $newFrame->setTimeBase($frame->getTimeBase()->num, $frame->getTimeBase()->den);

        $this->libSWScale->sws_scale(
            $this->ctx,
            $frame->getFrame()->data,
            $frame->getFrame()->linesize,
            0,
            $frame->getFrame()->height,
            $newFrame->getFrame()->data,
            $newFrame->getFrame()->linesize
        );

        return $newFrame;
    }

    /**
     * Initializes the SWScale shared library
     *
     * This method implements the SharedLibraryInterface requirement.
     * It uses a global FFI instance of the SWScale library if available.
     *
     * @return void
     */
    public function initiateSharedLibrary(): void
    {
        global $libSWScale;
        if ($libSWScale instanceof FFI) {
            $this->libSWScale = $libSWScale;
        }
    }
}
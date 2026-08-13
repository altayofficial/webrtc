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
use FFI\CData;
use Webrtc\AVCodec\Data\VideoPlane;
use Webrtc\AVCodec\Enum\ColorRange;
use Webrtc\AVCodec\Enum\Colorspace;
use Webrtc\AVCodec\Enum\Interpolation;
use Webrtc\AVCodec\Enum\PictureType;
use Webrtc\AVCodec\Exception\AvCodecException;
use Webrtc\AVCodec\Format\VideoFormat;

/**
 * Video Frame Class
 *
 * Represents a video frame in FFmpeg/AVCodec context, handling allocation,
 * storage, and manipulation of video frame data including planes, formats,
 * and frame attributes.
 *
 * @package Webrtc\AVCodec\Frame
 */
class VideoFrame extends Frame
{
    /**
     * @var VideoFormat $videFormat Video format information
     */
    private VideoFormat $videFormat;

    /**
     * @var CData|null $data FFI data buffer
     */
    private ?CData $data = null;

    /**
     * @var CData|null $lineSize FFI line size buffer
     */
    private ?CData $lineSize = null;

    /**
     * @var VideoFrameReformater|null $reformater Frame reformatter instance
     */
    private ?VideoFrameReformater $reformater = null;

    /**
     * Constructor
     *
     * @param int $width Frame width in pixels
     * @param int $height Frame height in pixels
     * @param string|int $format Pixel format (name or enum)
     * @param CData|null $frame Existing FFI AVFrame to wrap
     * @throws AvCodecException If allocation fails
     */
    public function __construct(int $width = 0, int $height = 0, string|int $format = "yuv420p", ?CData $frame = null)
    {
        if ($frame) {
            $this->initiateSharedLibrary();
            $this->timeBase = new \stdClass();
            $this->frame = $frame;
            $this->setVideoFormat();
            return;
        }

        parent::__construct();

        $this->frame->width = $width;
        $this->frame->height = $height;
        $this->frame->format = is_string($format) ? $this->getFormat($format) : $format;

        $this->data = $this->libAVCodec->new('uint8_t*[8]', false);  // Prevent automatic garbage collection
        $this->lineSize = $this->libAVCodec->new('int[8]', false);

        if ($width > 0 && $height > 0) {
            $result = $this->libAVCodec->av_image_alloc(
                $this->data,
                $this->lineSize,
                $width,
                $height,
                $this->frame->format,
                16
            );

            if ($result < 0) {
                throw new AvCodecException("Failed to allocate image.");
            }

            $this->frame->data = $this->data;
            $this->frame->linesize = $this->lineSize;
        }

        $this->setVideoFormat();
    }

    /**
     * Get pixel format enum from name
     *
     * @param string $formatName Pixel format name
     * @return int Pixel format enum
     * @throws AvCodecException If format is invalid
     */
    private function getFormat(string $formatName): int
    {
        $format = $this->libAVCodec->av_get_pix_fmt($formatName);
        if ($format == $this->libAVCodec->AV_PIX_FMT_NONE) {
            throw new AvCodecException(sprintf("Invalid format: %s", $formatName));
        }
        return $format;
    }

    /**
     * Set video format
     *
     * @param VideoFormat|null $videoFormat VideoFormat instance or null to create from frame
     */
    public function setVideoFormat(?VideoFormat $videoFormat = null): void
    {
        $this->videFormat = $videoFormat ?? new VideoFormat($this->frame->format, $this->frame->width, $this->frame->height);
    }

    /**
     * Get video format
     *
     * @return VideoFormat Current video format
     */
    public function getVideoFormat(): VideoFormat
    {
        return $this->videFormat;
    }

    /**
     * Iterate through video planes
     *
     * @return \Generator<VideoPlane> Generator yielding VideoPlane objects
     */
    public function planes(): \Generator
    {
        $planeCount = 0;
        $maxPlaneCount = 2;

        if ($this->videFormat->getName() == "pal8") {
            $maxPlaneCount = 2;
        } else {
            $videoFormatComponentsCount = $this->videFormat->getVideoFormat()->nb_components;
            for ($i = 0; $i < $videoFormatComponentsCount; $i++) {
                $maxPlaneCount = max($this->videFormat->getVideoFormat()->comp[$i]->plane + 1, $maxPlaneCount);
            }
        }

        // TODO: check this line later
//        $this->frame->extended_data = $this->libAVCodec->cast('uint8_t**', $this->frame->data);
        while ($planeCount < $maxPlaneCount && !FFI::isNull($this->frame->data[$planeCount])) {
            yield new VideoPlane($this, $planeCount++);
        }
    }

    /**
     * Set picture type
     *
     * @param PictureType $type Picture type enum
     */
    public function setPictureType(PictureType $type): void
    {
        $this->frame->pict_type = $type->value;
    }

    /**
     * Get picture type
     *
     * @return PictureType|null Picture type enum or null if not set
     */
    public function getPictureType(): ?PictureType
    {
        return PictureType::tryFrom($this->frame->pict_type);
    }

    /**
     * Destructor - frees allocated memory
     */
    public function __destruct()
    {
        if ($this->data) {
            $this->libAVCodec->av_freep(FFI::addr($this->data));
            $this->data = null;
        }

        if ($this->lineSize) {
            FFI::free($this->lineSize);
            $this->lineSize = null;
        }

        parent::__destruct();
    }

    public function initializeUserAttributes()
    {
        // TODO:
    }

    /**
     * Get raw picture type value
     *
     * @return int FFmpeg picture type value
     */
    public function getPictType(): int
    {
        return $this->frame->pict_type;
    }

    /**
     * Set raw picture type value
     *
     * @param int $value FFmpeg picture type value
     */
    public function setPictType(int $value): void
    {
        $this->frame->pict_type = $value;
    }

    /**
     * Get colorspace
     *
     * @return mixed Current colorspace value
     */
    public function getColorspace(): mixed
    {
        return $this->frame->colorspace;
    }

    /**
     * Set colorspace
     *
     * @param mixed $value Colorspace value to set
     */
    public function setColorspace($value): void
    {
        $this->frame->colorspace = $value;
    }

    /**
     * Get color range
     *
     * @return mixed Current color range value
     */
    public function getColorRange()
    {
        return $this->frame->color_range;
    }

    /**
     * Set color range
     *
     * @param mixed $value Color range value to set
     */
    public function setColorRange($value): void
    {
        $this->frame->color_range = $value;
    }

    /**
     * Reformat video frame
     *
     * @param int|null $width New width
     * @param int|null $height New height
     * @param string|null $format New pixel format
     * @param Colorspace|null $srcColorspace Source colorspace
     * @param Colorspace|null $dstColorspace Destination colorspace
     * @param Interpolation|null $interpolation Interpolation method
     * @param ColorRange|null $srcColorRange Source color range
     * @param ColorRange|null $dstColorRange Destination color range
     * @return VideoFrame Reformatted video frame
     */
    public function reformat(?int           $width = null, ?int $height = null, ?string $format = null,
                             ?Colorspace    $srcColorspace = null, ?Colorspace $dstColorspace = null,
                             ?Interpolation $interpolation = null, ?ColorRange $srcColorRange = null,
                             ?ColorRange    $dstColorRange = null): VideoFrame
    {
        if ($this->reformater === null) {
            $this->reformater = new VideoFrameReformater();
        }
        return $this->reformater->reformat(
            $this,
            $width,
            $height,
            $format,
            $srcColorspace,
            $dstColorspace,
            $interpolation,
            $srcColorRange,
            $dstColorRange
        );
    }


}

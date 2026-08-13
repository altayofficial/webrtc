<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec\Format;

use FFI;
use FFI\CData;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Mixin\SharedLibraryInterface;

/**
 * Video Format Class
 *
 * Represents a video pixel format and its properties, providing utilities
 * for working with different video formats in FFmpeg/AVCodec.
 *
 * @package Webrtc\AVCodec\Format
 */
class VideoFormat implements SharedLibraryInterface
{
    /**
     * @var FFI $libAVCodec FFI instance for AVCodec library
     */
    private FFI $libAVCodec;

    /**
     * @var CData|null $videoFormat FFI CData representing AVPixFmtDescriptor
     */
    private ?CData $videoFormat;

    /**
     * @var array $components Array of VideoFormatComponent objects
     */
    private array $components;

    /**
     * Constructor
     *
     * @param int|string|null $format Pixel format (enum value or name string)
     * @param int $width Frame width
     * @param int $height Frame height
     */
    public function __construct(private int|string|null $format,
                                private int             $width,
                                private int             $height,
    )
    {
        $this->initiateSharedLibrary();
        $this->format = is_string($this->format) ? self::getPixFmt($this->format) : $this->format;
        $this->videoFormat = $this->libAVCodec->av_pix_fmt_desc_get($this->format);
        $this->setVideoFormatComponents();
    }

    /**
     * Initialize format components
     */
    private function setVideoFormatComponents(): void
    {
        for ($i = 0; $i < $this->videoFormat->nb_components; $i++) {
            $this->components [] = new VideoFormatComponent($this, $i);
        }

    }

    /**
     * Get raw FFI video format descriptor
     *
     * @return CData AVPixFmtDescriptor structure
     */
    public function getVideoFormat(): CData
    {
        return $this->videoFormat;
    }

    /**
     * Get format components
     *
     * @return array<VideoFormatComponent> Array of video components
     */
    public function getComponents(): array
    {
        return $this->components;
    }

    /**
     * Get format name
     *
     * @return string Format name (e.g. "yuv420p")
     */
    public function getName(): string
    {
        return $this->videoFormat->name;
    }

    /**
     * Initialize shared library instance
     */
    public function initiateSharedLibrary(): void
    {
        global $libAVCodec;

        if ($libAVCodec instanceof FFI) {
            $this->libAVCodec = $libAVCodec;
        }
    }

    /**
     * Calculate chroma width
     *
     * @param int $lumaWidth Optional luma width (defaults to frame width)
     * @return int Chroma width
     */
    public function chromaWidth(int $lumaWidth = 0): int
    {
        $lumaWidth = $lumaWidth > 0 ? $lumaWidth : $this->width;
        return $lumaWidth ? -((-$lumaWidth) >> $this->videoFormat->log2_chroma_w) : 0;
    }

    /**
     * Calculate chroma height
     *
     * @param int $lumaHeight Optional luma height (defaults to frame height)
     * @return int Chroma height
     */
    public function chromaHeight(int $lumaHeight = 0): int
    {
        $lumaHeight = $lumaHeight > 0 ? $lumaHeight : $this->height;
        return $lumaHeight ? -((-$lumaHeight) >> $this->videoFormat->log2_chroma_h) : 0;
    }

    /**
     * Get frame height
     *
     * @return int Height in pixels
     */
    public function getHeight(): int
    {
        return $this->height;
    }

    /**
     * Get pixel format enum value
     *
     * @return int FFmpeg pixel format enum
     */
    public function getFormat(): int
    {
        return $this->format;
    }

    /**
     * Get frame width
     *
     * @return int Width in pixels
     */
    public function getWidth(): int
    {
        return $this->width;
    }

    /**
     * Set frame width
     *
     * @param int $width New width in pixels
     */
    public function setWidth(int $width): void
    {
        $this->width = $width;
    }

    /**
     * Set frame height
     *
     * @param int $height New height in pixels
     */
    public function setHeight(int $height): void
    {
        $this->height = $height;
    }

    /**
     * Convert pixel format name to enum value
     *
     * @param string $name Pixel format name (e.g. "yuv420p")
     * @return int FFmpeg pixel format enum
     * @throws InvalidArgumentException If the format is invalid or library not initialized
     */
    public static function getPixFmt(string $name): int
    {
        global $libAVCodec;

        if (!$libAVCodec instanceof FFI) {
            throw new InvalidArgumentException('LibAVCodec must be initialized');
        }

        $pixFmt = $libAVCodec->av_get_pix_fmt($name);

        if ($pixFmt === $libAVCodec->AV_PIX_FMT_NONE) {
            throw new InvalidArgumentException("Not a valid pixel format: {$name}");
        }

        return $pixFmt;
    }

    /**
     * Check if format has palette
     *
     * @return bool True if the format uses color palette
     */
    public function hasPalette(): bool
    {
        return (bool)($this->videoFormat->flags & (1 << 1));
    }

    /**
     * Check if the format is bitstream
     *
     * @return bool True if format is bitstream
     */
    public function isBitStream(): bool
    {
        return (bool)($this->videoFormat->flags & (1 << 2));
    }

    /**
     * Check if the format is planar
     *
     * @return bool True if the format is planar
     */
    public function isPlanar(): bool
    {
        return (bool)($this->videoFormat->flags & (1 << 4));
    }

    /**
     * Check if the format is RGB
     *
     * @return bool True if the format is RGB
     */
    public function isRgb(): bool
    {
        return (bool)($this->videoFormat->flags & (1 << 5));
    }
}
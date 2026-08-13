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

use FFI\CData;

/**
 * Video Format Component Class
 *
 * Represents an individual component (color channel/plane) of a video format.
 * Provides detailed information about each component in a pixel format.
 *
 * @package Webrtc\AVCodec\Format
 */
class VideoFormatComponent
{
    /**
     * @var CData $component FFI CData representing AVComponentDescriptor
     */
    private CData $component;

    /**
     * Constructor
     *
     * @param VideoFormat $videoFormat Parent video format
     * @param int $index Component index
     */
    public function __construct(private readonly VideoFormat $videoFormat,
                                private readonly int         $index)
    {
        $this->component = $this->videoFormat->getVideoFormat()->comp[$this->index];
    }

    /**
     * Get parent video format
     *
     * @return VideoFormat Associated video format
     */
    public function getVideoFormat(): VideoFormat
    {
        return $this->videoFormat;
    }

    /**
     * Get component index
     *
     * @return int Component index (0-based)
     */
    public function getIndex(): int
    {
        return $this->index;
    }

    /**
     * Get raw FFI component descriptor
     *
     * @return CData AVComponentDescriptor structure
     */
    public function getComponent(): CData
    {
        return $this->component;
    }

    /**
     * Get plane index for this component
     *
     * @return int Plane index where this component is stored
     */
    public function getPlane(): int
    {
        return $this->component->plane;
    }

    /**
     * Get bit depth of component
     *
     * @return int Number of bits used per sample
     */
    public function getBits(): int
    {
        return $this->component->depth;
    }

    /**
     * Check if component is alpha channel
     *
     * @return bool True if component represents alpha/transparency
     */
    public function isAlpha(): bool
    {
        return ($this->index === 1 && $this->videoFormat->getVideoFormat()->nb_components === 2) ||
            ($this->index === 3 && $this->videoFormat->getVideoFormat()->nb_components === 4);
    }

    /**
     * Check if component is luma (Y) channel
     *
     * @return bool True if component represents luma
     */
    public function isLuma(): bool
    {
        return $this->index === 0 && (
                $this->videoFormat->getVideoFormat()->nb_components === 1 ||
                $this->videoFormat->getVideoFormat()->nb_components === 2 ||
                !$this->videoFormat->isRgb()
            );
    }

    /**
     * Check if component is chroma (U/V) channel
     *
     * @return bool True if component represents chroma
     */
    public function isChroma(): bool
    {
        return in_array($this->index, [1, 2]) &&
            ($this->videoFormat->getVideoFormat()->log2_chroma_w || $this->videoFormat->getVideoFormat()->log2_chroma_h);
    }

    /**
     * Get component width
     *
     * Accounts for chroma subsampling if component is chroma
     * @return int Width in pixels
     */
    public function getWidth(): int
    {
        return $this->isChroma() ? $this->videoFormat->chromaWidth() : $this->videoFormat->getWidth();
    }

    /**
     * Get component height
     *
     * Accounts for chroma subsampling if component is chroma
     * @return int Height in pixels
     */
    public function getHeight(): int
    {
        return $this->isChroma() ? $this->videoFormat->chromaHeight() : $this->videoFormat->getHeight();
    }
}
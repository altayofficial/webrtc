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
use Webrtc\AVCodec\Util\Util;
use Webrtc\Exception\RuntimeException;
use Webrtc\Mixin\SharedLibraryInterface;

/**
 * Frame Abstract Base Class
 *
 * Represents a media frame (audio/video) in the FFmpeg context.
 * Provides common frame operations and properties for all frame types.
 *
 * @package Webrtc\AVCodec\Frame
 */
abstract class Frame implements FrameInterface, SharedLibraryInterface
{
    /**
     * @var float
     */
    private const AV_NOPTS_VALUE = -9223372036854775808;

    /**
     * @var FFI|null $libAVCodec FFI instance for AVCodec library
     */
    protected ?FFI $libAVCodec;

    /**
     * @var CData|null $frame FFI CData representing AVFrame
     */
    protected ?CData $frame = null;

    /**
     * @var \stdClass $timeBase Time base for PTS calculations
     */
    protected \stdClass $timeBase;

    /**
     * Constructor - allocates a new AVFrame
     * @throws RuntimeException If frame allocation fails
     */
    public function __construct()
    {
        $this->initiateSharedLibrary();
        $this->timeBase = new \stdClass();
        $this->frame = $this->libAVCodec->av_frame_alloc();

        if ($this->frame === null) {
            throw new RuntimeException("Failed to allocate frame.");
        }
    }

    /**
     * Destructor - frees frame memory
     */
    public function __destruct()
    {
        $this->libAVCodec->av_frame_free(FFI::addr($this->frame));
        $this->frame = null;
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
     * Get presentation timestamp (PTS)
     *
     * @return int|null PTS value or null if not set (AV_NOPTS_VALUE)
     */
    public function getPts(): ?int
    {
        return $this->frame->pts == $this->libAVCodec->AV_NOPTS_VALUE ? null : $this->frame->pts;
    }

    /**
     * Set presentation timestamp (PTS)
     *
     * @param int|null $pts PTS value or null to set as AV_NOPTS_VALUE
     */
    public function setPts(?int $pts): void
    {
        $this->frame->pts = $pts ?? $this->libAVCodec->AV_NOPTS_VALUE;
    }

    /**
     * Get current time base
     *
     * @return \stdClass Object with num/den properties
     */
    public function getTimeBase(): \stdClass
    {
        return $this->timeBase;
    }

    /**
     * Set time base for timestamp calculations
     *
     * @param int $numerator Time base numerator
     * @param int $denominator Time base denominator
     */
    public function setTimeBase(int $numerator, int $denominator): void
    {
        $this->timeBase->num = $numerator;
        $this->timeBase->den = $denominator;
    }

    /**
     * Get raw FFI frame object
     *
     * @return CData The FFI CData AVFrame
     */
    public function getFrame(): CData
    {
        return $this->frame;
    }

    /**
     * Write data to frame buffer
     *
     * @param string $data Data to write
     * @param int $index Plane/buffer index
     */
    public function putData(string $data, int $index = 0): void
    {
        if (!FFI::isNull($this->frame->extended_data[$index])) {
            FFI::memcpy($this->frame->extended_data[$index], $data, strlen($data));
        }
    }

    /**
     * Rebase timestamps to new time base
     *
     * @param CData $dst Target time base (FFI AVRational)
     * @throws RuntimeException If target time base is zero
     */
    public function rebaseTime(CData $dst): void
    {
        if (!$dst->num) {
            throw new RuntimeException("Cannot rebase to zero time.");
        }

        if (!isset($this->timeBase->num)) {
            $this->timeBase->num = $dst->num;
            $this->timeBase->den = $dst->den;
            return;
        }

        if ($this->timeBase->num == $dst->num && $this->timeBase->den == $dst->den) {
            return;
        }

        if ($this->frame->pts <= self::AV_NOPTS_VALUE) {
            $this->frame->pts = Util::rescaleQ($this->frame->pts, $this->timeBase, $dst);
        }

        $this->timeBase->num = $dst->num;
        $this->timeBase->den = $dst->den;
    }

    /**
     * Check if frame is a keyframe
     *
     * @return bool True if frame is a keyframe
     */
    public function isKeyFrame(): bool
    {
        return (bool)($this->frame->flags & (1 << 1));
    }
}

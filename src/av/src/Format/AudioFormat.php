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
use Webrtc\AVCodec\Exception\AvCodecException;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;
use Webrtc\Mixin\SharedLibraryInterface;

/**
 * Class AudioFormat
 *
 * Represents an audio sample format and provides utility methods for handling
 * its properties such as name, byte size, a bit of depth, planar/packed format, and
 * container names. This class interacts with the AVCodec library via FFI.
 */
class AudioFormat implements SharedLibraryInterface
{
    /**
     * @var int The sample format retrieved from the AVCodec library.
     */
    private int $sampleFormat;

    /**
     * @var FFI The AVCodec shared library interface.
     */
    private FFI $libAVCodec;

    /**
     * AudioFormat constructor.
     *
     * @param string| int $format The name of the audio sample format.
     *
     * @throws RuntimeException If the provided format name is invalid.
     */
    public function __construct(string|int $format)
    {
        $this->initiateSharedLibrary();
        $this->sampleFormat = is_int($format) ? $format : $this->libAVCodec->av_get_sample_fmt($format);

        if ($this->sampleFormat < 0) {
            throw new RuntimeException("Invalid sample format: $format");
        }
    }

    /**
     * Get the name of the sample format.
     *
     * @return string The name of the sample format.
     */
    public function getName(): string
    {
        return $this->libAVCodec->av_get_sample_fmt_name($this->sampleFormat);
    }

    /**
     * Get the number of bytes per sample for the format.
     *
     * @return int The number of bytes per sample.
     */
    public function getBytes(): int
    {
        return $this->libAVCodec->av_get_bytes_per_sample($this->sampleFormat);
    }

    /**
     * Get the bit depth of the sample format.
     *
     * @return int The bit depth (bytes per sample * 8).
     */
    public function getBits(): int
    {
        return $this->getBytes() << 3; // Multiply by 8 using bitwise shift
    }

    /**
     * Determine if the sample format is planar.
     *
     * @return bool True if the format is planar, false otherwise.
     */
    public function isPlanar(): bool
    {
        return (bool)$this->libAVCodec->av_sample_fmt_is_planar($this->sampleFormat);
    }

    /**
     * Determine if the sample format is packed.
     *
     * @return bool True if the format is packed, false otherwise.
     */
    public function isPacked(): bool
    {
        return !$this->isPlanar();
    }

    /**
     * Get the planar version of the sample format.
     *
     * @return self The planar format representation.
     */
    public function getPlanar(): self
    {
        if ($this->isPlanar()) {
            return $this;
        }
        $planarFormat = $this->libAVCodec->av_get_planar_sample_fmt($this->sampleFormat);
        return new self((int)$planarFormat);
    }

    /**
     * Get the packed version of the sample format.
     *
     * @return self The packed format representation.
     */
    public function getPacked(): self
    {
        if ($this->isPacked()) {
            return $this;
        }
        $packedFormat = $this->libAVCodec->av_get_packed_sample_fmt($this->sampleFormat);
        return new self($packedFormat);
    }

    /**
     * Get the container name for the sample format.
     *
     * @return string The container name.
     *
     * @throws InvalidArgumentException If the format is planar.
     * @throws AvCodecException If the format layout is unknown.
     */
    public function getContainerName(): string
    {
        if ($this->isPlanar()) {
            throw new InvalidArgumentException("Planar formats do not have container names.");
        }

        $containerFormatPostfix = (unpack('S', pack('v', 1))[1] === 1) ? "le" : "be";

        return match ($this->sampleFormat) {
            $this->libAVCodec->AV_SAMPLE_FMT_U8 => "u8",
            $this->libAVCodec->AV_SAMPLE_FMT_S16 => "s16$containerFormatPostfix",
            $this->libAVCodec->AV_SAMPLE_FMT_S32 => "s32$containerFormatPostfix",
            $this->libAVCodec->AV_SAMPLE_FMT_FLT => "f32$containerFormatPostfix",
            $this->libAVCodec->AV_SAMPLE_FMT_DBL => "f64$containerFormatPostfix",
            default => throw new AvCodecException("Unknown sample format layout."),
        };
    }

    /**
     * Load the shared library instance for AVCodec.
     *
     * @return void
     *
     * @throws RuntimeException If the shared library is not initialized.
     */
    public function initiateSharedLibrary(): void
    {
        global $libAVCodec;

        if (!$libAVCodec instanceof FFI) {
            throw new RuntimeException("Failed to load AVCodec shared library.");
        }

        $this->libAVCodec = $libAVCodec;
    }

    /**
     * @return int
     */
    public function getSampleFormat(): int
    {
        return $this->sampleFormat;
    }
}

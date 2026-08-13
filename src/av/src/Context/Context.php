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

use FFI;
use FFI\CData;
use Webrtc\AVCodec\Codec;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;
use Webrtc\Mixin\SharedLibraryInterface;

/**
 * AVCodec Context Class
 *
 * This class provides an abstraction layer for AVCodecContext using PHP FFI.
 * It handles the creation, configuration, and management of codec contexts
 * for both encoding and decoding operations.
 *
 * @package Webrtc\AVCodec\Context
 */
abstract class Context implements SharedLibraryInterface, ContextInterface
{
    /**
     * @var FFI $libAVCodec FFI instance for AVCodec library
     */
    protected FFI $libAVCodec;

    /**
     * @var array $options Codec context options
     */
    private array $options = [];

    /**
     * @var bool $extraDataSet Flag indicating if extradata is set
     */
    private bool $extraDataSet = false;

    /**
     * @var int $encodedFrameCount Counter for encoded frames
     */
    protected int $encodedFrameCount = 0;

    /**
     * Constructor
     *
     * @param CData $context FFI CData representing AVCodecContext
     * @param Codec $codec Associated codec instance
     */
    public function __construct(protected CData $context, protected Codec $codec)
    {
        $this->initiateSharedLibrary();
        $this->context->thread_count = 0; // Use as many threads as there are CPUs
        $this->context->thread_type = 2; // Thread within a frame
    }

    /**
     * Create a new context instance
     *
     * @param Codec $codec The codec to create context for
     * @return Context|VideoContext|AudioContext|null The created context instance
     */
    public static function create(Codec $codec): Context|VideoContext|AudioContext|null
    {
        global $libAVCodec;

        if ($libAVCodec instanceof FFI) {
            $ctx = $libAVCodec->avcodec_alloc_context3($codec->getCodec());
            return new static($ctx, $codec);
        }

        return null;
    }

    /**
     * Open the codec context
     *
     * @param bool $strict Whether to throw exceptions on errors
     * @throws RuntimeException If context is already open (when strict=true)
     * @throws RuntimeException If opening context fails
     */
    public function open(bool $strict = true): void
    {
        if ($this->libAVCodec->avcodec_is_open($this->context)) {
            if ($strict) {
                throw new RuntimeException("Context is already open.");
            }
            return;
        }

        $dictionary = new Dictionary;
        $dictionary->update($this->options);

        if ($this->context->time_base->num < 0 && $this->isEncoder()) {
            $this->setDefaultTimeBase();
        }
        $res = $this->libAVCodec->avcodec_open2($this->context, $this->codec->getCodec(), FFI::addr($dictionary()));

        if ($res < 0) {
            $errorMsgBuffer = $this->libAVCodec->new("char[1024]");
            $this->libAVCodec->av_strerror($res, $errorMsgBuffer, 1024);
            $errorMessage = FFI::string($errorMsgBuffer);
            throw new RuntimeException("Couldn't open the context: " . $errorMessage);
        }

//        foreach ($dictionary as $key => $value) {
//            $this->options[FFI::string($key)] = FFI::string($value);
//        }
    }

    /**
     * Close the codec context
     *
     * @param bool $strict Whether to throw exceptions on errors
     * @throws InvalidArgumentException If context is already closed (when strict=true)
     * @throws RuntimeException If closing context fails
     */
    public function close($strict = true): void
    {
        if (!$this->libAVCodec->avcodec_is_open($this->context)) {
            if ($strict) {
                throw new \InvalidArgumentException("CodecContext is already closed.");
            }
            return;
        }
        $res = $this->libAVCodec->avcodec_free_context(FFI::addr($this->context));

        if ($res < 0) {
            throw new RuntimeException("Couldn't close the context");
        }
    }

    /**
     * Destructor - cleans up resources
     */
    public function __destruct()
    {
        if ($this->context && $this->extraDataSet) {
            $this->libAVCodec->av_freep(FFI::addr($this->context->extradata));
        }
        if ($this->context) {
            $this->libAVCodec->avcodec_free_context(FFI::addr($this->context));
        }
    }

    /**
     * Check if context is for encoding
     *
     * @return bool True if context is for encoding, false for decoding
     */
    public function isEncoder(): bool
    {
        return (bool)$this->libAVCodec->av_codec_is_encoder($this->context->codec);
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
     * Set default the time base for the context
     */
    private function setDefaultTimeBase(): void
    {
        $this->context->time_base->num = 1;
        $this->context->time_base->den = 1000000;
    }

    /**
     * Set the bitrate for the context
     *
     * @param int $bitrate The bitrate in bits per second
     */
    public function setBitRate(int $bitrate): void
    {
        $this->context->bit_rate = $bitrate;
    }

    /**
     * Get the current bitrate
     *
     * @return int The current bitrate in bits per second
     */
    public function getBitrate(): int
    {
        return $this->context->bit_rate;
    }

    /**
     * Set the time base for the context
     *
     * @param int $numerator Time base numerator
     * @param int $denominator Time base denominator
     * @throws RuntimeException If called on a decoder context
     */
    public function setTimeBase(int $numerator, int $denominator): void
    {
        if ($this->codec->isDecoder()) {
            throw new RuntimeException("Cannot access 'gop_size' as a decoder");
        }
        $this->context->time_base->num = $numerator;
        $this->context->time_base->den = $denominator;
    }

    /**
     * Get the current time base
     *
     * @return CData FFI CData representing the time base
     * @throws RuntimeException If called on a decoder context
     */
    public function getTimeBase(): CData
    {
        if ($this->codec->isDecoder()) {
            throw new RuntimeException("Cannot access 'gop_size' as a decoder");
        }

        return $this->context->time_base;
    }

    /**
     * Get all context options
     *
     * @return array Current context options
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Set context options
     *
     * @param array $options New options to set
     */
    public function setOptions(array $options): void
    {
        $this->options = array_merge($options, $this->options);
    }

    /**
     * Get the raw FFI context object
     *
     * @return CData The FFI CData AVCodecContext
     */
    public function getContext(): CData
    {
        return $this->context;
    }

    public function profile(int $profile): void
    {
        $this->context->profile = $profile;
    }

    /**
     * Get the current skip frame setting as a human-readable string
     *
     * Possible return values:
     * - "NONE"      Discard nothing
     * - "DEFAULT"   Discard useless packets like 0 size packets in AVI
     * - "NONREF"    Discard all non-reference frames
     * - "BIDIR"     Discard all bidirectional frames
     * - "NONINTRA"  Discard all non-intra frames
     * - "NONKEY"    Discard all frames except keyframes
     * - "ALL"       Discard all
     *
     * @return string Current skip frame setting
     */
    public function getSkipFrame(): string
    {
        $value = $this->context->skip_frame;

        return match ($value) {
            $this->libAVCodec->AVDISCARD_NONE => "NONE",
            $this->libAVCodec->AVDISCARD_DEFAULT => "DEFAULT",
            $this->libAVCodec->AVDISCARD_NONREF => "NONREF",
            $this->libAVCodec->AVDISCARD_BIDIR => "BIDIR",
            $this->libAVCodec->AVDISCARD_NONINTRA => "NONINTRA",
            $this->libAVCodec->AVDISCARD_NONKEY => "NONKEY",
            $this->libAVCodec->AVDISCARD_ALL => "ALL",
            default => (string)$value,
        };
    }

    /**
     * Get the codec tag as a 4-character string
     *
     * @return string The codec tag
     */
    public function getCodecTag(): string
    {
        return pack("V", $this->context->codec_tag);
    }

    /**
     * Set the codec tag
     *
     * @param string $value 4-character codec tag string
     * @throws InvalidArgumentException If tag is not 4 characters
     */
    public function setCodecTag(string $value): void
    {
        if (strlen($value) !== 4) {
            throw new InvalidArgumentException("Codec tag should be a 4-character string.");
        }

        $this->context->codec_tag = unpack("V", $value)[1];
    }

    /**
     * Get the size of extradata
     *
     * @return int Size of extradata in bytes
     */
    public function getExtradataSize(): int
    {
        return $this->context->extradata_size;
    }

    /**
     * Get the extradata
     *
     * @return string|null The extradata or null if not set
     */
    public function getExtradata(): ?string
    {
        if ($this->context->extradata === null) {
            return null;
        }

        if ($this->context->extradata_size > 0) {
            return FFI::string($this->context->extradata, $this->context->extradata_size);
        }

        return null;
    }

    /**
     * Set the extradata
     *
     * @param string|null $data The extradata to set or null to clear
     * @throws RuntimeException If memory allocation fails
     */
    public function setExtradata(?string $data): void
    {
        if ($data === null) {
            $this->libAVCodec->av_freep(FFI::addr($this->context->extradata));
            $this->context->extradata_size = 0;
        } else {
            $length = strlen($data);
            $newMemory = $this->libAVCodec->av_realloc(
                $this->context->extradata,
                $length + 64 // AV_INPUT_BUFFER_PADDING_SIZE
            );

            if ($newMemory === null) {
                throw new RuntimeException("Cannot allocate extradata");
            }

            $this->context->extradata = $newMemory;
            FFI::memcpy($this->context->extradata, $data, $length);
            $this->context->extradata_size = $length;
        }
    }
}

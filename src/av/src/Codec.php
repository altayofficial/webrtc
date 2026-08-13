<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec;

use FFI;
use stdClass;
use Webrtc\AVCodec\Format\AudioFormat;
use Webrtc\AVCodec\Format\VideoFormat;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Mixin\SharedLibraryInterface;

/**
 * Class Codec
 *
 * This class represents a codec and provides functionalities to interact with
 * the FFmpeg library for encoding or decoding media streams.
 *
 * Implements SharedLibraryInterface to ensure shared library access.
 */
class Codec implements SharedLibraryInterface
{
    /**
     * @var object Codec instance returned by FFmpeg.
     */
    private $codec;

    /**
     * @var object|null Descriptor for the codec.
     */
    private $desc;

    /**
     * @var bool Indicates if the codec is an encoder.
     */
    private $isEncoder;

    /**
     * @var FFI Reference to the FFmpeg shared library.
     */
    private FFI $libAVCodec;

    /**
     * Codec constructor.
     *
     * @param string $name The name of the codec.
     * @param string $mode The mode for the codec: "r" (decoder) or "w" (encoder).
     *
     * @throws InvalidArgumentException If the codec cannot be found or initialized.
     * @throws \InvalidArgumentException If the mode is invalid.
     */
    public function __construct(string $name, string $mode = "r")
    {
        $this->initiateSharedLibrary();
        $this->initializeCodec($name, $mode);
    }

    /**
     * Initializes the shared library reference.
     *
     * @throws InvalidArgumentException If the shared library is not initialized.
     */
    public function initiateSharedLibrary(): void
    {
        global $libAVCodec;
        if ($libAVCodec instanceof FFI) {
            $this->libAVCodec = $libAVCodec;
        } else {
            throw new InvalidArgumentException("Shared library not initialized.");
        }
    }

    /**
     * Initializes the codec by name and mode.
     *
     * @param string $name The name of the codec.
     * @param string $mode The mode for the codec: "r" (decoder) or "w" (encoder).
     *
     * @throws InvalidArgumentException If the codec cannot be found or the mode mismatches.
     * @throws InvalidArgumentException If the mode is invalid.
     */
    private function initializeCodec(string $name, string $mode): void
    {
        if (!in_array($mode, ["r", "w"], true)) {
            throw new InvalidArgumentException('Invalid mode; must be "r" or "w".');
        }

        $this->codec = $this->findCodecByName($name, $mode);
        if (!$this->codec) {
            throw new InvalidArgumentException("Unknown codec: $name");
        }

        $this->desc = $this->desc ?? $this->libAVCodec->avcodec_descriptor_get($this->codec->id);

        $this->isEncoder = $this->libAVCodec->av_codec_is_encoder($this->codec);

        if ($this->isEncoder && $this->libAVCodec->av_codec_is_decoder($this->codec)) {
            throw new InvalidArgumentException("{$this->codec->name} is both encoder and decoder.");
        }

        if (($mode == "w") != $this->isEncoder) {
            throw new InvalidArgumentException("Codec mode mismatch: $name, $mode.");
        }
    }

    /**
     * Finds a codec by its name and mode.
     *
     * @param string $name The name of the codec.
     * @param string $mode The mode for the codec: "r" (decoder) or "w" (encoder).
     *
     * @return object|null The codec instance, or null if not found.
     */
    private function findCodecByName(string $name, string $mode): ?object
    {
        $finder = $mode === "w" ? "avcodec_find_encoder_by_name" : "avcodec_find_decoder_by_name";
        $codec = $this->libAVCodec->$finder($name);

        if (!$codec) {
            $this->desc = $this->libAVCodec->avcodec_descriptor_get_by_name($name);
            if ($this->desc) {
                $finder = $mode === "w" ? "avcodec_find_encoder" : "avcodec_find_decoder";
                $codec = $this->libAVCodec->$finder($this->desc->id);
            }
        }

        return $codec;
    }

    /**
     * Checks if the codec is a decoder.
     *
     * @return bool True if the codec is a decoder; false otherwise.
     */
    public function isDecoder(): bool
    {
        return !$this->isEncoder;
    }

    /**
     * Checks if the codec is an encoder.
     *
     * @return bool True if the codec is a decoder; false otherwise.
     */
    public function isEncoder(): bool
    {
        return $this->isEncoder;
    }

    /**
     * Gets the name of the codec.
     *
     * @return string The codec name.
     */
    public function getName(): string
    {
        return $this->codec->name ?? "";
    }

    /**
     * Gets the long name of the codec.
     *
     * @return string The long name of the codec.
     */
    public function getLongName(): string
    {
        return $this->codec->long_name ?? "";
    }

    /**
     * Gets the type of media associated with the codec.
     *
     * @return string The media type.
     */
    public function getType(): string
    {
        return $this->libAVCodec->av_get_media_type_string($this->codec->type) ?? "";
    }

    /**
     * Gets the ID of the codec.
     *
     * @return int The codec ID.
     */
    public function getId(): int
    {
        return $this->codec->id;
    }

    /**
     * Gets the codec instance.
     *
     * @return object The codec instance.
     */
    public function getCodec()
    {
        return $this->codec;
    }

    /**
     *
     *
     * @return bool
     */
    public function getDelay(): bool
    {
        return boolval($this->codec->capabilities & (1 << 5));
    }

    /**
     * A list of supported VideoFormat, or null.
     *
     * @return array|null
     */
    public function getVideoFormats(): ?array
    {
        if ($this->codec->pix_fmts === null) {
            return null;
        }

        $ret = [];
        $i = 0;
        while ($this->codec->pix_fmts[$i] !== -1) {
            $ret[] = new VideoFormat($this->codec->pix_fmts[$i], 0, 0);
            $i++;
        }

        return $ret;
    }

    /**
     * A list of supported frame rates (as fractions), or null.
     *
     * @return array|null
     */
    public function getFrameRates(): ?array
    {
        if ($this->codec->supported_framerates === null) {
            return null;
        }

        $ret = [];
        $i = 0;
        while ($this->codec->supported_framerates[$i]->denum !== 0) {
            $frameRate = FFI::addr($this->codec->supported_framerates[$i]);
            $frameRateObj = new StdClass();
            $frameRateObj->num = $frameRate->num;
            $frameRateObj->den = $frameRate->den;
            $ret[] = $frameRateObj;
            $i++;
        }

        return $ret;
    }

    /**
     * A list of supported AudioFormat, or null.
     *
     * @return array|null
     */
    public function getAudioFormats(): ?array
    {
        if ($this->codec->sample_fmts === null) {
            return null;
        }

        $ret = [];
        $i = 0;
        while ($this->codec->sample_fmts[$i] !== -1) {
            $ret[] = new AudioFormat($this->codec->sample_fmts[$i]);
            $i++;
        }

        return $ret;
    }

    /**
     * A list of supported audio sample rates (int), or null.
     *
     * @return array|null
     */
    public function getAudioRates(): ?array
    {
        if ($this->codec->supported_samplerates === null) {
            return null;
        }

        $ret = [];
        $i = 0;
        while ($this->codec->supported_samplerates[$i] !== 0) {
            $ret[] = $this->codec->supported_samplerates[$i];
            $i++;
        }

        return $ret;
    }

    public static function getCodecNames(): array
    {
        global $libAVCodec;
        if (!$libAVCodec instanceof FFI) {
            throw new InvalidArgumentException("Shared library not initialized.");
        }

        $names = [];
        $opaque = $libAVCodec->new("void *");

        while (true) {
            $ptr = $libAVCodec->av_codec_iterate(FFI::addr($opaque));
            if ($ptr === null) {
                break;
            }
            $names[] = $ptr->name;
        }

        return array_unique($names);
    }

    /**
     * Check if codec is experimental and is thus avoided in favor of non-experimental encoders.
     *
     * @return bool
     */
    public function isExperimental(): bool
    {
        return (bool)($this->codec->capabilities & (1 << 9));
    }
}

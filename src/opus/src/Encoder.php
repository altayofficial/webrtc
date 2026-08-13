<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Opus;

use FFI;
use FFI\CData;
use Webrtc\Opus\Exception\OpusException;
use Webrtc\Mixin\SharedLibraryInterface;

/**
 * Class Encoder
 *
 * Provides an interface for encoding audio data using the Opus codec via FFI.
 * This class uses a shared native Opus library and allows encoding raw PCM audio to Opus.
 *
 * @deprecated
 */
class Encoder implements SharedLibraryInterface
{
    private FFI $libOpus;
    private CData $encoder;
    private CData $buffer;
    private int|float $bufferSize;

    /**
     * Encoder constructor.
     *
     * Initializes the Opus encoder with the given sample rate, channel count, and sample width.
     *
     * @param int $sampleRate The sample rate of the input audio (default: 48,000 Hz).
     * @param int $channels The number of audio channels (default: 2).
     * @param int $sampleWidth The byte size of each audio sample (default: 2).
     *
     * @throws OpusException If the encoder cannot be created.
     */
    public function __construct(int $sampleRate = 48000, private readonly int $channels = 2, int $sampleWidth = 2)
    {
        $this->initiateSharedLibrary();
        $errCode = $this->libOpus->new("int");
        $this->encoder = $this->libOpus->opus_encoder_create($sampleRate, $channels, OPUS_APPLICATION_VOIP, FFI::addr($errCode));
        $this->bufferSize = $sampleRate * $channels * $sampleWidth;
        $this->buffer = $this->libOpus->new("unsigned char [$this->bufferSize]");
        if ($errCode->cdata != 0) {
            throw new OpusException("Cannot create opus decoder.");
        }
    }

    /**
     * Destructor.
     *
     * Frees the native Opus encoder instance.
     */
    public function __destruct()
    {
        $this->libOpus->opus_encoder_destroy($this->encoder);
    }

    /**
     * Encodes raw PCM audio data into Opus format.
     *
     * @param string $data The raw PCM input audio data.
     * @param int $samplePerRate Number of samples per channel (default: 960).
     *
     * @return string The encoded Opus audio data.
     *
     * @throws OpusException If encoding fails.
     */
    public function encode(string $data, int $samplePerRate = 960): string
    {
        $decodedDataSize = $samplePerRate * $this->channels;
        $decodedData = $this->libOpus->new("opus_int16[$decodedDataSize]");
        FFI::memcpy($decodedData, $data, strlen($data));
        $sampleLength = $this->libOpus->opus_encode($this->encoder, $decodedData, $samplePerRate, $this->buffer, $this->bufferSize);

        if ($sampleLength <= 0) {
            throw new OpusException("Cannot decoded data");
        }

        return FFI::string($this->buffer, $sampleLength);
    }

    /**
     * Assigns the shared Opus library to the class if available in the global scope.
     *
     * This should be called before using any Opus functions.
     */
    public function initiateSharedLibrary(): void
    {
        global $libOpus;

        if ($libOpus instanceof FFI) {
            $this->libOpus = $libOpus;
        }
    }
}

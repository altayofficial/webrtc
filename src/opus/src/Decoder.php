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
use Webrtc\AVCodec\Frame\AudioFrame;
use Webrtc\Opus\Exception\OpusException;
use Webrtc\Mixin\SharedLibraryInterface;

/**
 * Class Decoder
 *
 * Provides an interface for decoding Opus-encoded audio data into raw PCM audio using FFI.
 * This class wraps the native Opus decoder functionality for use within PHP.
 *
 * @deprecated
 */
class Decoder implements SharedLibraryInterface
{
    private FFI $libOpus;
    private CData $decoder;

    /**
     * Decoder constructor.
     *
     * Initializes the Opus decoder with the given sample rate and channel count.
     *
     * @param int $sampleRate The sample rate of the input audio (default: 48,000 Hz).
     * @param int $channels The number of audio channels (default: 2).
     *
     * @throws OpusException If the decoder cannot be created.
     */
    public function __construct(int $sampleRate = 48000, private readonly int $channels = 2)
    {
        $this->initiateSharedLibrary();
        $errCode = $this->libOpus->new("int");
        $this->decoder = $this->libOpus->opus_decoder_create($sampleRate, $channels, FFI::addr($errCode));
        if ($errCode->cdata != 0) {
            throw new OpusException("Cannot create opus decoder.");
        }
    }

    /**
     * Decodes Opus-encoded audio data into raw PCM and writes it into the given audio frame.
     *
     * @param string $data The Opus-encoded input data.
     * @param AudioFrame $frame The target audio frame to receive the decoded PCM data.
     * @param int $samplePerRate Number of samples per channel expected (default: 960).
     *
     * @return int The number of samples decoded.
     *
     * @throws OpusException If decoding fails or the sample length is incorrect.
     */
    public function decode(string $data, AudioFrame $frame, int $samplePerRate = 960): int
    {
        $encodedDataLength = strlen($data);
        $encodedData = $this->libOpus->new("unsigned char[$encodedDataLength]");
        FFI::memcpy($encodedData, $data, $encodedDataLength);

        $decodedData = $this->libOpus->cast("opus_int16 *", $frame->getFrame()->extended_data[0]);

        $sampleLength = $this->libOpus->opus_decode($this->decoder, $encodedData, $encodedDataLength, $decodedData, $samplePerRate, 0);

        if ($sampleLength != $samplePerRate) {
            throw new OpusException("Cannot decoded data");
        }

        return $sampleLength;
    }

    /**
     * Destructor.
     *
     * Frees the native Opus decoder instance.
     */
    public function __destruct()
    {
        $this->libOpus->opus_decoder_destroy($this->decoder);
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

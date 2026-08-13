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

use FFI\CData;
use Webrtc\AVCodec\Audio\AudioLayout;
use Webrtc\AVCodec\Audio\AudioResampler;
use Webrtc\AVCodec\Codec;
use Webrtc\AVCodec\Exception\AvCodecException;
use Webrtc\AVCodec\Format\AudioFormat;
use Webrtc\AVCodec\Frame\AudioFrame;
use Webrtc\AVCodec\Frame\FrameInterface;


/**
 * Class AudioCodecContext
 *
 * Represents an audio codec context, providing functionality for audio encoding and decoding,
 * frame preparation, and audio property management. This class is an extension of the Context
 * class and integrates additional audio-specific features.
 *
 */
class AudioContext extends Context
{
    private ?AudioResampler $resampler = null;
    private AudioFormat $format;
    private AudioLayout $layout;

    /**
     * @param CData $context Pointer to the C AVCodec structure.
     * @param Codec $codec
     */
    public function __construct(CData $context, Codec $codec)
    {
        parent::__construct($context, $codec);
    }

    /**
     * Prepares audio frames for encoding.
     *
     * Resamples the input frame if needed, and flushes the resampler when the input frame is null.
     *
     * @param FrameInterface|AudioFrame|null $frame The input audio frame or null to flush the resampler.
     * @return array An array of resampled frames, or an array with a single null element if flushing.
     * @throws AvCodecException
     */
    public function prepareFramesForEncode(null|AudioFrame|FrameInterface $frame): array
    {
        $allowVarFrameSize = ($this->context->codec->capabilities & AV_CODEC_CAP_VARIABLE_FRAME_SIZE) !== 0;

        if ($this->resampler === null) {
            $this->resampler = new AudioResampler(
                $this->getFormat(),
                $this->getLayout()->getName(),
                $this->context->sample_rate,
                $allowVarFrameSize ? null : $this->context->frame_size,
            );
        }

        return $this->resampler->resample($frame);
    }

    /**
     * Gets the frame size.
     *
     * The number of samples per channel in an audio frame.
     *
     * @return int The frame size.
     */
    public function getFrameSize(): int
    {
        return $this->context->frame_size;
    }

    /**
     * Gets the sample rate of the audio data.
     *
     * @return int The sample rate in samples per second.
     */
    public function getSampleRate(): int
    {
        return $this->context->sample_rate;
    }

    /**
     * Sets the sample rate of the audio data.
     *
     * @param int $value The sample rate in samples per second.
     */
    public function setSampleRate(int $value): void
    {
        $this->context->sample_rate = $value;
    }

    /**
     * @return int The number of channels.
     */
    public function getChannels(): int
    {
        return $this->getLayout()->getNbChannels();
    }

    /**
     * @return AudioLayout The channel layout.
     */
    public function getLayout(): AudioLayout
    {
        return $this->layout;
    }

    /**
     * @param string $value The channel layout to set.
     */
    public function setLayout(string $value): void
    {
        $this->layout = new AudioLayout($value);
        $this->context->ch_layout = $this->layout->getLayout();
    }

    /**
     * @return AudioFormat The audio sample format.
     */
    public function getFormat(): AudioFormat
    {
        return $this->format;
    }

    /**
     * @param mixed $value The sample format to set.
     */
    public function setFormat(mixed $value): void
    {
        $this->format = new AudioFormat($value);
        $this->context->sample_fmt = $this->format->getSampleFormat();
    }

    /**
     * @return FrameInterface create a copy of this object
     */
    public function getNewFrame(): FrameInterface
    {
        return new AudioFrame($this->format->getName(), $this->layout->getName(), $this->getSampleRate());
    }

    /**
     * @param AudioResampler|null $resampler
     * @return void
     */
    public function setResampler(?AudioResampler $resampler): void
    {
        $this->resampler = $resampler;
    }
}
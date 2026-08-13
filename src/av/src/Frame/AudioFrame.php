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

use FFI\CData;
use Webrtc\AVCodec\Audio\AudioLayout;
use Webrtc\AVCodec\Data\AudioPlane;
use Webrtc\AVCodec\Format\AudioFormat;
use Webrtc\Exception\RuntimeException;

/**
 * A frame of audio data.
 */
class AudioFrame extends Frame
{
    private ?CData $buffer = null;
    private int $bufferSize;
    private AudioFormat $format;
    private AudioLayout $layout;

    /**
     * Initializes an AudioFrame instance.
     *
     * @param string|int $format The audio format (e.g., "s16").
     * @param string $layout The channel layout (e.g., "stereo").
     * @param int $samples The number of samples per channel.
     * @param int $align Alignment for the buffer.
     */
    public function __construct(string|int $format = "s16", string $layout = "stereo", int $samples = 0, int $align = 1)
    {
        parent::__construct();
        $this->format = new AudioFormat($format);
        $this->layout = new AudioLayout($layout);

        $this->initialize($samples, $align);
    }

    /**
     * Performs internal initialization of the audio frame.
     *
     * @param int $nbSamples The number of samples per channel.
     * @param int $align Alignment for the buffer.
     */
    private function initialize(int $nbSamples, int $align): void
    {
        $this->frame->nb_samples = $nbSamples;
        $this->frame->format = $this->format->getSampleFormat();
        $this->frame->ch_layout = $this->layout->getLayout();

//        $this->initializeUserAttributes();

        if ($this->layout->getNbChannels() !== 0 && $nbSamples > 0) {
            // Free the old buffer if any.
            if ($this->buffer !== null) {
                $this->libAVCodec->av_free($this->buffer);
            }

            // Allocate a new buffer.
            $this->bufferSize = $this->libAVCodec->av_samples_get_buffer_size(
                null,
                $this->layout->getNbChannels(),
                $nbSamples,
                $this->format->getSampleFormat(),
                $align
            );

            $this->buffer = $this->libAVCodec->av_malloc($this->bufferSize);
            if (!$this->buffer) {
                throw new RuntimeException("Cannot allocate AudioFrame buffer");
            }

            // Connect data pointers to the buffer.
            $ret = $this->libAVCodec->avcodec_fill_audio_frame(
                $this->frame,
                $this->layout->getNbChannels(),
                $this->format->getSampleFormat(),
                $this->buffer,
                $this->bufferSize,
                $align
            );

            if ($ret < 0) {
                throw new RuntimeException("Cannot fill audio frame");
            }
        }
    }

    /**
     * Frees the allocated buffer when the object is destroyed.
     */
    public function __destruct()
    {
        parent::__destruct();
        $this->libAVCodec->av_free($this->buffer);
    }

    /**
     * Initializes user-defined attributes such as layout and format.
     */
    public function initializeUserAttributes(): void
    {
//        var_dump($this->frame->format);
//        die;
        $this->layout = new AudioLayout($this->frame->ch_layout);
        $this->format = new AudioFormat($this->frame->format);
    }

    /**
     * Returns the audio planes of the frame.
     *
     * @return AudioPlane[]
     */
    public function getPlanes(): array
    {
        $planes = [];
        $i = 0;
//        var_dump($this->frame->extended_data[0]);
//        var_dump($this->frame->extended_data[1]);


        while ($this->frame->extended_data[$i]) {
            $planes[] = new AudioPlane($this, $i++);
        }

        return $planes;
    }

    /**
     * Gets the number of audio samples per channel.
     *
     * @return int
     */
    public function getSamples(): int
    {
        return $this->frame->nb_samples;
    }

    /**
     * Gets the sample rate of the audio data.
     *
     * @return int
     */
    public function getSampleRate(): int
    {
        return $this->frame->sample_rate;
    }

    /**
     * Sets the sample rate of the audio data.
     *
     * @param int $value The sample rate in Hz.
     */
    public function setSampleRate(int $value): void
    {
        $this->frame->sample_rate = $value;
    }

    public function getFormat(): AudioFormat
    {
        return $this->format;
    }

    public function getLayout(): AudioLayout
    {
        return $this->layout;
    }
}

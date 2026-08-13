<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec\Audio;

use Webrtc\AVCodec\Exception\AvCodecException;
use Webrtc\AVCodec\Filter\Filter;
use Webrtc\AVCodec\Filter\Graph;
use Webrtc\AVCodec\Format\AudioFormat;
use Webrtc\AVCodec\Frame\AudioFrame;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;

/**
 * Class AudioResampler
 *
 * Handles audio resampling, allowing conversion of the sample rate, channel layout,
 * and/or format of an audio frame.
 */
class AudioResampler
{
    private ?AudioFormat $format;
    private ?string $layout;
    private int $rate;
    private ?int $frameSize;
    private Graph|null $graph = null;
    private bool $isPassthrough = false;
    private AudioFrame $frame;

    /**
     * @param AudioFormat|string $format The target audio format, or a string that specifies it.
     * @param string $layout The target channel layout, or a value that specifies it.
     * @param int $rate The target sample rate in Hz.
     * @param ?int $frameSize The frame size for resampling.
     */
    public function __construct(AudioFormat|string $format, string $layout, int $rate = 0, ?int $frameSize = 0)
    {
        $this->format = $format instanceof AudioFormat ? $format : new AudioFormat($format);
        $this->layout = $layout;
        $this->rate = $rate;
        $this->frameSize = $frameSize;
    }

    /**
     * Resamples an audio frame.
     *
     * Converts the `sample_rate`, `channel_layout`, and/or `format` of an audio frame.
     * If no resampling is needed, returns the input frame as a single-element list.
     * If `frame` is `null`, flushes the resampler.
     *
     * @param AudioFrame|null $frame The input audio frame or `null` to flush the resampler.
     * @return AudioFrame[] An array of resampled audio frames. If flushing, returns an empty array.
     *
     * @throws InvalidArgumentException|AvCodecException If the input frame does not match the resampler configuration.
     */
    public function resample(?AudioFrame $frame): array
    {
        if ($this->graph === null && $frame === null) {
            return [];
        }

        if ($this->isPassthrough) {
            return [$frame]; // If passthrough is enabled, return the frame as is.
        }

        // Initialize the graph based on the first frame.
        if ($this->graph === null) {
            $this->initializeGraph($frame);
            if ($this->isPassthrough) {
                return [$frame];
            }
        }

        // Validate the input frame matches the resampler setup.
        if ($frame !== null) {
            $this->validateFrame($frame);
        }

        $this->graph->push($frame);
        $output = [];

        // Collect all frames from the resampler output.
        while (true) {
            try {
                $output[] = $this->graph->pull();
            } catch (RuntimeException) {
                break;
            } catch (AvCodecException $e) {
                if ($e->getCode() !== EAGAIN) {
                    throw $e;
                }
                break;
            }
        }

        return $output;
    }

    /**
     * Initializes the filter graph for resampling based on the first input frame.
     *
     * @param AudioFrame $frame The input frame used to initialize the graph.
     */
    private function initializeGraph(AudioFrame $frame): void
    {
        $this->frame = $frame;

        $this->format = $this->format ?? $frame->getFormat();
        $this->layout = $this->layout ?? $frame->getLayout()->getName();
        $this->rate = $this->rate ?: $frame->getSampleRate();

        // Check if passthrough is possible.
        if (
            $frame->getFormat()->getSampleFormat() === $this->format->getSampleFormat() &&
            $frame->getLayout()->getName() === $this->layout &&
            $frame->getSampleRate() === $this->rate &&
            $this->frameSize === 0
        ) {
            $this->isPassthrough = true;
            return;
        }

        if ($frame->getSampleRate() === 0 and $frame->getSampleRate() !== $this->rate) {
            $frame->setSampleRate($this->rate);
        }

        // Configure the filter graph.
        $this->graph = new Graph();
        $timeBaseOpt = [];
        if (isset($frame->getTimeBase()->num) && isset($frame->getTimeBase()->den)) {
            $timeBaseOpt = ["time_base" => $frame->getTimeBase()->num . "/" . $frame->getTimeBase()?->den];
        }
        $abuffer = $this->graph->add(new Filter("abuffer"), options: array_merge([
            "sample_rate" => $frame->getSampleRate(),
            "sample_fmt" => $frame->getFormat()->getName(),
            "channel_layout" => $frame->getLayout()->getName()
        ], $timeBaseOpt)
        );
        $aformat = $this->graph->add(new Filter("aformat"), options: [
            "sample_rates" => $this->rate,
            "sample_fmts" => $this->format->getName(),
            "channel_layouts" => $this->layout
        ]);
        $abuffersink = $this->graph->add(new Filter("abuffersink"), args: [null]);
        $abuffer->linkTo($aformat);
        $aformat->linkTo($abuffersink);

        $this->graph->configure();

        if ($this->frameSize > 0) {
            $this->graph->setAudioFrameSize($this->frameSize);
        }
    }

    /**
     * Validates that the input frame matches the resampler setup.
     *
     * @param AudioFrame $frame The frame to validate.
     *
     * @throws InvalidArgumentException If the frame does not match the resampler setup.
     */
    private function validateFrame(AudioFrame $frame): void
    {
        if (
            $frame->getFormat()->getSampleFormat() != $this->frame->getFormat()->getSampleFormat() ||
            $frame->getLayout()->getName() != $this->frame->getLayout()->getName() ||
            $frame->getSampleRate() != $this->frame->getSampleRate()
        ) {
            throw new InvalidArgumentException("Frame does not match AudioResampler setup.");
        }
    }
}

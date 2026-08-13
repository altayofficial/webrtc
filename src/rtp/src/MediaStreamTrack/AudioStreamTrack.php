<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP\MediaStreamTrack;

use Webrtc\AVCodec\AVCodec;
use Webrtc\AVCodec\Data\Packet;
use Webrtc\AVCodec\Exception\AvCodecException;
use Webrtc\AVCodec\Frame\AudioFrame;
use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\RTP\Exception\RtpSenderException;

/**
 * Represents an audio stream track for WebRTC.
 *
 * This class handles the creation of audio frames for the media stream, including generating silent frames
 * and managing timestamps for audio frames.
 */
class AudioStreamTrack extends MediaStreamTrack
{
    /**
     * The audio packet time (ptime) in seconds, typically 20 ms for audio streams.
     * @var float
     */
    private const AUDIO_PTIME = 0.02;

    /**
     * The media kind, which is Audio in this case.
     *
     * @var MediaKind
     */
    protected MediaKind $kind = MediaKind::Audio;

    /**
     * The current timestamp for audio frames.
     *
     * @var ?int
     */
    private ?int $timestamp = null;

    /**
     * The sample rate of the audio track.
     *
     * @var int
     */
    private int $sampleRate = 8000;

    /**
     * The number of samples per frame.
     *
     * @var int
     */
    private int $samplesPerFrame;

//    /**
//     * The start time for audio frame generation.
//     *
//     * @var float
//     */
//    private float $start = 0;

    /**
     * Constructor for the AudioStreamTrack.
     *
     * Initializes the audio track with default values and prepares the codec.
     * @throws AvCodecException
     */
    public function __construct()
    {
        parent::__construct();
        AVCodec::init();
        $this->samplesPerFrame = (int)(self::AUDIO_PTIME * $this->sampleRate);
    }

    /**
     * Generates a timestamp for the next audio frame.
     *
     * If the timestamp has not been set before, it initializes it and calculates
     * the starting time. For later calls, it increments the timestamp by
     * the number of samples per frame and adjusts the wait time to maintain the
     * correct timing for audio frames.
     */
    private function generateTimestamp(): void
    {
        if ($this->timestamp === null) {
//            $this->start = microtime(true);
            $this->timestamp = 0;
        } else {
            $this->timestamp += $this->samplesPerFrame;
//           $wait = $this->start + ($this->timestamp / $this->sampleRate) - microtime(true);
            // FIXME: delay($wait);
        }
    }

    /**
     * Generates a silent audio frame.
     *
     * This frame contains no actual audio data (silence) and is used when
     * there is no active audio to transmit.
     *
     * @return AudioFrame The generated silent audio frame.
     * @throws RtpSenderException If the track has already ended.
     */
    private function generateSilentFrame(): AudioFrame
    {
        if ($this->ended) {
            throw new RtpSenderException("The track has already been ended.");
        }
        $this->generateTimestamp();

        $frame = new AudioFrame(format: "s16", layout: "mono", samples: $this->samplesPerFrame);

        // Fill the frame with silence (zeros)
        foreach ($frame->getPlanes() as $plane) {
            $plane->putData(str_repeat("\0", $plane->getSize()));
        }

        // Set frame properties
        $frame->setPts($this->timestamp);
        $frame->setSampleRate($this->sampleRate);
        $frame->setTimeBase(1, $this->sampleRate);

        return $frame;
    }

    /**
     * Receives and returns the next audio frame.
     *
     * In this case, it generates and returns a silent audio frame, as no real
     * audio data is being processed.
     *
     * @return null|Packet|FrameInterface A silent audio frame.
     * @throws RtpSenderException
     */
    public function receiveData(): null|Packet|FrameInterface
    {
        return $this->generateSilentFrame();
    }
}

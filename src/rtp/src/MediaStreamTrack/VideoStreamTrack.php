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

use Exception;
use Webrtc\AVCodec\Data\Packet;
use Webrtc\AVCodec\Exception\AvCodecException;
use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\AVCodec\Frame\VideoFrame;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\RTP\Exception\MediaStreamTrackException;

/**
 * Represents a video stream track in WebRTC.
 *
 * This class extends `MediaStreamTrack` and is responsible for handling video
 * streams. It generates video frames, manages timestamps, and handles timing
 * to ensure frames are processed at the correct intervals.
 */
class VideoStreamTrack extends MediaStreamTrack
{
    /**
     * The clock rate for video, typically 90,000 Hz.
     *
     * @var int
     */
    private const VIDEO_CLOCK_RATE = 90000;

    /**
     * The time per frame for 30 fps video.
     *
     * @var int|float
     */
    private const VIDEO_PTIME = 1 / 30;  // 30 fps

    /**
     * The kind of media track (video).
     *
     * @var MediaKind
     */
    protected MediaKind $kind = MediaKind::Video;

//    /**
//     * The start time for video frames.
//     *
//     * @var float
//     */
//    private float $start;

    /**
     * The current timestamp for video frames.
     *
     * @var int
     */
    private int $timestamp;

    /**
     * VideoStreamTrack constructor.
     *
     * Initializes the video stream track, calling the parent constructor.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Calculates the next timestamp for the video frame and waits to maintain proper frame timing.
     *
     * This method updates the `timestamp` and calculates the time to wait before
     * generating the next frame. If the track has ended, it throws a `MediaStreamTrackException`.
     *
     * @return void
     * @throws MediaStreamTrackException If the track has stopped.
     */
    public function nextTimestampAndWait(): void
    {
        if ($this->ended) {
            throw new MediaStreamTrackException("Video Track has stopped.");
        }

        if (isset($this->timestamp)) {
            $this->timestamp += (int)(self::VIDEO_PTIME * self::VIDEO_CLOCK_RATE);
//            $wait = $this->start + ($this->timestamp / self::VIDEO_CLOCK_RATE) - microtime(true);
            // FIXME: delay($wait); Uncomment to actually wait for the correct frame timing
        } else {
//            $this->start = microtime(true);
            $this->timestamp = 0;
        }
    }

    /**
     * Generates a blank video frame (e.g., a black frame).
     *
     * This method creates a new `VideoFrame`, fills it with zero data (black frame),
     * and sets its properties such as timestamp and time base. It throws exceptions
     * if there are any issues during the frame generation process.
     *
     * @return VideoFrame The generated blank video frame.
     * @throws MediaStreamTrackException If the track has stopped.
     * @throws AvCodecException If there is an error generating the video frame.
     * @throws Exception
     */
    public function generateBlankFrame(): VideoFrame
    {
        $this->nextTimestampAndWait();

        $frame = new VideoFrame(640, 480);  // Default 640x480 resolution
        foreach ($frame->planes() as $plane) {
            $plane->putData(str_repeat("\0", $plane->getSize()));  // Fill the frame with zero data (black)
        }
        $frame->setPts($this->timestamp);
        $frame->setTimeBase(1, self::VIDEO_CLOCK_RATE);

        return $frame;
    }

    /**
     * Receives and returns the next video frame or packet.
     *
     * This method generates a blank video frame and returns it. It may throw exceptions
     * related to codec issues or if the track has stopped.
     *
     * @return Packet|FrameInterface|null The next video frame or packet, or null if none available.
     * @throws AvCodecException If there is an error generating the video frame.
     * @throws MediaStreamTrackException If the track has stopped.
     */
    public function receiveData(): null|Packet|FrameInterface
    {
        return $this->generateBlankFrame();
    }
}

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

use SplQueue;
use Webrtc\AVCodec\Data\Packet;
use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\RTP\Enum\MediaKind;

/**
 * Represents a remote media stream track (audio, video, etc.) in WebRTC.
 *
 * This class extends `MediaStreamTrack` and is used for handling media tracks
 * from remote sources. It manages the queue of frames and allows for receiving
 * and processing the next frame in the stream.
 */
class RemoteStreamTrack extends MediaStreamTrack
{
    /**
     * The kind of media track (audio, video, etc.).
     *
     * @var MediaKind
     */
    protected MediaKind $kind;

    /**
     * A queue to hold frames for the track.
     *
     * @var SplQueue
     */
    private SplQueue $frameQueue;

    /**
     * Constructor for RemoteStreamTrack.
     *
     * Initializes the media track with the specified media kind (audio, video, etc.)
     * and an optional track ID. A queue for holding frames is also created.
     *
     * @param MediaKind $kind The type of media track (e.g., "audio" or "video").
     * @param string|null $id Optional identifier for the track. If not provided, a UUID will be generated.
     */
    public function __construct(MediaKind $kind, ?string $id = null)
    {
        parent::__construct();
        $this->kind = $kind;
        $this->frameQueue = new SplQueue();

        if ($id) {
            $this->id = $id;
        }
    }

    /**
     * Adds a frame to the frame queue.
     *
     * This method includes a frame that will be processed later when `receiveData()` is called.
     *
     * @param FrameInterface $frame The frame to add to the queue.
     */
    public function queueFrame(FrameInterface $frame): void
    {
        $this->frameQueue->enqueue($frame);
    }

    /**
     * Receives and returns the next frame from the queue.
     *
     * If there are frames in the queue, the next frame is dequeued and returned.
     * If the queue is empty, `null` is returned.
     *
     * @return FrameInterface|Packet|null The next frame or packet, or null if the queue is empty.
     */
    public function receiveData(): FrameInterface|Packet|null
    {
        if (!$this->frameQueue->isEmpty()) {
            return $this->frameQueue->dequeue();
        }
        return null;
    }

    /**
     * Gets the current frame queue.
     *
     * This method returns the queue that holds the frames for this track.
     *
     * @return SplQueue The frame queue.
     */
    public function getFrameQueue(): SplQueue
    {
        return $this->frameQueue;
    }
}

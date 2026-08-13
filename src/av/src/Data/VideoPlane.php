<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec\Data;

use Exception;
use FFI;
use Webrtc\AVCodec\Frame\VideoFrame;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;

/**
 * VideoPlane Class
 *
 * Represents a single plane of video data within a video frame.
 * Video frames can have multiple planes depending on the pixel format (e.g., Y, U, V planes
 * in YUV formats). This class provides access to individual plane data and properties.
 */
class VideoPlane extends Buffer
{
    /**
     * @var int Size of the plane buffer in bytes
     */
    private int $bufferSize;

    /**
     * Constructor for VideoPlane
     *
     * Initializes a video plane for the specified frame and plane index.
     * Calculates the appropriate buffer size based on the pixel format.
     *
     * @param VideoFrame $frame The video frame that contains this plane
     * @param int $index The index of the plane within the frame (e.g., 0 for Y plane in YUV)
     *
     * @throws RuntimeException If the specified plane can’t be found in the pixel format
     */
    public function __construct(private readonly VideoFrame $frame, private readonly int $index)
    {
        parent::__construct();
        if ($frame->getVideoFormat()->getName() == "pal8" && $index == 1) {
            $this->bufferSize = 256 * 4;
            return;
        }

        for ($i = 0; $i < $frame->getVideoFormat()->getVideoFormat()->nb_components; $i++) {
            if ($frame->getVideoFormat()->getVideoFormat()->comp[$i]->plane == $index) {
                $height = $frame->getVideoFormat()->getComponents()[$i]->getHeight();
                break;
            }
        }

        if (!isset($height)) {
            throw new RuntimeException("could not find plane $index of {$frame->getVideoFormat()->getName()}");
        }
        $this->bufferSize = abs($this->frame->getFrame()->linesize[$this->index]) * $height;//$this->libAVCodec->av_image_get_buffer_size($videoFormat->getFormat(), $videoFormat->getWidth(), $videoFormat->getHeight(), 32);
    }

    /**
     * Get the plane index
     *
     * @return int The index of this plane within the frame
     */
    public function getIndex(): int
    {
        return $this->index;
    }

    /**
     * Get the parent video frame
     *
     * @return VideoFrame The video frame that contains this plane
     */
    public function getFrame(): VideoFrame
    {
        return $this->frame;
    }

    /**
     * Get the pointer to the plane data
     *
     * Returns the raw FFI pointer to the plane data in memory.
     *
     * @return mixed FFI pointer to the plane data
     */
    public function getPointer(): FFI\CData
    {
        return $this->frame->getFrame()->extended_data[$this->index];
    }

    /**
     * Get the plane data as a string
     *
     * Converts the raw plane data to a PHP string.
     *
     * @return string The plane data as a binary string
     */
    public function getData(): string
    {
        return FFI::string($this->frame->getFrame()->data[$this->index], $this->bufferSize);
    }

    /**
     * Get the size of the plane buffer
     *
     * @return int The size of the plane buffer in bytes
     */
    public function getSize(): int
    {
        return $this->bufferSize;
    }

    /**
     * Get the line size (stride) of the plane
     *
     * The line size may include padding and might be larger than
     * the visible width of the plane.
     *
     * @return mixed The line size in bytes
     */
    public function getLineSize(): int
    {
        return $this->frame->getFrame()->linesize[$this->index];
    }

    /**
     * Put data into the plane buffer
     *
     * Copies the provided data string into the plane buffer.
     * The data size must match the buffer size exactly.
     *
     * @param string $data The binary data to copy into the plane buffer
     *
     * @return void
     * @throws Exception If the buffer is not writable
     * @throws InvalidArgumentException If the input data size doesn't match the buffer size
     * @throws RuntimeException If there's a null pointer in extended_data
     */
    public function putData(string $data): void
    {
        if (!$this->bufferWritable) {
            throw new Exception("Buffer is not writable");
        }
        // TODO: check this line code later
//        $this->allocateBuffer();
        $dataSize = strlen($data);


        // TODO: check this line code later
//        // Manually ensure input data size fits allocated frame buffer
//        if ($dataSize > $this->bufferSize / 3) { // Assuming three equal parts for Y, U, V
//            throw new InvalidArgumentException("Input data size exceeds allocated buffer size");
//        }

        if (strlen($data) != $this->bufferSize) {
            throw new InvalidArgumentException("Input data size exceeds allocated buffer size");
        }

        if (FFI::isNull($this->frame->getFrame()->extended_data[$this->index])) {
            throw new RuntimeException("Null pointer encountered in extended_data");
        }

        FFI::memcpy($this->frame->getFrame()->extended_data[$this->index], $data, $dataSize);
    }

    /**
     * Allocate buffer for the plane
     *
     * Allocates memory for the plane buffer and fills the image arrays.
     * Currently, disabled and marked with TODOs.
     *
     * @return void
     * @throws RuntimeException If buffer allocation or image array filling fails
     */
    private function allocateBuffer(): void
    {
        // TODO: check this line code later
//        if ($this->libAVCodec->av_frame_get_buffer($this->frame->getFrame(), 32) < 0) {
//            throw new InvalidArgumentException("Failed to allocate frame buffer");
//        }

        // Allocate buffer size manually and ensure it is correctly filled
        $videoFormat = $this->frame->getVideoFormat();
        $buffer = $this->libAVCodec->new("uint8_t[$this->bufferSize]");
        if ($buffer === null) {
            throw new RuntimeException("Failed to allocate buffer");
        }

        if ($this->libAVCodec->av_image_fill_arrays($this->frame->getFrame()->data, $this->frame->getFrame()->linesize, $buffer, $videoFormat->getFormat(), $videoFormat->getWidth(), $videoFormat->getHeight(), 32) < 0) {
            throw new RuntimeException("Failed to fill image arrays");
        }
    }

    /**
     * Destructor for VideoPlane
     *
     * Cleans up resources when the object is destroyed.
     * Currently, most functionality is disabled and marked with TODOs.
     */
    public function __destruct()
    {
        // TODO: check this line code later
//        if ($this->buffer) {
//            FFI::free($this->buffer);
//            $this->buffer = null;
//        }
    }
}
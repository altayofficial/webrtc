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

use FFI;
use Webrtc\AVCodec\Frame\AudioFrame;
use Webrtc\Exception\InvalidArgumentException;

/**
 * Audio Plane Class
 *
 * Represents a single plane of audio data within an audio frame.
 * Provides access to raw audio data and metadata for a specific channel/plane.
 *
 * @package Webrtc\AVCodec\Data
 */
class AudioPlane extends Buffer
{
    /**
     * @var int $bufferSize Size of the audio buffer in bytes
     */
    private int $bufferSize;

    /**
     * Constructor
     *
     * @param AudioFrame $frame Parent audio frame
     * @param int $index Plane/channel index
     */
    public function __construct(private readonly AudioFrame $frame, private readonly int $index)
    {
        parent::__construct();
        $this->bufferSize = abs($this->frame->getFrame()->linesize[0]);
    }

    /**
     * Get the plane/channel index
     *
     * @return int Zero-based plane index
     */
    public function getIndex(): int
    {
        return $this->index;
    }

    /**
     * Get the parent audio frame
     *
     * @return AudioFrame Associated audio frame
     */
    public function getFrame(): AudioFrame
    {
        return $this->frame;
    }

    /**
     * Get raw pointer to audio data
     *
     * @return mixed FFI pointer to audio data
     */
    public function getPointer(): FFI\CData
    {
        return $this->frame->getFrame()->extended_data[$this->index];
    }

    /**
     * Get audio data as string
     *
     * @return string Raw audio data bytes
     */
    public function getData(): string
    {
        return FFI::string($this->frame->getFrame()->data[$this->index], $this->bufferSize);
    }

    /**
     * Get buffer size in bytes
     *
     * @return int Size of audio buffer
     */
    public function getSize(): int
    {
        return $this->bufferSize;
    }

    /**
     * Get line size (stride) of audio data
     *
     * @return mixed Line size in bytes
     */
    public function getLineSize(): int
    {
        return $this->frame->getFrame()->linesize[$this->index];
    }

    /**
     * Write data to audio plane
     *
     * @param string $data Raw audio data to write
     * @throws InvalidArgumentException If data exceeds buffer size
     */
    public function putData(string $data): void
    {
        if(strlen($data) > $this->bufferSize) {
            throw new InvalidArgumentException("Input data size exceeds allocated buffer size");
        }

        FFI::memcpy($this->frame->getFrame()->extended_data[$this->index], $data, strlen($data));
    }
}
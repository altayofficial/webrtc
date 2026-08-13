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
use FFI\CData;
use stdClass;
use Webrtc\Exception\InvalidArgumentException;

/**
 * Packet Class
 *
 * Represents an encoded audio/video packet (AVPacket) in the FFmpeg context.
 * Handles creation, storage, and manipulation of encoded media packets.
 *
 * @package Webrtc\AVCodec\Data
 */
class Packet extends Buffer
{
    /**
     * @var CData $packet FFI CData representing AVPacket
     */
    private CData $packet;

    /**
     * @var stdClass $timeBase Time base for PTS/DTS calculations
     */
    private stdClass $timeBase;

    /**
     * Constructor - allocates a new AVPacket
     */
    public function __construct()
    {
        parent::__construct();
        $this->timeBase = new stdClass();
        $this->packet = $this->libAVCodec->av_packet_alloc();
    }

    /**
     * Destructor - free packet memory
     */
    public function __destruct()
    {
        $this->libAVCodec->av_packet_free(FFI::addr($this->packet));
    }

    /**
     * Get packet size in bytes
     *
     * @return int Size of packet data
     */
    public function getSize(): int
    {
        return $this->packet->size;
    }

    /**
     * Get raw pointer to packet data
     *
     * @return mixed FFI pointer to packet data
     */
    public function getPointer(): CData
    {
        return $this->packet->data;
    }

    /**
     * Get packet data as string
     *
     * @return string Raw packet data bytes
     */
    public function getData(): string
    {
        return FFI::string($this->packet->data, $this->packet->size);
    }

    /**
     * Get presentation timestamp (PTS)
     *
     * @return int|null PTS value or null if not set (AV_NOPTS_VALUE)
     */
    public function getPts(): ?int
    {
        if ($this->packet->pts != $this->libAVCodec->AV_NOPTS_VALUE) {
            return $this->packet->pts;
        }
        return null;
    }

    /**
     * Set presentation timestamp (PTS)
     *
     * @param int|null $value PTS value or null to set as AV_NOPTS_VALUE
     */
    public function setPts(?int $value): void
    {
        $this->packet->pts = $value ?? $this->libAVCodec->AV_NOPTS_VALUE;
    }

    /**
     * Get raw FFI packet object
     *
     * @return CData The FFI CData AVPacket
     */
    public function getPacket(): CData
    {
        return $this->packet;
    }

    /**
     * Store data in a packet
     *
     * @param string $data Data to store in a packet
     * @throws InvalidArgumentException If packet creation fails
     */
    public function putData(string $data): void
    {
        $res = $this->libAVCodec->av_new_packet($this->packet, strlen($data));
        if ($res < 0) {
            throw new InvalidArgumentException("Couldn't create a new packet");
        }

        FFI::memcpy($this->packet->data, $data, strlen($data));
    }

    /**
     * Set time base for timestamp calculations
     *
     * @param int $numerator Time base numerator
     * @param int $denominator Time base denominator
     */
    public function setTimeBase(int $numerator, int $denominator): void
    {
        $this->timeBase->num = $numerator;
        $this->timeBase->den = $denominator;
    }

    /**
     * Get current time base
     *
     * @return stdClass Object with num/den properties
     */
    public function getTimeBase(): stdClass
    {
        return $this->timeBase;
    }
}
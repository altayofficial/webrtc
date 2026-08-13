<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec;

use FFI;
use Webrtc\AVCodec\Context\ContextInterface;
use Webrtc\AVCodec\Context\VideoContext;
use Webrtc\AVCodec\Data\Packet;
use Webrtc\AVCodec\Frame\Frame;
use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;
use Webrtc\Mixin\SharedLibraryInterface;

/**
 * TransCoder Class
 *
 * This class handles encoding and decoding of audio and video data using FFI to interface
 * with the AVCodec library. It manages the flow of frames and packets between the codec
 * context and the application.
 */
class TransCoder implements TransCoderInterface, SharedLibraryInterface
{
    /**
     * @var FFI The AVCodec library interface
     */
    private FFI $libAVCodec;

    /**
     * @var FrameInterface|null Cached frame for decoding operations
     */
    private ?FrameInterface $nextFrame = null;

    /**
     * Constructor for TransCoder
     *
     * @param ContextInterface $context The codec context to use for encoding/decoding operations
     */
    public function __construct(private ContextInterface $context)
    {
        $this->initiateSharedLibrary();
    }

    /**
     * Encodes a frame into one or more packets
     *
     * Processes the input frame through the encoder context and produces an array
     * of encoded packets. If frame is null, it will flush the encoder.
     *
     * @param FrameInterface|null $frame The frame to encode, or null to flush the encoder
     *
     * @return array An array of Packet objects
     * @throws RuntimeException If an error occurs during encoding
     */
    public function encode(?FrameInterface $frame): array
    {
        $frames = $this->prepareFrame($frame);
        $packets = [];

        foreach ($frames as $frame) {
            foreach ($this->sendFrameAndReceive($frame) as $packet) {
                $contextTimeBase = $this->context->getTimeBase();
                $packet->setTimeBase($contextTimeBase->num, $contextTimeBase->den);
                $packets [] = $packet;
            }
        }

        return $packets;
    }

    /**
     * Sends a frame to the encoder and receives any resulting packets
     *
     * This is a generator method that yields packets as they become available
     * from the encoder.
     *
     * @param FrameInterface|null $frame The frame to encode, or null to flush the encoder
     *
     * @return \Generator A generator yielding Packet objects
     * @throws RuntimeException If an error occurs during encoding
     */
    public function sendFrameAndReceive(?FrameInterface $frame): \Generator
    {
        $res = $this->libAVCodec->avcodec_send_frame($this->context->getContext(), $frame?->getFrame());
        if ($res < 0) {
            throw new RuntimeException("An error occurred while encoding");
        }

        $packet = $this->recvPacket();
        while ($packet) {
            yield $packet;
            $packet = $this->recvPacket();
        }
    }

    /**
     * Receives a packet from the encoder
     *
     * @return Packet|null A packet if available, or null if no more packets are available
     */
    private function recvPacket(): ?Packet
    {
        $packet = new Packet();
        $res = $this->libAVCodec->avcodec_receive_packet($this->context->getContext(), $packet->getPacket());

        if ($res == -11 || $res == -541478725) { // EAGAIN or AVERROR_EOF
            return null;
        }
        if ($res === 0) {
            return $packet;
        }
        return null;
    }

    /**
     * Decodes a packet into one or more frames
     *
     * Processes the input packet through the decoder context and produces
     * an array of decoded frames. If packet is null, it will flush the decoder.
     *
     * @param Packet|null $packet The packet to decode, or null to flush the decoder
     *
     * @return array An array of FrameInterface objects
     * @throws InvalidArgumentException If the codec is unknown or not properly initialized
     * @throws RuntimeException If an error occurs during decoding
     */
    public function decode(?Packet $packet): array
    {
        if (!$this->context->getContext()) {
            throw new InvalidArgumentException("Cannot decode unknown codec");
        }

        $this->context->open(false);

        $frames = [];
        foreach ($this->sendPacketAndGet($packet) as $frame) {
            if ($frame instanceof Frame) {
                $packetBaseTime = $packet?->getTimeBase();
                if ($packetBaseTime && isset($packetBaseTime->num) && isset($packetBaseTime->den)) {
                    $frame->setTimeBase($packetBaseTime->num, $packetBaseTime->den);
                }
            }
            $frames[] = $frame;
        }

        return $frames;
    }

    /**
     * Send a packet and receive frames
     *
     * @param Packet|null $packet The packet to be sent, or null to flush
     *
     * @return array An array of received frames
     * @throws RuntimeException If an error occurs during decoding
     */
    private function sendPacketAndGet(?Packet $packet): array
    {
        $frames = [];

        $res = $this->libAVCodec->avcodec_send_packet($this->context->getContext(), $packet?->getPacket());
        if ($res < 0) {
            throw new RuntimeException("An error occurred while decoding");
        }

        // Receive frames in a loop
        while (true) {
            $frame = $this->getFrame();
            if ($frame) {
                $frames[] = $frame;
            } else {
                break;
            }
        }

        return $frames;
    }

    /**
     * Receive a frame from the codec
     *
     * @return FrameInterface|null Returns a Frame object if available, or null if no more frames are available
     * @throws RuntimeException If an error occurs during decoding
     */
    private function getFrame(): ?FrameInterface
    {
        // Allocate the next frame if not already allocated
        if ($this->nextFrame === null) {
            $this->nextFrame = $this->context->getNewFrame();
        }
        $frame = $this->nextFrame;

        // Receive the frame
        $res = $this->libAVCodec->avcodec_receive_frame($this->context->getContext(), $frame->getFrame());

        // Handle special return codes
        if ($res === -EAGAIN || $res === AVERROR_EOF) {
            return null;
        }

        // Check for other errors
        if ($res < 0) {
            throw new RuntimeException("An error occurred while decoding");
        }

        // If successful, clear the next frame and return the frame
        if ($res === 0) {
            $this->nextFrame = null;
            if ($this->context instanceof VideoContext) {
                $frame->setVideoFormat();
            }
            return $frame;
        }

        return null;
    }

    /**
     * Prepares frames for encoding
     *
     * Validates codec type, opens the context if needed, and prepares
     * the frames with proper time base settings.
     *
     * @param FrameInterface|null $frame The frame to prepare, or null to flush
     *
     * @return array An array of prepared frames ready for encoding
     * @throws RuntimeException If encoding is not supported for the codec type
     */
    private function prepareFrame(?FrameInterface $frame): array
    {
        // Check if codec_type is supported
        if (!in_array($this->context->getContext()->codec_type, [0, 1])) {
            throw new RuntimeException("Encoding is only supported for audio and video.");
        }
        $this->context->open(false);

        $frames = $this->context->prepareFramesForEncode($frame);

        foreach ($frames as $frame) {
            $frame?->rebaseTime($this->context->getContext()->time_base);
        }

        return $frames;
    }

    /**
     * Initializes the AVCodec shared library
     *
     * This method implements the SharedLibraryInterface requirement.
     * It uses a global FFI instance of the AVCodec library if available.
     *
     * @return void
     */
    public function initiateSharedLibrary(): void
    {
        global $libAVCodec;

        if ($libAVCodec instanceof FFI) {
            $this->libAVCodec = $libAVCodec;
        }
    }
}
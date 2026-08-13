<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Codecs\Video\X264;

use Exception;
use Generator;
use Iterator;
use Webrtc\AVCodec\AVCodec;
use Webrtc\AVCodec\Codec;
use Webrtc\AVCodec\Context\Context;
use Webrtc\AVCodec\Context\VideoContext;
use Webrtc\AVCodec\Data\Packet;
use Webrtc\AVCodec\Enum\PictureType;
use Webrtc\AVCodec\Exception\AvCodecException;
use Webrtc\AVCodec\Format\VideoFormat;
use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\AVCodec\Frame\VideoFrame;
use Webrtc\AVCodec\TransCoder;
use Webrtc\Codecs\Encoder;
use Webrtc\Codecs\EncoderInterface;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;

/**
 * H.264 Video Encoder Class
 *
 * Implements real-time H.264 video encoding with RTP packetization.
 * Supports both hardware-accelerated (OMX) and software (libx264) encoding.
 * Provides adaptive bitrate control and frame-type management.
 *
 * @package Webrtc\Codecs\Video\X264
 */
class H264Encoder extends Encoder implements EncoderInterface
{
    /**
     * @var int
     */
    private const FU_A_HEADER_SIZE = 2;
    /**
     * @var int
     */
    private const NAL_TYPE_FU_A = 28;
    /**
     * @var int
     */
    private const NAL_TYPE_STAP_A = 24;
    /**
     * @var int
     */
    private const NAL_HEADER_SIZE = 1;
    /**
     * @var int
     */
    private const LENGTH_FIELD_SIZE = 2;
    /**
     * @var int
     */
    private const STAP_A_HEADER_SIZE = 1;
    /**
     * @var mixed[]
     */
    private const DEFAULT_CODEC_NAMES = ["h264_omx", "libx264"]; // Based on their priorities
    /**
     * @var int
     */
    private const PACKET_MAX = 1300; // Maximum packet size.
    /**
     * @var string $bufferData Buffered encoded data
     */
    private string $bufferData = "";

    /**
     * @var int|null $bufferPts PTS of buffered data
     */
    private ?int $bufferPts = null;

    /**
     * @var VideoContext|null $encoderContext Active encoder context
     */
    private ?VideoContext $encoderContext = null;

    /**
     * @var bool $codecBuffering Flag for codec buffering mode
     */
    private bool $codecBuffering = false;

    /**
     * @var int $bitrate Current target bitrate (1Mbps default)
     */
    protected int $bitrate = 1000000;

    /**
     * Constructor - initializes AVCodec
     * @throws AvCodecException
     */
    public function __construct()
    {
        AVCodec::init();
    }

    /**
     * Packetizes encoded H.264 data
     *
     * @param iterable $packets Encoded NAL units
     * @return array Packetized RTP payloads
     */
    public static function packetize(iterable $packets): array
    {
        $packetizedPackages = [];
        $packetsIterator = (function () use ($packets) {
            foreach ($packets as $packet) {
                yield $packet;
            }
        })();

        $packet = $packetsIterator->current();
        while ($packet !== null) {
            if (strlen($packet) > self::PACKET_MAX) {
                $packetizedPackages = array_merge(
                    $packetizedPackages,
                    self::packetizeFuA($packet)
                );
                $packetsIterator->next();
                $packet = $packetsIterator->current();
            } else {
                list($packetized, $packet) = self::packetizeStapA($packet, $packetsIterator);
                $packetizedPackages[] = $packetized;
            }
        }

        return $packetizedPackages;
    }

    /**
     * Splits large NAL units into FU-A fragments
     *
     * @param string $data NAL unit data
     * @return array FU-A packets
     */
    private static function packetizeFuA(string $data): array
    {
        $availableSize = self::PACKET_MAX - self::FU_A_HEADER_SIZE;
        $payloadSize = strlen($data) - self::NAL_HEADER_SIZE;
        $numPackets = (int)ceil($payloadSize / $availableSize);
        $numLargerPackets = $payloadSize % $numPackets;
        $packetsize = (int)floor($payloadSize / $numPackets);

        $fNri = ord($data[0]) & (0x80 | 0x60); // Extract forbidden bit and NRI
        $nal = ord($data[0]) & 0x1F; // Extract NAL type

        $fuIndicator = $fNri | self::NAL_TYPE_FU_A;

        $fuHeaderEnd = chr($fuIndicator) . chr($nal | 0x40);
        $fuHeaderMiddle = chr($fuIndicator) . chr($nal);
        $fuHeaderStart = chr($fuIndicator) . chr($nal | 0x80);
        $fuHeader = $fuHeaderStart;

        $packets = [];
        $offset = self::NAL_HEADER_SIZE;

        while ($offset < strlen($data)) {
            if ($numLargerPackets > 0) {
                $numLargerPackets--;
                $payload = substr($data, $offset, $packetsize + 1);
                $offset += $packetsize + 1;
            } else {
                $payload = substr($data, $offset, $packetsize);
                $offset += $packetsize;
            }

            if ($offset == strlen($data)) {
                $fuHeader = $fuHeaderEnd;
            }

            $packets[] = $fuHeader . $payload;
            $fuHeader = $fuHeaderMiddle;
        }

        if ($offset !== strlen($data)) {
            throw new RuntimeException("Incorrect fragment data");
        }

        return $packets;
    }

    /**
     * Aggregates small NAL units into STAP-A packets
     *
     * @param string $data Initial NAL unit
     * @param Iterator $packetsIterator Remaining NAL units
     * @return array [STAP-A packet, next NAL unit]
     */
    private static function packetizeStapA(string $data, Iterator $packetsIterator): array
    {
        $counter = 0;
        $availableSize = self::PACKET_MAX - self::STAP_A_HEADER_SIZE;

        $stapHeader = self::NAL_TYPE_STAP_A | (ord($data[0]) & 0xE0);

        $payload = "";
        try {
            $nalu = $data; // with header
            while ($nalu !== null && strlen($nalu) <= $availableSize && $counter < 9) {
                $stapHeader |= ord($nalu[0]) & 0x80;

                $nri = ord($nalu[0]) & 0x60;
                if (($stapHeader & 0x60) < $nri) {
                    $stapHeader = ($stapHeader & 0x9F) | $nri;
                }

                $availableSize -= self::LENGTH_FIELD_SIZE + strlen($nalu);
                $counter++;
                $payload .= pack("n", strlen($nalu)) . $nalu;
                $packetsIterator->next();
                $nalu = $packetsIterator->current();
            }

            if ($counter == 0) {
                $packetsIterator->next();
                $nalu = $packetsIterator->current();
            }
        } catch (Exception) {
            $nalu = null;
        }

        if ($counter <= 1) {
            return [$data, $nalu];
        } else {
            return [chr($stapHeader) . $payload, $nalu];
        }
    }

    /**
     * Splits H.264 bitstream into NAL units
     *
     * @param string $buf Encoded bitstream
     * @return Generator NAL units
     */
    public static function splitBitstream(string $buf): Generator
    {
        $i = 0;

        while (true) {
            $i = strpos($buf, "\x00\x00\x01", $i);
            if ($i === false) {
                return;
            }

            $i += 3;
            $nalStart = $i;

            $i = strpos($buf, "\x00\x00\x01", $i);
            if ($i === false) {
                yield substr($buf, $nalStart);
                return;
            }

            if ($buf[$i - 1] === "\x00") {
                yield substr($buf, $nalStart, $i - $nalStart - 1);
            } else {
                yield substr($buf, $nalStart, $i - $nalStart);
            }
        }
    }

    /**
     * Encodes video frame to H.264
     *
     * @param VideoFrame $frame Input frame
     * @param bool $useKeyframe Force keyframe
     * @return Generator Encoded NAL units
     */
    public function encodeFrame(VideoFrame $frame, bool $useKeyframe): Generator
    {
        if ($this->encoderContext && (
                $frame->getVideoFormat()->getWidth() !== $this->encoderContext->getWidth() ||
                $frame->getVideoFormat()->getHeight() !== $this->encoderContext->getHeight() ||
                abs($this->bitrate - $this->encoderContext->getBitrate()) / $this->encoderContext->getBitrate() > 0.1
            )) {
            $this->bufferData = "";
            $this->bufferPts = null;
            $this->encoderContext = null;
        }
        $frame->setPictureType($useKeyframe ? PictureType::I : PictureType::NONE);

        if ($this->encoderContext === null) {
            // Initialize codec context
            $this->encoderContext = $this->getContext($frame->getVideoFormat());
        }

        $dataToSend = "";

        $transCoder = new TransCoder($this->encoderContext);
        $packets = $transCoder->encode($frame);

        foreach ($packets as $packet) {
            $packetBytes = $packet->getData();

            if ($this->codecBuffering) {
                if ($packet->getPts() === $this->bufferPts) {
                    $this->bufferData .= $packetBytes;
                } else {
                    $dataToSend .= $this->bufferData;
                    $this->bufferData = $packetBytes;
                    $this->bufferPts = $packet->getPts();
                }
            } else {
                $dataToSend .= $packetBytes;
            }
        }

        if (!empty($dataToSend)) {
            foreach (self::splitBitstream($dataToSend) as $nalUnit) {
                yield $nalUnit;
            }
        }
    }

    /**
     * Encodes frame and packetizes output
     *
     * @param FrameInterface $frame Input frame
     * @param bool $useKeyframe Force keyframe
     * @return array [packets, timestamp]
     */
    public function encode(FrameInterface $frame, bool $useKeyframe = false): array
    {
        if (!$frame instanceof VideoFrame) {
            throw new InvalidArgumentException("");
        }

        $packets = $this->encodeFrame($frame, $useKeyframe);
        $timestamp = $this->convertTimebase($frame->getPts(), (array)$frame->getTimeBase(), [1, 90000]);

        return [self::packetize($packets), $timestamp];
    }

    /**
     * Gets appropriate encoder context
     *
     * @param VideoFormat $format Video format
     * @return Context|null Encoder context
     */
    public function getContext(VideoFormat $format): ?Context
    {
        foreach (self::DEFAULT_CODEC_NAMES as $codecName) {
            try {
                $this->codecBuffering = $codecName === "h264_omx";
                return $this->createContext($format, new Codec($codecName, "w"));
            } catch (Exception) {
            }
        }
        return null;
    }

    /**
     * Creates new encoder context
     *
     * @param VideoFormat $format Video format
     * @param Codec $codec Codec to use
     * @return Context|null Encoder context
     */
    private function createContext(VideoFormat $format, Codec $codec): ?Context
    {
        $videoContext = VideoContext::create($codec);
        $videoContext->setFormat($format);
        $videoContext->setBitRate($this->bitrate);
        $videoContext->setFramerate(30, 1);
        $videoContext->setTimeBase(1, 30);
        $videoContext->setOptions([
            "profile" => "baseline",
            "level" => "31",
            "tune" => "zerolatency",
        ]);
        $videoContext->open();

        return $videoContext;
    }

    /**
     * Packetizes encoded packet for RTP
     *
     * @param Packet $packet Encoded packet
     * @return array [packets, timestamp]
     */
    public function pack(Packet $packet): array
    {
        $packages = $this->splitBitstream($packet->getData());
        $timestamp = $this->convertTimebase($packet->getPts(), (array)$packet->getTimeBase(), [1, 90000]);

        return [$this->packetize($packages), $timestamp];
    }
}


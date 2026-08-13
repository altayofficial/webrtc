<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Codecs;

use Exception;
use Webrtc\Codecs\Audio\Opus\OpusDecoder;
use Webrtc\Codecs\Audio\Opus\OpusEncoder;
use Webrtc\Codecs\Audio\PCM\PCMaDecoder;
use Webrtc\Codecs\Audio\PCM\PCMaEncoder;
use Webrtc\Codecs\Audio\PCM\PCMuDecoder;
use Webrtc\Codecs\Audio\PCM\PCMuEncoder;
use Webrtc\Codecs\Video\Vp8\Vp8Decoder;
use Webrtc\Codecs\Video\Vp8\Vp8Encoder;
use Webrtc\Codecs\Video\Vp8\Vp8PayloadDescriptor;
use Webrtc\Codecs\Video\X264\H264Decoder;
use Webrtc\Codecs\Video\X264\H264Encoder;
use Webrtc\Codecs\Video\X264\H264PayloadDescriptor;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\RTPParameter\RTCRtcpFeedback;
use Webrtc\RTPParameter\RTCRtpCapabilities;
use Webrtc\RTPParameter\RTCRtpCodecCapability;
use Webrtc\RTPParameter\RTCRtpCodecParameters;
use Webrtc\RTPParameter\RTCRtpHeaderExtensionCapability;
use Webrtc\RTPParameter\RTCRtpHeaderExtensionParameters;

/**
 * WebRTC Codec Management Class
 *
 * Provides centralized codec and RTP capabilities management for WebRTC applications.
 * Handles audio/video codec registration, capability negotiation, and encoder/decoder
 * instantiation.
 *
 * @package Webrtc\Codecs
 */
class Codec
{
    /**
     * @var RTCRtpCodecParameters[][] Registered codecs by media type
     */
    private array $codecs;

    /**
     * @var array Header extensions by media type
     */
    private array $headerExtensions;

    /**
     * @var int Dynamic payload type counter
     */
    private int $dynamicPt = 97;

    /**
     * Constructor - initializes default codecs and header extensions
     */
    public function __construct()
    {
        $this->initializeDefaultCodecs();
        $this->initializeHeaderExtensions();
        $this->initCodecs();
    }

    /**
     * Initializes default audio codecs
     */
    private function initializeDefaultCodecs(): void
    {
        $this->codecs = [
            'audio' => [
                new RTCRtpCodecParameters('audio/opus', 48000, 2, 96),
                new RTCRtpCodecParameters('audio/PCMU', 8000, 1, 0),
                new RTCRtpCodecParameters('audio/PCMA', 8000, 1, 8),
            ],
            'video' => [],
        ];
    }

    /**
     * Initializes default header extensions
     */
    private function initializeHeaderExtensions(): void
    {
        $this->headerExtensions = [
            'audio' => [
                new RTCRtpHeaderExtensionParameters(1, 'urn:ietf:params:rtp-hdrext:sdes:mid'),
                new RTCRtpHeaderExtensionParameters(2, 'urn:ietf:params:rtp-hdrext:ssrc-audio-level'),
            ],
            'video' => [
                new RTCRtpHeaderExtensionParameters(1, 'urn:ietf:params:rtp-hdrext:sdes:mid'),
                new RTCRtpHeaderExtensionParameters(3, 'http://www.webrtc.org/experiments/rtp-hdrext/abs-send-time'),
            ],
        ];
    }

    /**
     * Adds video codec with RTX support
     *
     * @param string $mimeType Codec MIME type
     * @param array $parameters Codec-specific parameters
     */
    private function addVideoCodec(string $mimeType, array $parameters = []): void
    {
        $clockRate = 90000;
        $this->codecs['video'][] = new RTCRtpCodecParameters(
            $mimeType,
            $clockRate,
            null,
            $this->dynamicPt,
            [
                new RTCRtcpFeedback('nack'),
                new RTCRtcpFeedback('nack', 'pli'),
                new RTCRtcpFeedback('goog-remb'),
            ],
            $parameters
        );

        $this->codecs['video'][] = new RTCRtpCodecParameters(
            'video/rtx',
            $clockRate,
            null,
            $this->dynamicPt + 1,
            [],
            ['apt' => $this->dynamicPt]
        );

        $this->dynamicPt += 2;
    }

    /**
     * Initializes all supported codecs
     */
    private function initCodecs(): void
    {
        $this->addVideoCodec('video/VP8');
        foreach (['42001f', '42e01f'] as $profileLevelId) {
            $this->addVideoCodec('video/H264', [
                'level-asymmetry-allowed' => '1',
                'packetization-mode' => '1',
                'profile-level-id' => $profileLevelId,
            ]);
        }
    }

    /**
     * Gets capabilities for specified media type
     *
     * @param string $kind Media type ('audio' or 'video')
     * @return RTCRtpCapabilities Codec and header extension capabilities
     * @throws InvalidArgumentException For unknown media types
     */
    public function getCapabilities(string $kind): RTCRtpCapabilities
    {
        if (!isset($this->codecs[$kind])) {
            throw new InvalidArgumentException("Cannot get capabilities for unknown media $kind");
        }

        $codecs = [];
        $headerExtensions = [];
        $rtxAdded = false;

        foreach ($this->codecs[$kind] as $codec) {
            if (CodecUtility::isRtx($codec)) {
                if (!$rtxAdded) {
                    $codecs[] = new RTCRtpCodecCapability(
                        $codec->mimeType,
                        $codec->clockRate
                    );
                    $rtxAdded = true;
                }
            } else {
                $codecs[] = new RTCRtpCodecCapability(
                    $codec->mimeType,
                    $codec->clockRate,
                    $codec->channels,
                    $codec->parameters
                );
            }
        }

        foreach ($this->headerExtensions[$kind] as $extension) {
            $headerExtensions[] = new RTCRtpHeaderExtensionCapability($extension->uri);
        }

        return new RTCRtpCapabilities($codecs, $headerExtensions);
    }

    /**
     * Gets decoder instance for specified codec
     *
     * @param RTCRtpCodecParameters $codec Codec parameters
     * @return DecoderInterface Appropriate decoder instance
     * @throws InvalidArgumentException For unsupported codecs
     */
    public static function getDecoder(RTCRtpCodecParameters $codec): DecoderInterface
    {
        return match (strtolower($codec->mimeType)) {
            'audio/opus' => new OpusDecoder,
            'audio/pcma' => new PCMaDecoder,
            'audio/pcmu' => new PCMuDecoder,
            'video/h264' => new H264Decoder,
            'video/vp8'  => new Vp8Decoder,
            default => throw new InvalidArgumentException("No decoder found for MIME type `$codec->mimeType`"),
        };
    }

    /**
     * Gets encoder instance for specified codec
     *
     * @param RTCRtpCodecParameters $codec Codec parameters
     * @return EncoderInterface Appropriate encoder instance
     * @throws InvalidArgumentException For unsupported codecs
     */
    public static function getEncoder(RTCRtpCodecParameters $codec): EncoderInterface
    {
        return match (strtolower($codec->mimeType)) {
            'audio/opus' => new OpusEncoder,
            'audio/pcma' => new PCMaEncoder,
            'audio/pcmu' => new PCMuEncoder,
            'video/h264' => new H264Encoder,
            'video/vp8'  => new Vp8Encoder,
            default => throw new InvalidArgumentException("No encoder found for MIME type `$codec->mimeType`"),
        };
    }

    /**
     * Processes RTP payload according to codec requirements
     *
     * @param RTCRtpCodecParameters $codec Codec parameters
     * @param string $payload RTP payload data
     * @return array [bool, string] Processed payload data
     * @throws Exception For processing failures
     */
    public static function depayload(RTCRtpCodecParameters $codec, string $payload): array
    {
        return match (strtolower($codec->mimeType)) {
            "video/vp8" => Vp8PayloadDescriptor::decode($payload),
            "video/h264" => H264PayloadDescriptor::decode($payload),
            default => [true, $payload],
        };
    }

    /**
     * Gets registered codecs
     *
     * @param string|null $kind Optional media type filter
     * @return array Codecs array
     */
    public function getCodecs(?string $kind = null): array
    {
        return $kind ? $this->codecs[$kind] : $this->codecs;
    }

    /**
     * Gets registered header extensions
     *
     * @param string|null $kind Optional media type filter
     * @return array Header extensions array
     */
    public function getHeaderExtensions(?string $kind = null): array
    {
        return $kind ? $this->headerExtensions[$kind] : $this->headerExtensions;
    }
}
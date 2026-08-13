<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP\Sender;

use DateMalformedStringException;
use Exception;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Random\RandomException;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Throwable;
use Webrtc\AVCodec\Frame\AudioFrame;
use Webrtc\AVCodec\Frame\FrameInterface;
use Webrtc\Codecs\Codec;
use Webrtc\Codecs\CodecUtility;
use Webrtc\Codecs\EncoderInterface;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\NTP\NetworkTimeProtocol;
use Webrtc\RTCP\Exception\RtcpExceptionInterface;
use Webrtc\RTCP\RtcpByePacket;
use Webrtc\RTCP\RtcpConstants;
use Webrtc\RTCP\RtcpPacketInterface;
use Webrtc\RTCP\RtcpPsfbPacket;
use Webrtc\RTCP\RtcpRrPacket;
use Webrtc\RTCP\RtcpRtpfbPacket;
use Webrtc\RTCP\RtcpSdesPacket;
use Webrtc\RTCP\RtcpSenderInfo;
use Webrtc\RTCP\RtcpSourceInfo;
use Webrtc\RTCP\RtcpSrPacket;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\RTP\HeaderExtension\HeaderExtensionsMap;
use Webrtc\RTP\MediaStreamTrack\MediaStreamTrack;
use Webrtc\RTP\RTCRTPDtlsTransportInterface;
use Webrtc\RTP\RtpConstants;
use Webrtc\RTP\RtpPacket;
use Webrtc\RTP\RtpUtility;
use Webrtc\RTPParameter\RTCRtpCodecParameters;
use Webrtc\RTPParameter\RTCRtpSendParameters;
use Webrtc\Srtp\Exception\SrtpException;
use Webrtc\Stats\enum\StatType;
use Webrtc\Stats\enum\TLSState;
use Webrtc\Stats\RTCOutboundRtpStreamStats;
use Webrtc\Stats\RTCRemoteInboundRtpStreamStats;
use Webrtc\Stats\RTCStatsReport;

/**
 * RTCRtpSender handles the sending of media data over RTP/RTCP.
 *
 * This class is responsible for:
 * - Encoding and packetizing media frames
 * - Sending RTP packets
 * - Generating and processing RTCP packets
 * - Handling retransmissions (RTX)
 * - Collecting and reporting statistics
 * - Managing the media track and transport
 */
class RTCRtpSender implements RtpSenderInterface
{
    /** @var float Smoothing factor for RTT calculations */
    private const RTT_ALPHA = 0.85;

    /** @var MediaKind The kind of media (e.g., "audio" or "video") */
    private MediaKind $kind;

    /** @var MediaStreamTrack|null The media track being sent */
    private ?MediaStreamTrack $track = null;

    /** @var string|null The CNAME for RTCP */
    private ?string $cname = null;

    /** @var int The SSRC for RTP */
    private int $ssrc;

    /** @var int The SSRC for RTX (retransmissions) */
    private int $rtxSsrc;

    /** @var string The stream ID */
    private string $streamId;

    /** @var bool Whether the sender is enabled */
    private bool $enabled = true;

    /** @var EncoderInterface|null The encoder for media frames */
    private ?EncoderInterface $encoder = null;

    /** @var bool Whether to force the next frame to be a keyframe */
    private bool $useKeyframe = false;

    /** @var string|null The media ID (mid) for this sender */
    private ?string $mid = null;

    /** @var array<int, RtpPacket> History of sent RTP packets for retransmissions */
    private array $rtpHistory = [];

    /** @var RTCStatsReport Statistics for this sender */
    private RTCStatsReport $stats;

    /** @var float|null The last sender report timestamp */
    private ?float $lsrTime = null;

    /** @var int|null The last sender report NTP timestamp */
    private ?int $lsr = null;

    /** @var int|string The NTP timestamp for RTP */
    private int|string $ntpTimestamp = 0;

    /** @var int The RTP timestamp */
    private int $rtpTimestamp = 0;

    /** @var int The total number of octets sent */
    private int $octetCount = 0;

    /** @var int The total number of packets sent */
    private int $packetCount = 0;

    /** @var float|null The round-trip time (RTT) estimate */
    private ?float $rtt = null;

    /** @var bool Whether the sender has started */
    private bool $started = false;

    /** @var string The track ID */
    private string $trackId;

    /** @var HeaderExtensionsMap Map of RTP header extensions */
    private HeaderExtensionsMap $headerExtensionsMap;

    /** @var int|null The payload type for RTX packets */
    private ?int $rtxPayloadType = null;

    /** @var LoopInterface The event loop for RTCP tasks */
    private LoopInterface $rtcpLoop;

    /** @var LoopInterface The event loop for RTP tasks */
    private LoopInterface $rtpLoop;

    /** @var RTCRtpCodecParameters The codec parameters */
    private RTCRtpCodecParameters $codec;

    /** @var LoggerInterface|null The logger instance */
    private ?LoggerInterface $logger = null;

    /** @var TimerInterface The RTCP timer task */
    private TimerInterface $rtcpTask;

    /** @var TimerInterface The RTP timer task */
    private TimerInterface $rtpTask;

    /** @var int The current RTP sequence number */
    private int $sequenceNumber;

    /** @var int The current RTX sequence number */
    private int $rtxSequenceNumber;

    /** @var int The original timestamp offset */
    private int $orgTimestamp;

    /**
     * Constructs a new RTCRtpSender instance.
     *
     * @param MediaKind|MediaStreamTrack $trackOrKind Either a MediaStreamTrack or a media kind ("audio" or "video")
     * @param RTCRTPDtlsTransportInterface $transport The transport for sending media
     * @throws InvalidArgumentException If the transport is closed
     * @throws RandomException If random number generation fails
     */
    public function __construct(MediaKind|MediaStreamTrack $trackOrKind, private RTCRTPDtlsTransportInterface $transport)
    {
        if ($transport->getState() === TLSState::CLOSED) {
            throw new InvalidArgumentException("Transport is closed");
        }

        $this->rtcpLoop = Loop::get();
        $this->rtpLoop = Loop::get();
        if ($trackOrKind instanceof MediaStreamTrack) {
            $this->kind = $trackOrKind->getKind();
            $this->replaceTrack($trackOrKind);
        } else {
            $this->kind = $trackOrKind;
            $this->replaceTrack(null);
        }

        $this->ssrc = random_int(0, 0xFFFFFFFF);
        $this->rtxSsrc = random_int(0, 0xFFFFFFFF);
        $this->orgTimestamp = random_int(0, 0xFFFFFFFF);
        $this->sequenceNumber = $this->generateSequenceNumber();
        $this->rtxSequenceNumber = random_int(0, 0xFFFF);
        $this->streamId = Uuid::uuid4()->toString();
        $this->stats = new RTCStatsReport();
        $this->headerExtensionsMap = new HeaderExtensionsMap();
    }

    /**
     * Gets the kind of media for this sender.
     *
     * @return MediaKind The media kind ("audio" or "video")
     */
    public function getKind(): MediaKind
    {
        return $this->kind;
    }

    /**
     * Gets the media track being sent.
     *
     * @return MediaStreamTrack|null The media track or null if not set
     */
    public function getTrack(): ?MediaStreamTrack
    {
        return $this->track;
    }

    /**
     * Gets the transport used for sending media.
     *
     * @return RTCRTPDtlsTransportInterface The transport
     */
    public function getTransport(): RTCRTPDtlsTransportInterface
    {
        return $this->transport;
    }

    /**
     * Replaces the media track being sent.
     *
     * @param MediaStreamTrack|null $track The new media track or null to clear
     */
    public function replaceTrack(?MediaStreamTrack $track): void
    {
        $this->track = $track;
        $this->trackId = $track ? $track->getId() : Uuid::uuid4()->toString();
    }

    /**
     * Sets the transport used for sending media.
     *
     * @param RTCRTPDtlsTransportInterface $transport The new transport
     */
    public function setTransport(RTCRTPDtlsTransportInterface $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * Starts sending media with the given parameters.
     *
     * @param RTCRtpSendParameters $parameters The parameters for sending media
     * @throws RandomException If random number generation fails
     */
    public function start(RTCRtpSendParameters $parameters): void
    {
        if ($this->started) {
            return;
        }

        $this->cname = $parameters->rtcp->cname;
        $this->mid = $parameters->muxId;
        $this->codec = $parameters->codecs[0];
        $this->encoder = Codec::getEncoder($parameters->codecs[0]);
        $this->transport->setRtpSender($this, $parameters);
        $this->headerExtensionsMap->configure($parameters);

        foreach ($parameters->codecs as $codec) {
            if (CodecUtility::isRtx($codec) && $codec->parameters["apt"] === $parameters->codecs[0]->payloadType) {
                $this->rtxPayloadType = $codec->payloadType;
                break;
            }
        }

        // Start RTP and RTCP tasks
        $this->startRtpTask();
        $this->startRtcpTask();
        $this->started = true;
        $this->log(" RTP Sender started");
    }

    /**
     * Shuts down RTP and RTCP tasks.
     *
     * @throws SrtpException If there's an error with SRTP processing
     */
    public function stop(): void
    {
        if (!$this->started) {
            return;
        }

        $this->transport->removeRtpSender($this);

        // Stop RTCP and RTP Tasks
        $this->stopRtcpTask();
        $this->stopRtpTask();
    }

    /**
     * Stops the RTCP task and sends a BYE packet.
     *
     * @throws SrtpException If there's an error with SRTP processing
     */
    private function stopRtcpTask(): void
    {
        $this->rtcpLoop->cancelTimer($this->rtcpTask);
        $byePacket = new RtcpByePacket([$this->ssrc]);
        $this->sendRtcpPacket([$byePacket]);

        $this->log(" RTCP has ended.");
    }

    /**
     * Stops the RTP task and cleans up resources.
     */
    private function stopRtpTask(): void
    {
        if ($this->track) {
            $this->track->stop();
            $this->track = null;
        }
        $this->encoder = null;
        $this->rtpLoop->cancelTimer($this->rtpTask);

        $this->log(" RTP has ended.");
    }

    /**
     * Starts the periodic RTP packet sending a task.
     *
     * @throws RandomException
     */
    public function startRtpTask(): void
    {
        $this->log("Start RTP task");
        $this->sequenceNumber = $this->generateSequenceNumber();
        $this->orgTimestamp = random_int(0, 0xFFFFFFFF);
        $this->rtpTask = $this->rtpLoop->addPeriodicTimer(0, function () {
            if (!$this->track) {
                return;
            }
            $encodedFrame = $this->getNextEncodedFrame();
            if ($encodedFrame === null) {
                return;
            }

            for ($i = 0; $i < count($encodedFrame->getPayloads()); $i++) {
                $rtpPacket = $this->generateRtpPacket($encodedFrame, $i);
                $this->sendRtpPacket($rtpPacket);
                $this->updateStatistics($rtpPacket, $encodedFrame->getPayloads()[$i]);
                $this->sequenceNumber = ($this->sequenceNumber + 1) & 0xFFFF;
            }
        });
    }

    /**
     * Gets the next encoded frame from the track.
     *
     * @return RTCEncodedFrame|null The encoded frame or null if no frame is available
     */
    private function getNextEncodedFrame(): ?RTCEncodedFrame
    {
        if (!$this->enabled) {
            return null;
        }

        $audioLevel = null;

        $data = $this->track->receiveData();
        if ($data === null) {
            return null;
        }
        if ($data instanceof FrameInterface) {
            if ($data instanceof AudioFrame) {
                $audioLevel = RtpUtility::computeAudioLevelDbov($data->getPlanes()[0]->getData(), $data->getSamples());
            }
            $useKeyFrame = $this->useKeyframe;
            $this->useKeyframe = false;
            if ($this->encoder === null) {
                $this->encoder = Codec::getEncoder($this->codec);
            }
            [$payloads, $timestamp] = $this->encoder->encode($data, $useKeyFrame);
        } else {
            [$payloads, $timestamp] = $this->encoder->pack($data);
        }

        if (empty($payloads)) {
            return null;
        }

        return new RTCEncodedFrame($payloads, $timestamp, $audioLevel);
    }

    /**
     * Generates an RTP packet from an encoded frame.
     *
     * @param RTCEncodedFrame $encodedFrame The encoded frame
     * @param int $i The payload index
     * @return RtpPacket The generated RTP packet
     * @throws DateMalformedStringException If there's an error with timestamp generation
     */
    private function generateRtpPacket(RTCEncodedFrame $encodedFrame, int $i): RtpPacket
    {
        $packet = new RtpPacket();
        $packet->setPayloadType($this->codec->payloadType);
        $packet->setSequenceNumber($this->sequenceNumber);
        $packet->setTimestamp(($this->orgTimestamp + $encodedFrame->getTimestamp()) & 0xFFFFFFFF);
        $packet->setSsrc($this->ssrc);
        $packet->setPayload($encodedFrame->getPayloads()[$i]);
        $packet->setMarker(($i === count($encodedFrame->getPayloads()) - 1) ? 1 : 0);

        // Set header extensions
        $ntpTime = NetworkTimeProtocol::currentNtpTime();
        $gmpTime = gmp_init($ntpTime);
        $shifted = gmp_div_q($gmpTime, gmp_pow(2, 14));
        $masked = gmp_and($shifted, 0x00FFFFFF);
        $packet->getExtensions()->setAbsSendTime(gmp_intval($masked));
        $packet->getExtensions()->setMid($this->mid);
        if ($encodedFrame->getAudioLevel()) {
            $packet->getExtensions()->setAudioLevel([false, -$encodedFrame->getAudioLevel()]);
        }

        return $packet;
    }

    /**
     * Sends an RTP packet over the transport.
     *
     * @param RtpPacket $packet The RTP packet to send
     */
    private function sendRtpPacket(RtpPacket $packet): void
    {
        $this->log("Sending packet: $packet");
        $this->rtpHistory[$packet->getSequenceNumber() % RtpConstants::RTP_HISTORY_SIZE] = $packet;
        $packetBytes = $packet->encode($this->headerExtensionsMap);
        $this->transport->sendRtp($packetBytes);
    }

    /**
     * Updates statistics after sending an RTP packet.
     *
     * @param RtpPacket $packet The scent RTP packet
     * @param string $payload The packet payload
     * @throws DateMalformedStringException
     */
    private function updateStatistics(RtpPacket $packet, string $payload): void
    {
        $this->ntpTimestamp = NetworkTimeProtocol::currentNtpTime();
        $this->rtpTimestamp = $packet->getTimestamp();
        $this->octetCount += strlen($payload);
        $this->packetCount++;
    }

    /**
     * Starts the periodic RTCP packet sending a task.
     *
     * @throws RandomException If random number generation fails
     */
    private function startRtcpTask(): void
    {
        $this->log("RTCP started");

        $this->rtcpTask = $this->rtcpLoop->addPeriodicTimer(0.5 + (random_int(0, 1000) / 1000), function () {
            try {
                $rtcpPackets = $this->generateRtcpPackets();
                $this->sendRtcpPacket($rtcpPackets);
            } catch (RtcpExceptionInterface $e) {
                $this->log("RTCP error: " . $e->getMessage(), "warning");
            }
        });
    }

    /**
     * Generates RTCP packets for the current state.
     *
     * @return RtcpPacketInterface[] Array of RTCP packets to send
     */
    private function generateRtcpPackets(): array
    {
        $packets = [$this->generateRtcpSrPacket()];

        $gmp = gmp_init($this->ntpTimestamp);
        $shifted = gmp_div_q($gmp, gmp_pow(2, 16));
        $this->lsr = gmp_intval(gmp_and($shifted, "0xFFFFFFFF"));
        $this->lsrTime = microtime(true);

        // Generate RTCP SDES packet
        if ($this->cname !== null) {
            $packets[] = $this->generateRtcpSdesPacket();
        }

        return $packets;
    }

    /**
     * Generates an RTCP sender report (SR) packet.
     *
     * @return RtcpSrPacket The generated SR packet
     */
    private function generateRtcpSrPacket(): RtcpSrPacket
    {
        return new RtcpSrPacket(
            ssrc: $this->ssrc,
            senderInfo: new RtcpSenderInfo(
                ntpTimestamp: $this->ntpTimestamp,
                rtpTimestamp: $this->rtpTimestamp,
                packetCount: $this->packetCount,
                octetCount: $this->octetCount
            )
        );
    }

    /**
     * Generates an RTCP source description (SDES) packet.
     *
     * @return RtcpSdesPacket The generated SDES packet
     */
    private function generateRtcpSdesPacket(): RtcpSdesPacket
    {
        return new RtcpSdesPacket([
            new RtcpSourceInfo(
                ssrc: $this->ssrc,
                items: [[1, $this->cname]]
            )
        ]);
    }

    /**
     * Sends RTCP packets over the transport.
     *
     * @param array<RtcpPacketInterface> $packets The RTCP packets to send
     */
    private function sendRtcpPacket(array $packets): void
    {
        $payload = "";
        foreach ($packets as $packet) {
            $this->log("Sending RTCP packet: $packet");
            $payload .= $packet->encode();
        }

        try {
            $this->transport->sendRtcp($payload);
        } catch (Throwable $e) {
            $this->log("Failed to send RTCP: " . $e->getMessage(), "warning");
        }
    }

    /**
     * Handles an incoming RTCP packet.
     *
     * @param RtcpPacketInterface $packet The received RTCP packet
     * @throws Throwable If there's an error with DTLS
     * @throws SrtpException If there's an error with SRTP
     */
    public function handleRtcpPacket(RtcpPacketInterface $packet): void
    {
        if ($packet instanceof RtcpRrPacket || $packet instanceof RtcpSrPacket) {
            $this->handleReportRtcpPacket($packet);
        } elseif ($packet instanceof RtcpRtpfbPacket && $packet->getFmt() === RtcpConstants::RTCP_RTPFB_NACK) {
            foreach ($packet->getLost() as $seq) {
                $this->retransmit($seq);
            }
        } elseif ($packet instanceof RtcpPsfbPacket && $packet->getFmt() === RtcpConstants::RTCP_PSFB_PLI) {
            $this->sendKeyframe();
        } elseif ($packet instanceof RtcpPsfbPacket && $packet->getFmt() === RtcpConstants::RTCP_PSFB_APP) {
            $this->changeMediaBitrate($packet);
        }
    }

    /**
     * Handles RTCP report packets (RR or SR).
     *
     * @param RtcpSrPacket|RtcpRrPacket $packet The report packet
     */
    private function handleReportRtcpPacket(RtcpSrPacket|RtcpRrPacket $packet): void
    {
        foreach ($packet->getReports() as $report) {
            if ($report->getSsrc() === $this->ssrc && $report->getDlsr() !== null) {
                // Estimate round-trip time
                $rtt = microtime(true) - $this->lsrTime - ($report->getDlsr() / 65536);
                $this->rtt = $this->rtt === null ? $rtt : self::RTT_ALPHA * $this->rtt + (1 - self::RTT_ALPHA) * $rtt;

                // Update statistics
                $this->stats->add(new RTCRemoteInboundRtpStreamStats(
                    id: "remote_inbound_rtp_stream_" . spl_object_id($this),
                    ssrc: $packet->getSsrc(),
                    kind: $this->kind->value,
                    transportId: $this->transport->getReportTransport()->id,
                    packetsReceived: $this->packetCount - $report->getPacketsLost(),
                    packetsLost: $report->getPacketsLost(),
                    jitter: $report->getJitter(),
                    roundTripTime: $this->rtt,
                    fractionLost: $report->getFractionLost()
                ));
            }
        }
    }

    /**
     * Retransmits an RTP packet identified by sequence number.
     *
     * @param int $sequenceNumber The sequence number of the packet to retransmit
     * @throws RandomException
     * @throws SrtpException
     * @throws Throwable
     */
    public function retransmit(int $sequenceNumber): void
    {
        $packet = $this->rtpHistory[$sequenceNumber % RtpConstants::RTP_HISTORY_SIZE] ?? null;
        if ($packet && $packet->getSequenceNumber() === $sequenceNumber) {
            if ($this->rtxPayloadType !== null) {
                $packet = RtpUtility::wrapRtx(
                    $packet,
                    payloadType: $this->rtxPayloadType,
                    sequenceNumber: $this->rtxSequenceNumber,
                    ssrc: $this->rtxSsrc
                );
                $this->rtxSequenceNumber = ($this->rtxSequenceNumber + 1) & 0xFFFF;
            }

            $this->log("Retransmitting packet: $packet");
            $packetBytes = $packet->encode($this->headerExtensionsMap);
            $this->transport->sendRtp($packetBytes);
        }
    }

    /**
     * Adjusts media bitrate based on REMB feedback.
     *
     * @param RtcpPsfbPacket $packet The REMB feedback packet
     */
    private function changeMediaBitrate(RtcpPsfbPacket $packet): void
    {
        try {
            [$bitrate, $ssrcs] = RtpUtility::unpackRembFci($packet->getFci());
            if (in_array($this->ssrc, $ssrcs)) {
                $this->log("Receiver estimated maximum bitrate: $bitrate bps");
                $this->encoder?->setBitrate($bitrate);
            }
        } catch (Exception) {
            // Ignore invalid REMB packets
        }
    }

    /**
     * Logs a message using the configured logger.
     *
     * @param string $message The message to log
     * @param string $level The log level (default: "debug")
     */
    private function log(string $message, string $level = "debug"): void
    {
        $this->logger?->{$level}("RTCRtpSender({$this->kind->value}): $message");
    }

    /**
     * Checks if the next frame should be a keyframe.
     *
     * @return bool True if the next frame should be a keyframe
     */
    public function isUseKeyframe(): bool
    {
        return $this->useKeyframe;
    }

    /**
     * Gets the last sender report NTP timestamp.
     *
     * @return int|null The NTP timestamp or null if not available
     */
    public function getLsr(): ?int
    {
        return $this->lsr;
    }

    /**
     * Gets the track ID.
     *
     * @return string The track ID
     */
    public function getTrackId(): string
    {
        return $this->trackId;
    }

    /**
     * Gets the header extensions map.
     *
     * @return HeaderExtensionsMap The header extensions map
     */
    public function getHeaderExtensionsMap(): HeaderExtensionsMap
    {
        return $this->headerExtensionsMap;
    }

    /**
     * Sets the logger instance.
     *
     * @param LoggerInterface|null $logger The logger instance
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Checks if the sender is enabled.
     *
     * @return bool True if the sender is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Sets whether the sender is enabled.
     *
     * @param bool $enabled True to enable the sender
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    /**
     * Gets the SSRC for RTP packets.
     *
     * @return int The SSRC
     */
    public function getSsrc(): int
    {
        return $this->ssrc;
    }

    /**
     * Requests the next frame to be a keyframe.
     */
    public function sendKeyframe(): void
    {
        $this->useKeyframe = true;
    }

    /**
     * Gets the stream ID.
     *
     * @return string The stream ID
     */
    public function getStreamId(): string
    {
        return $this->streamId;
    }

    /**
     * Destructor - stops the sender when the object is destroyed.
     * @throws SrtpException
     */
    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Gets the current statistics report.
     *
     * @return RTCStatsReport The statistics report
     */
    public function getStats(): RTCStatsReport
    {
        $this->stats->add(new RTCOutboundRtpStreamStats(
            id: "outbound_rtp_stream_" . spl_object_id($this),
            type: StatType::OutboundRtpStream,
            ssrc: $this->ssrc,
            kind: $this->kind->value,
            transportId: $this->transport->getReportTransport()->id,
            packetsSent: $this->packetCount,
            bytesSent: $this->octetCount,
            trackId: $this->trackId
        ));
        $this->stats->add($this->transport->getReportTransport());

        return $this->stats;
    }

    /**
     * Sets the SSRC for RTP packets.
     *
     * @param int $ssrc The SSRC to set
     */
    public function setSsrc(int $ssrc): void
    {
        $this->ssrc = $ssrc;
    }

    /**
     * Sets the SSRC for RTX packets.
     *
     * @param int $rtxSsrc The RTX SSRC to set
     */
    public function setRtxSsrc(int $rtxSsrc): void
    {
        $this->rtxSsrc = $rtxSsrc;
    }

    /**
     * Sets the stream ID.
     *
     * @param string $streamId The stream ID to set
     */
    public function setStreamId(string $streamId): void
    {
        $this->streamId = $streamId;
    }

    /**
     * Gets the SSRC for RTX packets.
     *
     * @return int The RTX SSRC
     */
    public function getRtxSsrc(): int
    {
        return $this->rtxSsrc;
    }

    /**
     * Generates a random sequence number for RTP packets.
     *
     * @return int The generated sequence number
     * @throws RandomException
     */
    private function generateSequenceNumber(): int
    {
        $bytes = random_bytes(2);
        $unpacked = unpack('n', $bytes);

        return $unpacked[1] % 32768;
    }
}
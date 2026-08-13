<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Webrtc;

use DateInvalidOperationException;
use Evenement\EventEmitter;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Random\RandomException;
use React\Promise\PromiseInterface;
use Throwable;
use Webrtc\Codecs\Codec;
use Webrtc\Codecs\CodecUtility;
use Webrtc\DataChannel\RTCDataChannel;
use Webrtc\DataChannel\RTCDataChannelParameters;
use Webrtc\DTLS\Exception\DTLSException;
use Webrtc\DTLS\Exception\RTCCertificateException;
use Webrtc\DTLS\Exception\TLSException;
use Webrtc\DTLS\RTCCertificate;
use Webrtc\DTLS\RTCDtlsTransport;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;
use Webrtc\ICE\Enum\IceGatheringState;
use Webrtc\ICE\Enum\IceRole;
use Webrtc\ICE\Enum\IceTransportState;
use Webrtc\ICE\RTCIceCandidate;
use Webrtc\ICE\RTCIceGatherer;
use Webrtc\ICE\RTCIceParameters;
use Webrtc\ICE\RTCIceTransport;
use Webrtc\NTP\NetworkTimeProtocol;
use Webrtc\RTP\Enum\MediaKind;
use Webrtc\RTP\MediaStreamTrack\MediaStreamTrack;
use Webrtc\RTP\MediaStreamTrack\RemoteStreamTrack;
use Webrtc\RTP\MediaStreamTrack\RTCTrackEvent;
use Webrtc\RTP\Receiver\RTCRtpReceiver;
use Webrtc\RTP\RTCRtpTransceiver;
use Webrtc\RTP\RtpConstants;
use Webrtc\RTP\Sender\RTCRtpSender;
use Webrtc\RTPParameter\RTCRtcpFeedback;
use Webrtc\RTPParameter\RTCRtpCodecCapability;
use Webrtc\RTPParameter\RTCRtpCodecParameters;
use Webrtc\RTPParameter\RTCRtpDecodingParameters;
use Webrtc\RTPParameter\RTCRtpHeaderExtensionParameters;
use Webrtc\RTPParameter\RTCRtpParameters;
use Webrtc\RTPParameter\RTCRtpReceiveParameters;
use Webrtc\RTPParameter\RTCRtpRtxParameters;
use Webrtc\RTPParameter\RTCRtpSendParameters;
use Webrtc\SCTP\Exception\SctpException;
use Webrtc\SCTP\RTCSctpTransport;
use Webrtc\SDP\DtlsParameter\RTCDtlsParameters;
use Webrtc\SDP\Enum\DtlsRole;
use Webrtc\SDP\Enum\H264Profile;
use Webrtc\SDP\Enum\SDPDirections;
use Webrtc\SDP\GroupDescription;
use Webrtc\SDP\H264Sdp;
use Webrtc\SDP\MediaDescription;
use Webrtc\SDP\RTCSessionDescription;
use Webrtc\SDP\SessionDescription;
use Webrtc\SDP\SsrcDescription;
use Webrtc\Srtp\Exception\SrtpException;
use Webrtc\SSL\Exception\OpenSSLException;
use Webrtc\SSL\Exception\SSLException;
use Webrtc\SSL\Exception\SysCallException;
use Webrtc\SSL\Exception\WantReadException;
use Webrtc\SSL\Exception\WantWriteException;
use Webrtc\SSL\Exception\WantX509LookupException;
use Webrtc\SSL\Exception\ZeroReturnException;
use Webrtc\Stats\enum\TLSState;
use Webrtc\Stats\RTCStatsReport;
use Webrtc\Webrtc\Enum\ConnectionState;
use Webrtc\Webrtc\Enum\IceConnectionState;
use Webrtc\Webrtc\Enum\SignalingState;
use function React\Async\async;
use function React\Async\await;
use function React\Async\delay;

/**
 * RTCPeerConnection represents a WebRTC connection between the local device and a remote peer.
 *
 * This class implements the RTCPeerConnectionInterface and provides methods to create and manage
 * WebRTC connections, including media streaming and data channels.
 *
 * Key Features:
 * - Manages ICE candidates for network connectivity
 * - Handles DTLS for secure communication
 * - Supports media tracks (audio/video)
 * - Provides data channel functionality
 * - Manages connection state and signaling
 *
 * Events:
 * - "connectionstatechange": Fired when the connection state changes
 * - "iceconnectionstatechange": Fired when the ICE connection state changes
 * - "icegatheringstatechange": Fired when the ICE gathering state changes
 * - "signalingstatechange": Fired when the signaling state changes
 * - "track": Fired when a new media track is received
 * - "datachannel": Fired when a new data channel is created by the remote peer
 */
class RTCPeerConnection extends EventEmitter implements RTCPeerConnectionInterface
{
    /**
     * Port number used for discard protocol (used as placeholder in SDP)
     * @var int
     */
    private const DISCARD_PORT = 9;

    /**
     * IP address used as placeholder in SDP before real candidates are gathered
     * @var string
     */
    private const DISCARD_HOST = "0.0.0.0";

    /**
     * Configuration object containing ICE servers, certificates, and other settings
     */
    private ?RTCConfiguration $configuration;

    /**
     * List of RTCCertificate objects used for DTLS handshake
     * @var list<RTCCertificate>
     */
    private array $certificates = [];

    /**
     * Canonical Name (CNAME) used in RTCP packets for synchronization
     */
    private string $cname;

    /**
     * List of all active DTLS transports for media and data channels
     * @var list<RTCDtlsTransport>
     */
    private array $dtlsTransports = [];

    /**
     * List of all active ICE transports for network connectivity
     * @var list<RTCIceTransport>
     */
    private array $iceTransports = [];

    /**
     * Remote DTLS parameters indexed by transport object ID
     * @var array<int, RTCDtlsParameters>
     */
    private array $remoteDtlsParameter = [];

    /**
     * Remote ICE parameters indexed by transport object ID
     * @var array<int, RTCIceParameters>
     */
    private array $remoteIceParameters = [];

    /**
     * List of media stream identifiers (MIDs) that have been processed
     * @var list<string>
     */
    private array $seenMids = [];

    /**
     * SCTP transport for data channels (null until first data channel is created)
     */
    private ?RTCSctpTransport $sctp = null;

    /**
     * Flag indicating whether to use legacy SDP format for SCTP
     */
    private bool $sctpLegacySdp = true;

    /**
     * Remote SCTP port number (null until set by remote description)
     */
    private ?int $sctpRemotePort = null;

    /**
     * Unique identifier for this peer connection's media streams
     */
    private string $streamId;

    /**
     * List of all media transceivers in this connection
     * @var list<RTCRtpTransceiver>
     */
    private array $transceivers = [];

    /**
     * Overall connection state (connected, disconnected, failed, etc.)
     * @var ConnectionState
     */
    private ConnectionState $connectionState = ConnectionState::new;

    /**
     * ICE connection state (checking, completed, failed, etc.)
     * @var IceConnectionState
     */
    private IceConnectionState $iceConnectionState = IceConnectionState::new;

    /**
     * ICE candidate gathering state (new, gathering, complete)
     * @var IceGatheringState
     */
    private IceGatheringState $iceGatheringState = IceGatheringState::new;

    /**
     * Signaling state for offer/answer negotiation
     * @var SignalingState
     */
    private SignalingState $signalingState = SignalingState::stable;

    /**
     * Flag indicating whether the connection has been closed
     */
    private bool $isClosed = false;

    /**
     * Current local description that has been successfully negotiated
     */
    private ?SessionDescription $currentLocalDescription = null;

    /**
     * Current remote description that has been successfully negotiated
     */
    private ?SessionDescription $currentRemoteDescription = null;

    /**
     * Local description that is pending negotiation
     */
    private ?SessionDescription $pendingLocalDescription = null;

    /**
     * Remote description that is pending negotiation
     */
    private ?SessionDescription $pendingRemoteDescription = null;

    /**
     * Logger instance for debugging output (optional)
     */
    private ?LoggerInterface $logger = null;

    /**
     * Creates a new RTCPeerConnection instance.
     *
     * @param array|RTCConfiguration|null $configuration Configuration options for the connection
     * @throws RTCCertificateException If certificate generation fails
     * @throws OpenSSLException|DateInvalidOperationException If there's an SSL-related error
     */
    public function __construct(null|array|RTCConfigurationInterface $configuration = null)
    {
        $this->configuration = $configuration instanceof RTCConfigurationInterface ? $configuration : new RTCConfiguration($configuration);
        $this->certificates[] = new RTCCertificate($this->configuration->getPrivateKeyPath(), $this->configuration->getCertificatePath());
        $this->cname = Uuid::uuid4()->toString();
        $this->streamId = Uuid::uuid4()->toString();
    }

    /**
     * Gets the current connection state.
     *
     * @return ConnectionState The current connection state
     */
    public function getConnectionState(): ConnectionState
    {
        return $this->connectionState;
    }

    /**
     * Sets the connection state and emits "connectionstatechange" event if changed.
     *
     * @param ConnectionState $connectionState The new connection state
     */
    public function setConnectionState(ConnectionState $connectionState): void
    {
        $this->connectionState = $connectionState;
    }

    /**
     * Gets the current ICE connection state.
     *
     * @return IceConnectionState The current ICE connection state
     */
    public function getIceConnectionState(): IceConnectionState
    {
        return $this->iceConnectionState;
    }

    /**
     * Sets the ICE connection state and emits "iceconnectionstatechange" event if changed.
     *
     * @param IceConnectionState $iceConnectionState The new ICE connection state
     */
    public function setIceConnectionState(IceConnectionState $iceConnectionState): void
    {
        $this->iceConnectionState = $iceConnectionState;
    }

    /**
     * Gets the current ICE gathering state.
     *
     * @return IceGatheringState The current ICE gathering state
     */
    public function getIceGatheringState(): IceGatheringState
    {
        return $this->iceGatheringState;
    }

    /**
     * Sets the ICE gathering state and emits "icegatheringstatechange" event if changed.
     *
     * @param IceGatheringState $iceGatheringState The new ICE gathering state
     */
    public function setIceGatheringState(IceGatheringState $iceGatheringState): void
    {
        $this->iceGatheringState = $iceGatheringState;
    }

    /**
     * Gets the local description of the connection.
     *
     * @return RtcSessionDescription|null The local session description or null if not set
     */
    public function getLocalDescription(): ?RtcSessionDescription
    {
        $sdp = $this->pendingLocalDescription ?? $this->currentLocalDescription ?? null;
        if (!$sdp) {
            return null;
        }

        return new RtcSessionDescription((string)$sdp, $sdp->getType());
    }

    /**
     * Gets the remote description of the connection.
     *
     * @return RtcSessionDescription|null The remote session description or null if not set
     */
    public function getRemoteDescription(): ?RtcSessionDescription
    {
        $sdp = $this->pendingRemoteDescription ?? $this->currentRemoteDescription ?? null;
        if (!$sdp) {
            return null;
        }

        return new RtcSessionDescription((string)$sdp, $sdp->getType());
    }

    /**
     * Gets the SCTP transport used for data channels.
     *
     * @return RTCSctpTransport|null The SCTP transport or null if not established
     */
    public function getSctp(): ?RTCSctpTransport
    {
        return $this->sctp;
    }

    /**
     * Sets the SCTP transport for data channels.
     *
     * @param RTCSctpTransport|null $sctp The SCTP transport to set
     */
    public function setSctp(?RTCSctpTransport $sctp): void
    {
        $this->sctp = $sctp;
    }

    /**
     * Gets the current signaling state.
     *
     * @return SignalingState The current signaling state
     */
    public function getSignalingState(): SignalingState
    {
        return $this->signalingState;
    }

    /**
     * Sets the signaling state and emits "signalingstatechange" event.
     *
     * @param SignalingState $signalingState The new signaling state
     */
    public function setSignalingState(SignalingState $signalingState): void
    {
        $this->signalingState = $signalingState;
        $this->emit("signalingstatechange");
    }

    /**
     * Sets a logger for debugging purposes.
     *
     * @param LoggerInterface $logger The logger instance
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Adds an ICE candidate received from the remote peer.
     *
     * @param RTCIceCandidate $candidate The ICE candidate to add
     * @throws InvalidArgumentException If a candidate is missing required fields
     */
    public function addIceCandidate(RTCIceCandidate $candidate): void
    {
        if ($candidate->getSdpMid() === null && $candidate->getSdpMLineIndex() === null) {
            throw new InvalidArgumentException("Candidate must have either sdpMid or sdpMLineIndex");
        }

        foreach ($this->transceivers as $transceiver) {
            if ($candidate->getSDPMid() == $transceiver->getMid() && !$transceiver->isBundled()) {
                $iceTransport = $transceiver->getDtlsTransport()->getIceTransport();
                $iceTransport->addRemoteCandidate($candidate);
                return;
            }
        }

        if ($this->sctp && $candidate->getSDPMid() == $this->sctp->getMid() && !$this->sctp->isBundled()) {
            $iceTransport = $this->sctp->getDtlsTransport()->getIceTransport();
            $iceTransport->addRemoteCandidate($candidate);
        }
    }

    /**
     * Adds a media track to be sent to the remote peer.
     *
     * @param MediaStreamTrack $track The media track to add
     * @return RTCRtpSender The sender associated with the track
     * @throws OpenSSLException
     * @throws RandomException
     * @throws SrtpException
     */
    public function addTrack(MediaStreamTrack $track): RTCRtpSender
    {
        $this->checkNotClosed();

        if (!in_array($track->getKind(), [MediaKind::Audio, MediaKind::Video])) {
            throw new InvalidArgumentException("Invalid track kind {$track->getKind()->value}");
        }

        $this->checkTrackHasNoSender($track);

        foreach ($this->transceivers as $transceiver) {
            if ($transceiver->getKind() === $track->getKind() && !$transceiver->getSender()->getTrack()) {
                $transceiver->getSender()->replaceTrack($track);
                $transceiver->setDirection(SDPDirections::tryFrom($transceiver->getDirection()->value | SDPDirections::sendonly->value));
                return $transceiver->getSender();
            }
        }
        $transceiver = $this->createTransceiver($track->getKind(), SDPDirections::sendrecv, $track);

        return $transceiver->getSender();
    }

    /**
     * Adds a new transceiver to the connection.
     *
     * @param MediaKind|MediaStreamTrack $trackOrKind Either a media kind or track
     * @param SDPDirections $direction The direction of the transceiver
     * @return RTCRtpTransceiver The created transceiver
     * @throws OpenSSLException
     * @throws RandomException
     * @throws SrtpException
     */
    public function addTransceiver(MediaKind|MediaStreamTrack $trackOrKind, SDPDirections $direction = SDPDirections::sendrecv): RTCRtpTransceiver
    {
        $this->checkNotClosed();

        if ($trackOrKind instanceof MediaStreamTrack) {
            $kind = $trackOrKind->getKind();
            $track = $trackOrKind;
        } else {
            $kind = $trackOrKind;
            $track = null;
        }

        if (!in_array($kind, [MediaKind::Audio, MediaKind::Video])) {
            throw new RuntimeException("Invalid track kind $kind->value");
        }

        if ($track) {
            $this->checkTrackHasNoSender($track);
        }

        return $this->createTransceiver($kind, $direction, $track);
    }

    /**
     * Closes the peer connection and terminates all transports.
     *
     * @throws OpenSSLException
     * @throws SrtpException If there's an error stopping SRTP
     * @throws TLSException
     * @throws SSLException
     * @throws SysCallException
     * @throws WantReadException
     * @throws WantWriteException
     * @throws WantX509LookupException
     * @throws ZeroReturnException
     */
    public function close(): void
    {
        if ($this->isClosed) {
            return;
        }

        foreach ($this->transceivers as $transceiver) {
            $transceiver->stop();
            $transceiver->getDtlsTransport()->stop()
                ->then(function () use ($transceiver) {
                    delay(.01);
                    $transceiver->getDtlsTransport()->getIceTransport()->stop();
                });
        }

        if (isset($this->sctp)) {
            $this->sctp->stop();
            $this->sctp->getDtlsTransport()->stop()
                ->then(function () {
                    delay(.01);
                    $this->sctp->getDtlsTransport()->getIceTransport()->stop();
                });
        }

        $this->isClosed = true;
        $this->setSignalingState(SignalingState::closed);

        $this->updateIceGatheringState();
        $this->updateIceConnectionState();
        $this->updateConnectionState();

        $this->removeAllListeners();
    }

    /**
     * Creates an SDP answer to a remote offer.
     *
     * @return PromiseInterface<RTCSessionDescription> Promise that resolves with the answer
     * @throws InvalidArgumentException If called in invalid signaling state
     */
    public function createAnswer(): PromiseInterface
    {
        return async(function () {
            $this->checkNotClosed();

            if (!in_array($this->signalingState, [SignalingState::haveRemoteOffer, SignalingState::haveLocalPranswer])) {
                throw new InvalidArgumentException("Cannot create answer in signaling state {$this->signalingState->name}");
            }

            [$sessionDescription, $groupDescription] = $this->initSessionDescription("answer");

            $remoteDescription = $this->pendingRemoteDescription ?? $this->currentRemoteDescription;

            foreach ($remoteDescription->getMedia() as $remoteMediaStream) {
                if (in_array($remoteMediaStream->getKind(), ["audio", "video"])) {
                    $transceiver = $this->getTransceiverByMid($remoteMediaStream->getRTP()->muxId);
                    $mediaDescription = $this->createMediaDescriptionForTransceiver($transceiver);
                    $dtlsTransport = $transceiver->getDtlsTransport();
                } else {
                    $mediaDescription = $this->createMediaDescriptionForSctp();
                    $dtlsTransport = $this->sctp->getDtlsTransport();
                }

                $mediaDescription->getDtls()->role = $dtlsTransport->getRole() == DtlsRole::Auto ? DtlsRole::Client : $dtlsTransport->getRole();
                $sessionDescription->addMedia($mediaDescription);
                $groupDescription->items[] = $mediaDescription->getRtp()->muxId;
            }

            $sessionDescription->addGroup($groupDescription);

            return new RTCSessionDescription((string)$sessionDescription, $sessionDescription->getType());
        })();
    }

    /**
     * Creates a media description for a transceiver.
     *
     * @param RTCRtpTransceiver $transceiver The transceiver to describe
     * @param string|null $mid The media identifier
     * @return MediaDescription The created media description
     * @throws OpenSSLException
     */
    private function createMediaDescriptionForTransceiver(RTCRtpTransceiver $transceiver, ?string $mid = null): MediaDescription
    {
        $mediaDescription = new MediaDescription($transceiver->getKind()->value, self::DISCARD_PORT, "UDP/TLS/RTP/SAVPF", array_map(fn($codec) => $codec->payloadType, $transceiver->getCodecs()));
        $mediaDescription->setDirection(SDPDirections::tryFrom($transceiver->getDirection()->value & $transceiver->getOfferDirection()->value) ?? SDPDirections::sendonly);
        $mediaDescription->setMsid("{$transceiver->getSender()->getStreamId()} {$transceiver->getSender()->getTrackId()}");
        $mediaDescription->setRtp(new RTCRtpParameters($transceiver->getCodecs(), $transceiver->getHeaderExtensions(), $mid ?? $transceiver->getMid()));
        $mediaDescription->setRtcpHost(self::DISCARD_HOST);
        $mediaDescription->setRtcpPort(self::DISCARD_PORT);
        $mediaDescription->setRtcpMux(true);
        $mediaDescription->setSsrc([new SsrcDescription($transceiver->getSender()->getSsrc(), $this->cname)]);

        $rtxCodecs = array_filter($mediaDescription->getRtp()->codecs, fn($codec) => CodecUtility::isRtx($codec));
        if (!empty($rtxCodecs)) {
            $mediaDescription->addSsrc(new SsrcDescription($transceiver->getSender()->getRtxSsrc(), $this->cname));
            $mediaDescription->setSsrcGroup([new GroupDescription("FID", [$transceiver->getSender()->getSsrc(), $transceiver->getSender()->getRtxSsrc()])]);
        }

        $this->addTransportDescription($mediaDescription, $transceiver->getDtlsTransport());

        return $mediaDescription;
    }

    /**
     * Adds transport information to a media description.
     *
     * @param MediaDescription $mediaDescription The description to augment
     * @param RTCDtlsTransport $dtlsTransport The transport to describe
     * @throws OpenSSLException
     */
    private function addTransportDescription(MediaDescription $mediaDescription, RTCDtlsTransport $dtlsTransport): void
    {
        // ice
        $iceTransport = $dtlsTransport->getIceTransport();
        $iceGatherer = $iceTransport->getIceGatherer();
        $mediaDescription->setIceCandidates($iceGatherer->getLocalCandidates());
        $mediaDescription->setIceCandidatesComplete($iceGatherer->getState() === IceGatheringState::complete);
        $mediaDescription->setIce($iceGatherer->getLocalParameters());

        if (!empty($mediaDescription->getIceCandidates())) {
            $mediaDescription->setHost($mediaDescription->getIceCandidates()[0]->getHost());
            $mediaDescription->setPort($mediaDescription->getIceCandidates()[0]->getPort());
        } else {
            $mediaDescription->setHost(self::DISCARD_HOST);
            $mediaDescription->setPort(self::DISCARD_PORT);
        }

        // dtls
        if (!$mediaDescription->getDtls()) {
            $mediaDescription->setDtls($dtlsTransport->getLocalParameters());
        } else {
            $mediaDescription->getDtls()->fingerprints = $dtlsTransport->getLocalParameters()->fingerprints;
        }
    }

    /**
     * Gets a transceiver by its media identifier.
     *
     * @param string $muxId The media identifier
     * @return RTCRtpTransceiver|null The transceiver or null if not found
     */
    private function getTransceiverByMid(string $muxId): ?RTCRtpTransceiver
    {
        $found = null;
        foreach ($this->transceivers as $transceiver) {
            if ($transceiver->getMid() === $muxId) {
                $found = $transceiver;
                break;
            }
        }
        return $found;
    }

    /**
     * Gets a transceiver by its media line index.
     *
     * @param int $index The media line index
     * @return RTCRtpTransceiver|null The transceiver or null if not found
     */
    private function getTransceiverByMLineIndex(int $index): ?RTCRtpTransceiver
    {
        $found = null;
        foreach ($this->transceivers as $transceiver) {
            if ($transceiver->getMlineIndex() === $index) {
                $found = $transceiver;
                break;
            }
        }
        return $found;
    }

    /**
     * Creates a media description for the SCTP transport.
     *
     * @param string|null $mid The media identifier
     * @return MediaDescription The created media description
     * @throws OpenSSLException
     */
    private function createMediaDescriptionForSctp(?string $mid = null): MediaDescription
    {
        if ($this->sctpLegacySdp) {
            $mediaDescription = new MediaDescription("application", self::DISCARD_PORT, "DTLS/SCTP", [$this->sctp->getPort()]);
            $mediaDescription->setSctpMap([$this->sctp->getPort() => "webrtc-datachannel {$this->sctp->getOutboundStreamsCount()}"]);
        } else {
            $mediaDescription = new MediaDescription("application", self::DISCARD_PORT, "UDP/DTLS/SCTP", ["webrtc-datachannel"]);
            $mediaDescription->setSctpPort($this->sctp->getPort());
        }

        $mediaDescription->getRtp()->muxId = $mid ?? $this->sctp->getMid();
        $mediaDescription->setSctpCapabilities($this->sctp->getCapabilities());

        $this->addTransportDescription($mediaDescription, $this->sctp->getDtlsTransport());

        return $mediaDescription;
    }

    /**
     * Creates a data channel for sending arbitrary data.
     *
     * @param RTCDataChannelParameters $parameters Configuration for the data channel
     * @return RTCDataChannel The created data channel
     * @throws OpenSSLException
     * @throws RandomException
     * @throws SctpException
     * @throws SrtpException
     */
    public function createDataChannel(RTCDataChannelParameters $parameters): RTCDataChannel
    {
        if ($parameters->maxPacketLifeTime !== null && $parameters->maxRetransmits !== null) {
            throw new InvalidArgumentException("Cannot specify both maxPacketLifeTime and maxRetransmits");
        }

        if (!$this->sctp) {
            $this->createSctpTransport();
        }

        return new RTCDataChannel($this->sctp, $parameters);
    }

    /**
     * Creates an SCTP transport if none exists.
     *
     * @throws OpenSSLException
     * @throws RandomException If random number generation fails
     * @throws SctpException If SCTP transport creation fails
     * @throws SrtpException
     */
    private function createSctpTransport(): void
    {
        $this->sctp = new RTCSctpTransport($this->createDtlsTransport());

        $this->sctp->on("datachannel", function ($dataChannel): void {
            $this->emit("datachannel", [$dataChannel]);
        });
    }

    /**
     * Creates a DTLS transport.
     *
     * @return RTCDtlsTransport The created DTLS transport
     * @throws OpenSSLException If SSL operation fails
     * @throws RandomException|SrtpException If random number generation fails
     */
    private function createDtlsTransport(): RTCDtlsTransport
    {
        // create ICE transport
        $iceGatherer = new RTCIceGatherer($this->configuration->getIceServers(), $this->configuration->iceSettings(), $this->logger);
        $iceGatherer->on("statechange", fn() => $this->updateIceGatheringState());
        $iceTransport = new RTCIceTransport($iceGatherer, $this->logger);
        $iceTransport->on("statechange", fn() => $this->updateIceConnectionState());
        $iceTransport->on("statechange", fn() => $this->updateConnectionState());
        $this->iceTransports[spl_object_id($iceTransport)] = $iceTransport;

        // create DTLS transport
        $dtlsTransport = new RTCDtlsTransport($iceTransport, $this->certificates[0]);
        $dtlsTransport->on("statechange", fn() => $this->updateConnectionState());
        $this->dtlsTransports[spl_object_id($dtlsTransport)] = $dtlsTransport;

        //update states
        $this->updateIceGatheringState();
        $this->updateIceConnectionState();
        $this->updateConnectionState();

        return $dtlsTransport;
    }

    /**
     * Creates an SDP offer to initiate a connection.
     *
     * @return PromiseInterface<RTCSessionDescription> Promise that resolves with the offer
     * @throws RuntimeException If called with no media or data channels
     */
    public function createOffer(): PromiseInterface
    {
        return async(function () {
            $this->checkNotClosed();

            if (!$this->sctp && empty($this->transceivers)) {
                throw new RuntimeException("Cannot create an offer with no media and no data channels");
            }

            $codec = new Codec();
            foreach ($this->transceivers as $transceiver) {
                $transceiver->setCodecs($this->findPreferredCodecs($codec->getCodecs($transceiver->getKind()->value), $transceiver->getPreferredCodecs()));
                $transceiver->setheaderExtensions($codec->getHeaderExtensions($transceiver->getKind()->value));
            }
            $mids = $this->seenMids;

            [$sessionDescription, $groupDescription] = $this->initSessionDescription("offer");

            $remoteDescriptionMedias = ($this->pendingRemoteDescription ?? $this->currentRemoteDescription)?->getMedia() ?? [];
            $localDescriptionMedias = ($this->pendingLocalDescription ?? $this->currentLocalDescription)?->getMedia() ?? [];

            for ($i = 0; $i < max(count($remoteDescriptionMedias), count($localDescriptionMedias)); $i++) {
                $mediaKind = ($localDescriptionMedias[$i] ?? $remoteDescriptionMedias[$i])?->getKind();
                $mid = ($localDescriptionMedias[$i] ?? $remoteDescriptionMedias[$i])?->getRtp()?->muxId;
                if (in_array($mediaKind, ["audio", "video"])) {
                    $transceiver = $this->getTransceiverByMid($mid);
                    $transceiver->setMlineIndex($i);
                    $mediaDescription = $this->createMediaDescriptionForTransceiver($transceiver, $mid);
                } elseif ($mediaKind == "application") {
                    $mediaDescription = $this->createMediaDescriptionForSctp($mid);
                }

                if (isset($mediaDescription)) {
                    $sessionDescription->addMedia($mediaDescription);
                }
            }

            foreach ($this->transceivers as $transceiver) {
                if ($transceiver->getMid() === null && !$transceiver->isStopped()) {
                    $transceiver->setMlineIndex(count($sessionDescription->getMedia()));
                    $sessionDescription->addMedia($this->createMediaDescriptionForTransceiver($transceiver, $this->getFreeMid($mids)));
                }
            }

            if ($this->sctp && $this->sctp->getMid() === null) {
                $sessionDescription->addMedia($this->createMediaDescriptionForSctp($this->getFreeMid($mids)));
            }

            foreach ($sessionDescription->getMedia() as $media) {
                $groupDescription->items[] = $media->getRtp()->muxId;
            }
            $sessionDescription->addGroup($groupDescription);

            return new RTCSessionDescription((string)$sessionDescription, $sessionDescription->getType());
        })();
    }

    /**
     * Initializes a new session description.
     *
     * @param string $type The description type (offer/answer)
     * @return array{SessionDescription, GroupDescription} The session and group descriptions
     */
    private function initSessionDescription(string $type): array
    {
        $ntpSeconds = NetworkTimeProtocol::currentMs() >> 3;

        $sessionDescription = new SessionDescription();
        $sessionDescription->setOrigin("- $ntpSeconds $ntpSeconds IN IP4 0.0.0.0");
        $sessionDescription->appendMsidSemantic(new GroupDescription("WMS", ["*"]));
        $sessionDescription->setType($type);

        $groupDescription = new GroupDescription("BUNDLE", []);

        return [$sessionDescription, $groupDescription];
    }

    /**
     * Finds an unused media identifier.
     *
     * @param array $mids Array of used media identifiers
     * @return int The next available media identifier
     */
    private function getFreeMid(array &$mids): int
    {
        $i = 0;
        while (True) {
            if (!isset($mids[$i])) {
                $mids[$i] = true;
                return $i;
            }
            $i += 1;
        }
    }

    /**
     * Gets all RTP receivers attached to the connection.
     *
     * @return array<RTCRtpReceiver> Array of RTP receivers
     */
    public function getReceivers(): array
    {
        return array_map(fn($transceiver) => $transceiver->getReceiver(), $this->transceivers);
    }

    /**
     * Gets all RTP senders attached to the connection.
     *
     * @return list<RTCRtpSender> Array of RTP senders
     */
    public function getSenders(): array
    {
        return array_map(fn($transceiver) => $transceiver->getSender(), $this->transceivers);
    }

    /**
     * Gets statistics for the connection.
     *
     * @return RTCStatsReport The statistics report
     */
    public function getStats(): RTCStatsReport
    {
        $report = new RTCStatsReport();

        $senders = $this->getSenders();
        $receivers = $this->getReceivers();

        foreach ($senders as $sender) {
            $report->merge($sender->getStats());
        }

        foreach ($receivers as $receiver) {
            $report->merge($receiver->getStats());
        }

        return $report;
    }

    /**
     * Gets all transceivers attached to the connection.
     *
     * @return array<RTCRtpTransceiver> Array of transceivers
     */
    public function getTransceivers(): array
    {
        return $this->transceivers;
    }

    /**
     * Sets the local description for the connection.
     *
     * @param RTCSessionDescription $rtcSessionDescription The description to set
     * @return PromiseInterface Promise that resolves when complete
     */
    public function setLocalDescription(RTCSessionDescription $rtcSessionDescription): PromiseInterface
    {
        return async(function () use ($rtcSessionDescription) {
            $this->debug(sprintf("setLocalDescription(%s)\n%s", $rtcSessionDescription->getType(), $rtcSessionDescription->getSdp()));

            $sessionDescription = SessionDescription::decode($rtcSessionDescription->getSdp());
            $sessionDescription->setType($rtcSessionDescription->getType());
            $this->validateDescription($sessionDescription, true);

            if ($sessionDescription->isType("offer")) {
                $this->setSignalingState(SignalingState::haveLocalOffer);
            } elseif ($sessionDescription->isType("answer")) {
                $this->setSignalingState(SignalingState::stable);
            }

            foreach ($sessionDescription->getMedia() as $index => $media) {
                $this->seenMids[$media->getRtp()->muxId] = true;

                if (in_array($media->getKind(), ["audio", "video"])) {
                    $transceiver = $this->getTransceiverByMLineIndex($index);
                    $transceiver->setMid($media->getRtp()->muxId);
                } elseif ($media->getKind() === "application") {
                    $this->sctp->setMid($media->getRtp()->muxId);
                }
            }

            if ($sessionDescription->isType("offer")) {
                foreach ($this->iceTransports as $iceTransport) {
                    if (!$iceTransport->isRoleSet()) {
                        $iceTransport->getIceConnection()->setIceRole(IceRole::Controlling);
                        $iceTransport->setRoleSet(true);
                    }
                }
            }

            if ($sessionDescription->isType("answer")) {
                foreach ($sessionDescription->getMedia() as $index => $media) {
                    if (in_array($media->getKind(), ["audio", "video"])) {
                        $transceiver = $this->getTransceiverByMLineIndex($index);
                        $transceiver->getDtlsTransport()->setRole($media->getDTLS()->role);
                    } elseif ($media->getKind() === "application") {
                        $this->sctp->getDtlsTransport()->setRole($media->getDTLS()->role);
                    }
                }
            }

            if (in_array($sessionDescription->getType(), ["answer", "pranswer"])) {
                foreach ($this->transceivers as $transceiver) {
                    $transceiver->setCurrentDirection(SDPDirections::tryFrom($transceiver->getDirection()->value & $transceiver->getOfferDirection()->value) ?? SDPDirections::sendonly);
                }
            }
            $this->gatherIceCandidates();

            foreach ($sessionDescription->getMedia() as $index => $media) {
                if (in_array($media->getKind(), ["audio", "video"])) {
                    $transceiver = $this->getTransceiverByMLineIndex($index);
                    $this->addTransportDescription($media, $transceiver->getDtlsTransport());
                } elseif ($media->getKind() === "application") {
                    $this->addTransportDescription($media, $this->sctp->getDtlsTransport());
                }
            }

            if ($sessionDescription->isType("answer")) {
                $this->currentLocalDescription = $sessionDescription;
                $this->pendingLocalDescription = null;
            } else {
                $this->pendingLocalDescription = $sessionDescription;
            }

            async(fn() => $this->connect())();
        })();
    }

    /**
     * Sets the remote description for the connection.
     *
     * @param RTCSessionDescription $sessionDescription The description to set
     * @return PromiseInterface Promise that resolves when complete
     */
    public function setRemoteDescription(RTCSessionDescription $sessionDescription): PromiseInterface
    {
        return async(function () use ($sessionDescription) {
            $this->debug(sprintf("setRemoteDescription(%s)\n%s", $sessionDescription->getType(), $sessionDescription->getSdp()));
            $sdp = SessionDescription::decode($sessionDescription->getSdp());
            $sdp->setType($sessionDescription->getType());
            $this->validateDescription($sdp, false);
            $iceCandidates = [];
            /* @var RTCTrackEvent[] $trackEvents */
            $trackEvents = [];
            foreach ($sdp->getMedia() as $index => $media) {
                $dtlsTransport = null;
                $this->seenMids[$media->getRtp()->muxId] = true;
                if (in_array($media->getKind(), ["audio", "video"])) {
                    [$trackEvents [], $dtlsTransport] = $this->getTrackEventAndDtlsTransport($index, $media, $sdp);
                } elseif ($media->getKind() === "application") {
                    $dtlsTransport = $this->getSctpDtlsTransport($index, $media);
                }

                if (isset($dtlsTransport)) {
                    $this->handleTransports($dtlsTransport, $sdp, $media, $iceCandidates);
                }
            }
            $bundleGroupDescriptions = array_filter($sdp->getGroup(), fn(GroupDescription $group) => $group->semantic === "BUNDLE");
            $bundleGroupDescription = $bundleGroupDescriptions[0] ?? null;

            if ($bundleGroupDescription && !empty($bundleGroupDescription->items)) {
                $this->removeBundleTransport($bundleGroupDescription, $iceCandidates);
            }

            foreach ($iceCandidates as [$iceCandidate, $media]) {
                $this->addRemoteCandidates($iceCandidate, $media);
            }

            foreach ($trackEvents as $trackEvent) {
                if ($trackEvent !== null) {
                    $this->emit("track", [$trackEvent->track]);
                }
            }

            if ($sdp->isType("offer")) {
                $this->setSignalingState(SignalingState::haveRemoteOffer);
            } elseif ($sdp->isType("answer")) {
                $this->setSignalingState(SignalingState::stable);

            }

            if ($sdp->isType("answer")) {
                $this->currentRemoteDescription = $sdp;
                $this->pendingRemoteDescription = null;
            } else {
                $this->pendingRemoteDescription = $sdp;
            }

            async(fn() => $this->connect())();

        })();
    }

    /**
     * Gets a track event and DTLS transport for a media description.
     *
     * This method handles the negotiation and setup of a transceiver for a remote media description,
     * including codec negotiation, direction configuration, and track creation.
     *
     * @param int $index The media line index in the SDP
     * @param MediaDescription $media The media description from the remote SDP
     * @param SessionDescription $sessionDescription The full session description
     * @return array{RTCTrackEvent|null, RTCDtlsTransport} Tuple containing:
     *         - The track event (if a new receiver track was created)
     *         - The associated DTLS transport
     * @throws OpenSSLException
     * @throws RandomException
     * @throws SrtpException
     */
    private function getTrackEventAndDtlsTransport(int $index, MediaDescription $media, SessionDescription $sessionDescription): array
    {
        $trackEvent = null;
        $transceiver = null;
        foreach ($this->transceivers as $trans) {
            if ($trans->getKind()->value === $media->getKind() && ($trans->getMid() === null || $trans->getMid() == $media->getRtp()->muxId)) {
                $transceiver = $trans;
                break;
            }
        }

        if (!$transceiver) {
            $transceiver = $this->createTransceiver(MediaKind::tryFrom($media->getKind()), SDPDirections::recvonly);
        }

        if (!$transceiver->getMid()) {
            $transceiver->setMid($media->getRtp()->muxId);
            $transceiver->setMlineIndex($index);
        }

        $codec = new Codec();
        // negotiate codecs
        $mutual = $this->findPreferredCodecs($this->findMutualCodecs($codec->getCodecs($media->getKind()), $media->getRtp()->codecs), $transceiver->getPreferredCodecs());
        if (empty($mutual)) {
            throw new RuntimeException("Failed to set remote {$media->getKind()} description send parameters");
        }
        $transceiver->setCodecs($mutual);
        $transceiver->setHeaderExtensions($this->findMutualHeaderExtensions($codec->getHeaderExtensions($media->getKind()), $media->getRtp()->headerExtensions));

        // configure direction
        $direction = $this->reverseDirection($media->getDirection());
        if (in_array($sessionDescription->getType(), ["answer", "pranswer"])) {
            $transceiver->setCurrentDirection($direction);
        } else {
            $transceiver->setOfferDirection($direction);
        }

        // create remote stream track
        if (in_array($direction, [SDPDirections::recvonly, SDPDirections::sendrecv]) && !$transceiver->getReceiver()->getTrack()) {
            $transceiver->getReceiver()->setTrack(new RemoteStreamTrack(MediaKind::tryFrom($media->getKind()), $sessionDescription->webrtcTrackId($media)));
            $trackEvent = new RTCTrackEvent($transceiver->getReceiver(), $transceiver->getReceiver()->getTrack(), $transceiver);
        }

        // memorise transport parameters
        $this->remoteDtlsParameter[spl_object_id($transceiver)] = $media->getDtls();
        $this->remoteIceParameters[spl_object_id($transceiver)] = $media->getIce();

        return [$trackEvent, $transceiver->getDtlsTransport()];
    }

    /**
     * Creates a new RTCRtpTransceiver with the specified parameters.
     *
     * The transceiver includes both sender and receiver components and is automatically
     * added to the connection's transceiver list.
     *
     * @param MediaKind $kind The media kind (audio/video)
     * @param SDPDirections $direction The initial direction of the transceiver
     * @param MediaStreamTrack|null $senderTrack Optional track for the sender component
     * @return RTCRtpTransceiver The created transceiver
     * @throws OpenSSLException
     * @throws RandomException
     * @throws SrtpException
     */
    private function createTransceiver(MediaKind $kind, SDPDirections $direction, ?MediaStreamTrack $senderTrack = null): RTCRtpTransceiver
    {
        $dtlsTransport = $this->createDtlsTransport();
        $transceiver = new RTCRtpTransceiver($kind, new RTCRtpReceiver($kind, $dtlsTransport), new RTCRtpSender($senderTrack ?? $kind, $dtlsTransport));
        $transceiver->setDirection($direction);
        $transceiver->getReceiver()->setRtcpSsrc($transceiver->getSender()->getSsrc());
        $transceiver->getSender()->setStreamId($this->streamId);
        $transceiver->setDtlsTransport($dtlsTransport);

        $this->transceivers[] = $transceiver;

        return $transceiver;
    }

    /**
     * Gets or creates the SCTP transport's DTLS transport for a media description.
     *
     * This handles SCTP-specific SDP negotiation including legacy SDP format detection
     * and port configuration.
     *
     * @param int $index The media line index in the SDP
     * @param MediaDescription $media The media description from the remote SDP
     * @return RTCDtlsTransport The DTLS transport for the SCTP association
     * @throws OpenSSLException
     * @throws RandomException
     * @throws SctpException
     * @throws SrtpException
     */
    private function getSctpDtlsTransport(int $index, MediaDescription $media): RTCDtlsTransport
    {
        if (!$this->sctp) {
            $this->createSctpTransport();
        }

        if ($this->sctp->getMid() === null) {
            $this->sctp->setMid($media->getRtp()->muxId);
        }

        // configure sctp
        if ($media->getProfile() === "DTLS/SCTP") {
            $this->sctpLegacySdp = true;
            $this->sctpRemotePort = $media->getFmt()[0];
        } else {
            $this->sctpLegacySdp = false;
            $this->sctpRemotePort = $media->getSctpPort();
        }

        // memorise transport parameters
        $this->remoteDtlsParameter[spl_object_id($this->sctp)] = $media->getDtls();
        $this->remoteIceParameters[spl_object_id($this->sctp)] = $media->getIce();

        return $this->sctp->getDtlsTransport();
    }

    /**
     * Configures ICE and DTLS transports based on session and media descriptions.
     *
     * This method handles:
     * - Setting ICE roles (controlling/controlled) based on offer/answer
     * - Configuring DTLS roles (client/server) based on remote parameters
     * - Tracking ICE candidates for later processing
     *
     * @param RTCDtlsTransport $dtlsTransport The DTLS transport to configure
     * @param SessionDescription $sessionDescription The session description
     * @param MediaDescription $media The media description
     * @param array &$iceCandidates Reference to array storing ICE candidates
     */
    private function handleTransports(RTCDtlsTransport $dtlsTransport, SessionDescription $sessionDescription, MediaDescription $media, array &$iceCandidates): void
    {
        // add ICE candidates
        $iceTransport = $dtlsTransport->getIceTransport();

        // set an ICE role
        if ($sessionDescription->isType("offer") && !$iceTransport->isRoleSet()) {
            $iceTransport->getIceConnection()->setIceRole($media->getIce()->iceLite ? IceRole::Controlling : IceRole::Controlled);
            $iceTransport->setRoleSet(true);
        }

        // set DTLS role
        if ($sessionDescription->isType("offer") && $media->getDtls()->role == DtlsRole::Client) {
            $dtlsTransport->setRole(DtlsRole::Server);
        }
        if ($sessionDescription->isType("answer")) {
            $dtlsTransport->setRole($media->getDtls()->role == DtlsRole::Client ? DtlsRole::Server : DtlsRole::Client);
        }

        $iceCandidates[spl_object_id($iceTransport)] = [$iceTransport, $media];
    }

    /**
     * Handles BUNDLE group negotiation by consolidating transports.
     *
     * This implements the BUNDLE mechanism from RFC 8843, where multiple media streams
     * share a single transport. It:
     * - Identifies the primary transport from the BUNDLE group
     * - Reconfigures secondary transports to use the primary transport
     * - Cleans up old unused transports
     *
     * @param GroupDescription $bundleGroupDescription The BUNDLE group from SDP
     * @param array &$iceCandidates Reference to array storing ICE candidates
     */
    private function removeBundleTransport(GroupDescription $bundleGroupDescription, array &$iceCandidates): void
    {
        // find the main media stream
        $masterMid = $bundleGroupDescription->items[0];
        $masterTransport = null;
        foreach ($this->transceivers as $transceiver) {
            if ($transceiver->getMid() == $masterMid) {
                $masterTransport = $transceiver->getDtlsTransport();
                break;
            }
        }

        if ($this->sctp and $this->sctp->getMid() == $masterMid) {
            $masterTransport = $this->sctp->getDtlsTransport();
        }

        // replace transport for bundled media
        $oldTransports = [];
        $slaveMids = array_slice($bundleGroupDescription->items, 1);
        foreach ($this->transceivers as $transceiver) {
            if (in_array($transceiver->getMid(), $slaveMids) && !$transceiver->isBundled()) {
                $oldTransports [] = $transceiver->getDtlsTransport();
                $transceiver->getReceiver()->setTransport($masterTransport);
                $transceiver->getSender()->setTransport($masterTransport);
                $transceiver->setBundled(true);
                $transceiver->setDtlsTransport($masterTransport);
            }
        }

        if ($this->sctp && in_array($this->sctp->getMid(), $slaveMids) && !$this->sctp->isBundled()) {
            $oldTransports[] = $this->sctp->getDtlsTransport();
            $this->sctp->setTransport($masterTransport);
            $this->sctp->setBundled(true);
        }

        // stop and discard old ICE transports
        foreach ($oldTransports as $dtlsTransport) {
            $dtlsTransport->stop()->then(fn() => $dtlsTransport->getIceTransport()->stop());
            unset($this->dtlsTransports[spl_object_id($dtlsTransport)]);
            unset($this->iceTransports[spl_object_id($dtlsTransport->getIceTransport())]);
            unset($iceCandidates[spl_object_id($dtlsTransport->getIceTransport())]);
        }
        $this->updateIceGatheringState();
        $this->updateIceConnectionState();
        $this->updateConnectionState();
    }

    /**
     * Connects all transports when ready.
     *
     * @throws RandomException If random number generation fails
     * @throws TLSException If TLS handshake fails
     * @throws DTLSException If DTLS handshake fails
     * @throws OpenSSLException|Throwable If SSL operation fails
     */
    private function connect(): void
    {
        foreach ($this->transceivers as $transceiver) {
            $dtlsTransport = $transceiver->getDtlsTransport();
            $iceTransport = $dtlsTransport->getIceTransport();

            if (!empty($iceTransport->getIceGatherer()->getLocalCandidates()) && isset($this->remoteIceParameters[spl_object_id($transceiver)])) {
                if ($iceTransport->getState() === IceTransportState::new) {
                    await($iceTransport->start($this->remoteIceParameters[spl_object_id($transceiver)]));
                }

                if ($dtlsTransport->getState() == TLSState::NEW && $iceTransport->getState() == IceTransportState::complete) {
                    $dtlsTransport->start($this->remoteDtlsParameter[spl_object_id($transceiver)]->fingerprints);
                }

                if ($dtlsTransport->getState() == TLSState::CONNECTED) {
                    if (in_array($transceiver->getCurrentDirection(), [SDPDirections::sendonly, SDPDirections::sendrecv])) {
                        $transceiver->getSender()->start($this->localRtp($transceiver));
                    }
                    if (in_array($transceiver->getCurrentDirection(), [SDPDirections::recvonly, SDPDirections::sendrecv])) {
                        $transceiver->getReceiver()->start($this->remoteRtp($transceiver));
                    }
                }
            }
        }

        if ($this->sctp) {
            $dtlsTransport = $this->sctp->getDtlsTransport();
            $iceTransport = $dtlsTransport->getIceTransport();
            if ($iceTransport->getIceGatherer()->getLocalCandidates() && isset($this->remoteIceParameters[spl_object_id($this->sctp)])) {
                if ($iceTransport->getState() === IceTransportState::new) {
                    await($iceTransport->start($this->remoteIceParameters[spl_object_id($this->sctp)]));
                }
                if ($dtlsTransport->getState() == TLSState::NEW && $iceTransport->getState() == IceTransportState::complete) {
                    $dtlsTransport->start($this->remoteDtlsParameter[spl_object_id($this->sctp)]->fingerprints);
                }

                if ($dtlsTransport->getState() == TLSState::CONNECTED) {
                    $this->sctp->start($this->sctpRemotePort);
                }
            }
        }
    }

    /**
     * Gathers ICE candidates for all transports.
     *
     * @throws RandomException If random number generation fails
     * @throws Throwable If gathering fails
     */
    private function gatherIceCandidates(): void
    {
        foreach ($this->iceTransports as $iceTransport) {
            $iceTransport->getIceGatherer()->gather();
        }
    }

    /**
     * Verifies the connection is not closed.
     *
     * @throws RuntimeException If connection is closed
     */
    private function checkNotClosed(): void
    {
        if ($this->isClosed) {
            throw new RuntimeException("RTCPeerConnection is closed");
        }
    }

    /**
     * Verifies a track doesn't already have a sender.
     *
     * @param MediaStreamTrack $track The track to check
     * @throws InvalidArgumentException If track already has a sender
     */
    private function checkTrackHasNoSender(MediaStreamTrack $track): void
    {
        $senders = $this->getSenders();
        foreach ($senders as $sender) {
            if ($sender->getTrack() === $track) {
                throw new InvalidArgumentException("Track already has a sender");
            }
        }
    }

    /**
     * Creates RTP parameters for local sending.
     *
     * @param RTCRtpTransceiver $transceiver The sending transceiver
     * @return RTCRtpSendParameters The send parameters
     */
    private function localRtp(RTCRtpTransceiver $transceiver): RTCRtpSendParameters
    {
        $rtp = new RTCRtpSendParameters($transceiver->getCodecs(), $transceiver->getHeaderExtensions(), $transceiver->getMid());
        $rtp->rtcp->cname = $this->cname;
        $rtp->rtcp->ssrc = $transceiver->getSender()->getSsrc();

        return $rtp;
    }

    /**
     * Creates RTP parameters for remote receiving.
     *
     * @param RTCRtpTransceiver $transceiver The receiving transceiver
     * @return RTCRtpReceiveParameters The receive parameters
     */
    private function remoteRtp(RTCRtpTransceiver $transceiver): RTCRtpReceiveParameters
    {
        $remoteDescription = $this->pendingRemoteDescription ?? $this->currentRemoteDescription;
        $media = $remoteDescription->getMedia()[$transceiver->getMlineIndex()];
        $rtp = new RTCRtpReceiveParameters($transceiver->getCodecs(), $transceiver->getHeaderExtensions(), $media->getRtp()->muxId, $media->getRtp()->rtcp);

        if (count($media->getSsrc()) > 0) {
            $encoding = [];
            foreach ($transceiver->getCodecs() as $codec) {
                if (str_starts_with(strtolower($codec->mimeType), "rtx")) {
                    if (in_array($codec->parameters["apt"], $encoding) && count($media->getSsrc()) === 2) {
                        $encoding[$codec->parameters["apt"]]->setRtx(new RTCRtpRtxParameters($media->getSsrc()[1]->ssrc));
                        continue;
                    }
                }
                $encoding[$codec->payloadType] = new RTCRtpDecodingParameters($media->getSsrc()[0]->ssrc, $codec->payloadType);
            }
            $rtp->encodings = $encoding;
        }

        return $rtp;
    }

    /**
     * Updates the connection state based on transport states.
     *
     * @throws
     */
    private function updateConnectionState(): void
    {
        $dtlsStates = [];
        foreach ($this->dtlsTransports as $transport) {
            $dtlsStates[$transport->getState()->value] = true;
        }

        $iceStates = [];
        foreach ($this->iceTransports as $transport) {
            $iceStates[$transport->getState()->value] = true;
        }

        // Compute new state
        if ($this->isClosed) {
            $state = ConnectionState::closed;
        } elseif (isset($iceStates[IceTransportState::failed->value]) || isset($dtlsStates[TLSState::FAILED->value])) {
            $state = ConnectionState::failed;
        } elseif (isset($iceStates[IceTransportState::new->value]) && isset($dtlsStates[TLSState::NEW->value])) {
            $state = ConnectionState::new;
        } elseif (isset($iceStates[IceTransportState::checking->value]) || isset($dtlsStates[TLSState::CONNECTING->value]) || isset($dtlsStates[TLSState::NEW->value])) {
            $state = ConnectionState::connecting;
        } else {
            $state = ConnectionState::connected;
        }

        // Update state
        if ($state !== $this->connectionState) {
            $this->debug(sprintf("connectionState %s -> %s", $this->connectionState->name, $state->name));
            $this->connectionState = $state;
            $this->emit("connectionstatechange");
        }

        if (!$this->isClosed && isset($dtlsStates[TLSState::CLOSED->value])) {
            $this->close();
        }
    }

    /**
     * Updates the ICE connection state based on transport states.
     */
    private function updateIceConnectionState(): void
    {
        $iceStates = array_values(array_map(fn(RTCIceTransport $transport) => $transport->getState(), $this->iceTransports));

        if ($this->isClosed || $iceStates === [IceTransportState::closed]) {
            $state = IceConnectionState::closed;
        } elseif (in_array(IceTransportState::failed, $iceStates)) {
            $state = IceConnectionState::failed;
        } elseif ($iceStates === [IceTransportState::complete]) {
            $state = IceConnectionState::completed;
        } elseif (in_array(IceTransportState::checking, $iceStates)) {
            $state = IceConnectionState::checking;
        } else {
            $state = IceConnectionState::new;
        }
        // var_dump($state);

        // update $state
        if ($state !== $this->iceConnectionState) {
            $this->debug(sprintf("iceConnectionState %s -> %s", $this->iceConnectionState->name, $state->name));
            $this->iceConnectionState = $state;
            $this->emit("iceconnectionstatechange");
        }
    }

    /**
     * Updates the ICE gathering state based on the state of all ICE transports.
     *
     * Emits "icegatheringstatechange" if the state changes.
     */
    private function updateIceGatheringState(): void
    {
        // Compute new state
        $states = [];
        foreach ($this->iceTransports as $transport) {
            $states[] = $transport->getIceGatherer()->getState()->name;
        }

        $uniqueStates = array_unique($states);

        if ($uniqueStates === [IceGatheringState::complete->name]) {
            $state = IceGatheringState::complete;
        } elseif (in_array(IceGatheringState::gathering->name, $uniqueStates, true)) {
            $state = IceGatheringState::gathering;
        } else {
            $state = IceGatheringState::new;
        }

        // Update state
        if ($state !== $this->iceGatheringState) {
            $this->logger?->debug(sprintf("iceGatheringState %s -> %s", $this->iceGatheringState->name, $state->name));
            $this->iceGatheringState = $state;
            $this->emit('icegatheringstatechange');
        }
    }

    /**
     * Finds preferred codecs from available options.
     *
     * @param RTCRtpCodecParameters[] $codecs Available codecs
     * @param RTCRtpCodecCapability[] $preferredCodecs Preferred codecs
     * @return RTCRtpCodecParameters[] Filtered codecs
     */
    public function findPreferredCodecs(array $codecs, array $preferredCodecs): array
    {
        if (empty($preferredCodecs)) {
            return $codecs;
        }

        $rtxCodecs = array_filter($codecs, fn(RTCRtpCodecParameters $codec) => CodecUtility::isRtx($codec));
        $rtxPreferredCodecs = array_filter($preferredCodecs, fn(RTCRtpCodecCapability $codec) => !CodecUtility::isRtx($codec));
        $rtxEnabled = false;
        foreach ($preferredCodecs as $codec) {
            if (CodecUtility::isRtx($codec)) {
                $rtxEnabled = true;
                break;
            }
        }

        $filtered = [];
        foreach ($rtxPreferredCodecs as $pref) {
            foreach ($codecs as $codec) {
                if (strtolower($codec->mimeType) === strtolower($pref->mimeType) && $codec->parameters === $pref->parameters) {
                    $filtered[] = $codec;

                    // add corresponding RTX
                    if ($rtxEnabled) {
                        foreach ($rtxCodecs as $rtx) {
                            if ($rtx->parameters["apt"] === $codec->payloadType) {
                                $filtered[] = $rtx;
                                break;
                            }
                        }
                    }

                    break;
                }
            }
        }

        return $filtered;
    }

    /**
     * Finds mutually supported codecs between local and remote offers.
     *
     * This performs codec negotiation by:
     * - Matching codec types and parameters
     * - Handling RTX (retransmission) codecs specially
     * - Preserving dynamic payload types from the remote offer
     * - Filtering RTCP feedback capabilities based on mutual support
     *
     * @param array<RTCRtpCodecParameters> $localCodecs Local codec capabilities
     * @param array<RTCRtpCodecParameters> $remoteCodecs Remote codec offers
     * @return array<RTCRtpCodecParameters> Mutually supported codecs with negotiated parameters
     */
    public function findMutualCodecs(array $localCodecs, array $remoteCodecs): array
    {
        $mutual = [];
        $mutualBase = [];

        foreach ($remoteCodecs as $remoteCodec) {
            // for RTX, check we accepted the base codec
            if ((strtolower(explode("/", $remoteCodec->mimeType)[1]) === "rtx")) {
                $apt = $remoteCodec->parameters['apt'];
                if (isset($mutualBase[$apt])) {
                    $base = $mutualBase[$apt];
                    if ($remoteCodec->clockRate === $base->clockRate) {
                        $mutual[] = clone $remoteCodec;
                    }
                }
                continue;
            }

            // handle other codecs
            foreach ($localCodecs as $localCodec) {
                if ($this->isCodecCompatible($localCodec, $remoteCodec)) {
                    $codec = clone $localCodec;
                    if (in_array($remoteCodec->payloadType, RtpConstants::DYNAMIC_PAYLOAD_TYPES)) {
                        $codec->payloadType = $remoteCodec->payloadType;
                    }
                    $codec->rtcpFeedback = array_filter($codec->rtcpFeedback, fn(RTCRtcpFeedback $codec) => in_array($codec, $remoteCodec->rtcpFeedback));
                    $mutual[] = $codec;
                    $mutualBase[$codec->payloadType] = $codec;
                    break;
                }
            }
        }

        return $mutual;
    }

    /**
     * Adds remote ICE candidates to transport.
     *
     * @param RTCIceTransport $iceTransport The transport to add to
     * @param MediaDescription $media The media description containing candidates
     */
    private function addRemoteCandidates(RTCIceTransport $iceTransport, MediaDescription $media): void
    {
        foreach ($media->getIceCandidates() as $candidate) {
            $iceTransport->addRemoteCandidate($candidate);
        }

        if ($media->isIceCandidatesComplete()) {
            $iceTransport->endRemoteCandidate();
        }
    }

    /**
     * Reverses a direction (sendonly <-> recvonly).
     *
     * @param SDPDirections $direction The direction to reverse
     * @return SDPDirections The reversed direction
     * @throws InvalidArgumentException If a direction can’t be reversed
     */
    private function reverseDirection(SDPDirections $direction): SDPDirections
    {
        return match ($direction) {
            SDPDirections::sendonly => SDPDirections::recvonly,
            SDPDirections::recvonly => SDPDirections::sendonly,
            default => $direction
        };
    }

    /**
     * Finds mutually supported header extensions.
     *
     * @param RTCRtpHeaderExtensionParameters[] $localExtensions Local extensions
     * @param RTCRtpHeaderExtensionParameters[] $remoteExtensions Remote extensions
     * @return RTCRtpHeaderExtensionParameters[] Mutual extensions
     */
    private function findMutualHeaderExtensions(array $localExtensions, array $remoteExtensions): array
    {
        $mutual = [];

        foreach ($remoteExtensions as $rExt) {
            foreach ($localExtensions as $lExt) {
                if ($lExt->uri === $rExt->uri) {
                    $mutual[] = $rExt;
                }
            }
        }

        return $mutual;
    }

    /**
     * Validates a session description.
     *
     * @param SessionDescription $description The description to validate
     * @param bool $isLocal Whether the description is local
     * @throws InvalidArgumentException If description is invalid
     */
    private function validateDescription(SessionDescription $description, bool $isLocal): void
    {
        // Check description is compatible with signaling state
        if ($isLocal) {
            if ($description->isType("offer")) {
                if (!in_array($this->signalingState, [SignalingState::stable, SignalingState::haveLocalOffer])) {
                    throw new InvalidArgumentException(
                        "Cannot handle offer in signaling state \"" . $this->signalingState->name . "\""
                    );
                }
            } elseif ($description->isType("answer")) {
                if (!in_array($this->signalingState, [SignalingState::haveRemoteOffer, SignalingState::haveLocalPranswer])) {
                    throw new InvalidArgumentException(
                        "Cannot handle answer in signaling state \"" . $this->signalingState->name . "\""
                    );
                }
            }
        } else {
            if ($description->isType("offer")) {
                if (!in_array($this->signalingState, [SignalingState::stable, SignalingState::haveRemoteOffer])) {
                    throw new InvalidArgumentException(
                        "Cannot handle offer in signaling state \"" . $this->signalingState->name . "\""
                    );
                }
            } elseif ($description->isType("answer")) {
                if (!in_array($this->signalingState, [SignalingState::haveLocalOffer, SignalingState::haveLocalPranswer])) {
                    throw new InvalidArgumentException(
                        "Cannot handle answer in signaling state \"" . $this->signalingState->name . "\""
                    );
                }
            }
        }

        foreach ($description->getMedia() as $media) {
            // Check ICE credentials were provided
            if (empty($media->getIce()->usernameFragment) || empty($media->getIce()->password)) {
                throw new InvalidArgumentException("ICE username fragment or password is missing");
            }

            // Check a DTLS role is allowed
            if (in_array($description->getType(), ["answer", "pranswer"]) &&
                !in_array($media->getDtls()->role, [DtlsRole::Client, DtlsRole::Server])) {
                throw new InvalidArgumentException(
                    "DTLS setup attribute must be 'active' or 'passive' for an answer"
                );
            }

            // Check RTCP mux is used
            if (in_array($media->getKind(), ["audio", "video"]) && !$media->isRtcpMux()) {
                throw new InvalidArgumentException("RTCP mux is not enabled");
            }
        }

        // Check the number of media section matches
        if (in_array($description->getType(), ["answer", "pranswer"])) {
            $offer = $isLocal ? $this->getRemoteDescription() : $this->getLocalDescription();

            $offerMedia = array_map(fn(MediaDescription $media) => [$media->getKind(), $media->getRtp()->muxId], SessionDescription::decode($offer->getSdp())->getMedia());
            $answerMedia = array_map(fn($media) => [$media->getKind(), $media->getRtp()->muxId], $description->getMedia());

            if ($answerMedia != $offerMedia) {
                throw new InvalidArgumentException("Media sections in answer do not match offer");
            }
        }
    }

    /**
     * Checks if two codecs are compatible.
     *
     * @param RTCRtpCodecParameters $a First codec
     * @param RTCRtpCodecParameters $b Second codec
     * @return bool True if compatible
     */
    public function isCodecCompatible(RTCRtpCodecParameters $a, RTCRtpCodecParameters $b): bool
    {
        if (strtolower($a->mimeType) !== strtolower($b->mimeType) || $a->clockRate !== $b->clockRate) {
            return false;
        }

        if (strtolower($a->mimeType) === "video/h264") {
            try {
                return $this->packetization($a) === $this->packetization($b) && $this->profile($a) === $this->profile($b);
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }

    /**
     * Gets the H.264 profile from codec parameters.
     *
     * @param RTCRtpCodecParameters $codec The codec parameters
     * @return H264Profile The H.264 profile
     */
    private function profile(RTCRtpCodecParameters $codec): H264Profile
    {
        return H264Sdp::parseH264ProfileLevelId($codec->parameters['profile-level-id'] ?? '42E01F')[0];
    }

    /**
     * Gets the packetization mode from codec parameters.
     *
     * @param RTCRtpCodecParameters $codec The codec parameters
     * @return bool The packetization mode
     */
    private function packetization(RTCRtpCodecParameters $codec): bool
    {
        return $codec->parameters['packetization-mode'] ?? '0';
    }

    /**
     * Logs a debug message.
     *
     * @param string $log The message to log
     */
    private function debug(string $log): void
    {
        $this->logger?->debug("RTCPeerConnection(" . spl_object_id($this) . "): " . $log);
    }

    /**
     * Sets whether to use legacy SDP format for SCTP.
     *
     * @param bool $sctpLegacySdp Whether to use a legacy format
     */
    public function setSctpLegacySdp(bool $sctpLegacySdp): void
    {
        $this->sctpLegacySdp = $sctpLegacySdp;
    }
}
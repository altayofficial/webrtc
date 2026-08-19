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
use Random\RandomException;
use React\Promise\PromiseInterface;
use Throwable;
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
use Webrtc\SCTP\Exception\SctpException;
use Webrtc\SCTP\RTCSctpTransport;
use Webrtc\SDP\DtlsParameter\RTCDtlsParameters;
use Webrtc\SDP\Enum\DtlsRole;
use Webrtc\SDP\GroupDescription;
use Webrtc\SDP\MediaDescription;
use Webrtc\SDP\RTCSessionDescription;
use Webrtc\SDP\SessionDescription;
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
 * WebRTC connections. This build is data channel only - the media (audio/video) support of the
 * upstream package has been stripped out.
 *
 * Key Features:
 * - Manages ICE candidates for network connectivity
 * - Handles DTLS for secure communication
 * - Provides data channel functionality
 * - Manages connection state and signaling
 *
 * Events:
 * - "connectionstatechange": Fired when the connection state changes
 * - "iceconnectionstatechange": Fired when the ICE connection state changes
 * - "icegatheringstatechange": Fired when the ICE gathering state changes
 * - "signalingstatechange": Fired when the signaling state changes
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
     * List of all active DTLS transports for data channels
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
     * @throws DateInvalidOperationException If there's an SSL-related error
     */
    public function __construct(null|array|RTCConfigurationInterface $configuration = null)
    {
        $this->configuration = $configuration instanceof RTCConfigurationInterface ? $configuration : new RTCConfiguration($configuration);
        $this->certificates[] = new RTCCertificate($this->configuration->getPrivateKeyPath(), $this->configuration->getCertificatePath());
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

        if ($this->sctp && $candidate->getSDPMid() == $this->sctp->getMid() && !$this->sctp->isBundled()) {
            $iceTransport = $this->sctp->getDtlsTransport()->getIceTransport();
            $iceTransport->addRemoteCandidate($candidate);
        }
    }

    /**
     * Closes the peer connection and terminates all transports.
     *
     */
    public function close(): void
    {
        if ($this->isClosed) {
            return;
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
                if ($remoteMediaStream->getKind() !== "application") {
                    continue;
                }

                $mediaDescription = $this->createMediaDescriptionForSctp();
                $dtlsTransport = $this->sctp->getDtlsTransport();

                $mediaDescription->getDtls()->role = $dtlsTransport->getRole() == DtlsRole::Auto ? DtlsRole::Client : $dtlsTransport->getRole();
                $sessionDescription->addMedia($mediaDescription);
                $groupDescription->items[] = $mediaDescription->getRtp()->muxId;
            }

            $sessionDescription->addGroup($groupDescription);

            return new RTCSessionDescription((string)$sessionDescription, $sessionDescription->getType());
        })();
    }

    /**
     * Adds transport information to a media description.
     *
     * @param MediaDescription $mediaDescription The description to augment
     * @param RTCDtlsTransport $dtlsTransport The transport to describe
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
     * Creates a media description for the SCTP transport.
     *
     * @param string|null $mid The media identifier
     * @return MediaDescription The created media description
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
     * @throws SctpException
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
     * @throws SctpException If SCTP transport creation fails
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
     * @throws RandomException If random number generation fails
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
     * @throws RuntimeException If called with no data channels
     */
    public function createOffer(): PromiseInterface
    {
        return async(function () {
            $this->checkNotClosed();

            if (!$this->sctp) {
                throw new RuntimeException("Cannot create an offer with no data channels");
            }

            $mids = $this->seenMids;

            [$sessionDescription, $groupDescription] = $this->initSessionDescription("offer");

            $remoteDescriptionMedias = ($this->pendingRemoteDescription ?? $this->currentRemoteDescription)?->getMedia() ?? [];
            $localDescriptionMedias = ($this->pendingLocalDescription ?? $this->currentLocalDescription)?->getMedia() ?? [];

            for ($i = 0; $i < max(count($remoteDescriptionMedias), count($localDescriptionMedias)); $i++) {
                $mediaKind = ($localDescriptionMedias[$i] ?? $remoteDescriptionMedias[$i])?->getKind();
                $mid = ($localDescriptionMedias[$i] ?? $remoteDescriptionMedias[$i])?->getRtp()?->muxId;
                if ($mediaKind === "application") {
                    $sessionDescription->addMedia($this->createMediaDescriptionForSctp($mid));
                }
            }

            if ($this->sctp->getMid() === null) {
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
     * Gets statistics for the connection.
     *
     * @return RTCStatsReport The statistics report
     */
    public function getStats(): RTCStatsReport
    {
        $report = new RTCStatsReport();

        foreach ($this->dtlsTransports as $dtlsTransport) {
            $report->merge($dtlsTransport->getStats());
        }

        return $report;
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

            foreach ($sessionDescription->getMedia() as $media) {
                $this->seenMids[$media->getRtp()->muxId] = true;

                if ($media->getKind() === "application") {
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
                foreach ($sessionDescription->getMedia() as $media) {
                    if ($media->getKind() === "application") {
                        $this->sctp->getDtlsTransport()->setRole($media->getDTLS()->role);
                    }
                }
            }

            $this->gatherIceCandidates();

            foreach ($sessionDescription->getMedia() as $media) {
                if ($media->getKind() === "application") {
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
            foreach ($sdp->getMedia() as $index => $media) {
                $this->seenMids[$media->getRtp()->muxId] = true;
                if ($media->getKind() !== "application") {
                    continue;
                }

                $dtlsTransport = $this->getSctpDtlsTransport($index, $media);
                $this->handleTransports($dtlsTransport, $sdp, $media, $iceCandidates);
            }
            $bundleGroupDescriptions = array_filter($sdp->getGroup(), fn(GroupDescription $group) => $group->semantic === "BUNDLE");
            $bundleGroupDescription = $bundleGroupDescriptions[0] ?? null;

            if ($bundleGroupDescription && !empty($bundleGroupDescription->items)) {
                $this->removeBundleTransport($bundleGroupDescription, $iceCandidates);
            }

            foreach ($iceCandidates as [$iceCandidate, $media]) {
                $this->addRemoteCandidates($iceCandidate, $media);
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
     * Gets or creates the SCTP transport's DTLS transport for a media description.
     *
     * This handles SCTP-specific SDP negotiation including legacy SDP format detection
     * and port configuration.
     *
     * @param int $index The media line index in the SDP
     * @param MediaDescription $media The media description from the remote SDP
     * @return RTCDtlsTransport The DTLS transport for the SCTP association
     * @throws SctpException
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

        if ($this->sctp and $this->sctp->getMid() == $masterMid) {
            $masterTransport = $this->sctp->getDtlsTransport();
        }

        // replace transport for bundled media
        $oldTransports = [];
        $slaveMids = array_slice($bundleGroupDescription->items, 1);

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
     * @throws Throwable If SSL operation fails
     */
    private function connect(): void
    {
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

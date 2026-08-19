<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SCTP;

use Evenement\EventEmitter;
use Psr\Log\LoggerInterface;
use Random\RandomException;
use React\EventLoop\Loop;
use SplQueue;
use Webrtc\DataChannel\Enum\State as DataChannelState;
use Webrtc\DataChannel\RTCSctpTransportInterface;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\ICE\Enum\IceRole;
use Webrtc\SCTP\Chunk\AbortChunk;
use Webrtc\SCTP\Chunk\Chunk;
use Webrtc\SCTP\Chunk\CookieAckChunk;
use Webrtc\SCTP\Chunk\CookieEchoChunk;
use Webrtc\SCTP\Chunk\DataChunk;
use Webrtc\SCTP\Chunk\ErrorChunk;
use Webrtc\SCTP\Chunk\ForwardTsnChunk;
use Webrtc\SCTP\Chunk\HeartbeatAckChunk;
use Webrtc\SCTP\Chunk\HeartbeatChunk;
use Webrtc\SCTP\Chunk\InitAckChunk;
use Webrtc\SCTP\Chunk\InitChunk;
use Webrtc\SCTP\Chunk\ReconfigChunk;
use Webrtc\SCTP\Chunk\SackChunk;
use Webrtc\SCTP\Chunk\ShutdownAckChunk;
use Webrtc\SCTP\Chunk\ShutdownChunk;
use Webrtc\SCTP\Chunk\ShutdownCompleteChunk;
use Webrtc\SCTP\Enum\State;
use Webrtc\SCTP\Exception\SctpException;
use Webrtc\SCTP\Param\StreamAddOutgoingParam;
use Webrtc\SCTP\Param\StreamParamInterface;
use Webrtc\SCTP\Param\StreamResetOutgoingParam;
use Webrtc\SCTP\Param\StreamResetResponseParam;
use Webrtc\SCTP\Trait\DataChannel;
use Webrtc\SDP\SctpParameter\RTCSctpCapabilities;
use Webrtc\Stats\enum\TLSState;
use function React\Async\async;

/**
 * Class RTCSctpTransport
 *
 * RTCSctpTransport represents the SCTP (Stream Control Transmission Protocol) transport over a DTLS transport.
 * It manages the lifecycle, state, and data channel association of the SCTP transport used in WebRTC.
 * The class handles chunk encoding, transmission, state management, and stream configuration.
 *
 * This implementation supports both client and server roles and is capable of managing stream reset, congestion control,
 * reconfiguration parameters, and forward TSNs. It interacts with the lower-level RTCDtlsTransport and provides a
 * high-level interface for data channels and SCTP signaling.
 *
 * @implements RTCSctpTransportInterface
 */
class RTCSctpTransport extends EventEmitter implements RTCSctpTransportInterface
{
    use DataChannel;

    /**
     * @var mixed[]
     */
    private const RECONFIG_PARAM_TYPES = [
        13 => "StreamResetOutgoingParam",
        16 => "StreamResetResponseParam",
        17 => "StreamAddOutgoingParam",
    ];

    // States
    private State $state = State::CLOSED;
    private bool $started = false;

    // Local variables
    private int $localVerificationTag;

    // Remote variables
    private ?int $remotePort = null;
    private ?LoggerInterface $logger = null;

    // Inbound
    private int $advertisedRwnd = 1024 * 1024;
    private int $inboundStreamsMax = SctpConstant::MAX_STREAMS;
    private int $inboundStreamsCount = 0;
    private int $localTsn;
    private bool $sackNeeded = false;
    private ?int $lastReceivedTsn = null;

    // Outbound
    private int $outboundStreamsCount = SctpConstant::MAX_STREAMS;

    // Timers
    private int|float $rto = SctpConstant::SCTP_RTO_INITIAL;
    private SctpTimer $timer1;
    private SctpTimer $timer2;

    private array $remoteExtensions = [];
    private bool $remotePartialReliability = false;
    private int $remoteVerificationTag = 0;
    private array $inboundStreams = [];

    private array $sackDuplicates = [];
    private array $sackMisordered = [];
    private int $cwnd = 3 * SctpConstant::USERDATA_MAX_LENGTH;
    private ?int $fastRecoveryExit = null;
    private bool $fastRecoveryTransmit = false;
    private ?ForwardTsnChunk $forwardTsnChunk = null;
    private int $flightSize = 0;

    private int $lastSackedTsn;
    private int $advancedPeerAckTsn;

    /** @var SplQueue<DataChunk> */
    private SplQueue $outboundQueue;
    private array $outboundStreamSeq = [];

    private int $partialBytesAcked = 0;
    /** @var SplQueue<DataChunk> */
    private SplQueue $sentQueue;

    private ?float $srtt = null;
    private ?float $rttvar = null;

    // Reconfiguration properties
    /** @var int[] */
    private array $reconfigQueue = [];
    private ?StreamParamInterface $reconfigRequest = null; // Type depends on implementation
    private int $reconfigRequestSeq;
    private int $reconfigResponseSeq = 0;

    private bool $bundled = false;
    private ?string $mid = null;
    private string $hmacKey;
    private bool $localPartialReliability = true;

    /** @var int[]|string[] */
    private array $chunkTypes;
    private int $ssthresh;

    /**
     * Constructs a new RTCSctpTransport instance.
     *
     * @param RTCSctpDtlsTransportInterface $transport An established DTLS transport instance.
     * @param int $localPort The local SCTP port used for data channels (default: 5000).
     * @throws SctpException If the DTLS transport is closed.
     * @throws RandomException If a random generation fails.
     */
    public function __construct(private RTCSctpDtlsTransportInterface $transport, readonly private int $localPort = 5000)
    {
        if ($transport->getState() == TLSState::CLOSED) {
            throw new SctpException("The connection to RTCSctpTransport can't be established.");
        }

        $this->localVerificationTag = SctpUtility::random32();
        $this->localTsn = SctpUtility::random32();
        $this->hmacKey = random_bytes(16);
        $this->chunkTypes = array_flip(SctpPacket::CHUNK_TYPES);
        $this->lastSackedTsn = SctpUtility::tsnMinusOne($this->localTsn);
        $this->advancedPeerAckTsn = SctpUtility::tsnMinusOne($this->localTsn);
        $this->reconfigRequestSeq = $this->localTsn;
        $this->timer1 = new SctpTimer($this, SctpConstant::SCTP_MAX_INIT_RETRANS, $this->logger);
        $this->timer2 = new SctpTimer($this, SctpConstant::SCTP_MAX_ASSOCIATION_RETRANS, $this->logger);
        $this->dataChannelQueue = new SplQueue();
        $this->sentQueue = new SplQueue();
        $this->outboundQueue = new SplQueue();
        $this->loop = Loop::get();
    }

    /**
     * Get the maximum number of RTCDataChannel instances allowed by SCTP negotiation.
     *
     * @return int|null The negotiated maximum number of channels, or null if negotiation incomplete.
     */
    public function getMaxChannels(): ?int
    {
        if ($this->inboundStreamsCount) {
            return min($this->inboundStreamsCount, $this->outboundStreamsCount);
        }
        return null;
    }

    /**
     * Determine if this SCTP transport is acting as a server.
     *
     * @return bool True if acting as server, false if client.
     */
    public function isServer(): bool
    {
        return $this->transport->getIceTransport()->getRole() != IceRole::Controlling;
    }

    /**
     * Get the local SCTP port number used for data channels.
     *
     * @return int The port number.
     */
    public function getPort(): int
    {
        return $this->localPort;
    }

    /**
     * Get the current SCTP state.
     *
     * @return State The current state of the transport.
     */
    public function getState(): State
    {
        return $this->state;
    }

    /**
     * Get the underlying DTLS transport used by SCTP.
     *
     * @return RTCSctpDtlsTransportInterface The DTLS transport.
     */
    public function getDtlsTransport(): RTCSctpDtlsTransportInterface
    {
        return $this->transport;
    }

    /**
     * Get the capabilities of this SCTP transport (e.g., max message size).
     *
     * @return RTCSctpCapabilities SCTP transport capabilities.
     */
    public static function getCapabilities(): RTCSctpCapabilities
    {
        return new RTCSctpCapabilities(65536);
    }

    /**
     * Set a new DTLS transport for this SCTP transport.
     *
     * @param RTCSctpDtlsTransportInterface $transport The new DTLS transport.
     */
    public function setTransport(RTCSctpDtlsTransportInterface $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * Start the SCTP association with the given remote port.
     *
     * @param int $remotePort Remote SCTP port to connect to.
     */
    public function start(int $remotePort): void
    {
        if (!$this->started) {
            $this->started = true;
            $this->setState(State::CONNECTING);
            $this->remotePort = $remotePort;
            $this->dataChannelId = intval(!$this->isServer());
            $this->transport->setSctpReceiver($this);
            if (!$this->isServer()) {
                $this->init();
            }
        }
    }


    /**
     *
     * Stop the SCTP transport and terminate the association.
     *
     *
     * @return void
     */
    public function stop(): void
    {
        $this->transport->removeSctpReceiver($this);
        $this->setState(State::CLOSED);
        if ($this->state != State::CLOSED) {
            $this->sendChunk(new AbortChunk()); // Abort the association.
        }
    }


    /**
     *
     * Internal method to initialize an SCTP association by sending an INIT chunk.
     *
     *
     * @return void
     */
    private function init(): void
    {
        $chunk = new InitChunk();
        $chunk->setInitiateTag($this->localVerificationTag);
        $chunk->setAdvertisedRwnd($this->advertisedRwnd);
        $chunk->setOutboundStreams($this->outboundStreamsCount);
        $chunk->setInboundStreams($this->inboundStreamsMax);
        $chunk->setInitialTsn($this->localTsn);
        $chunk->addParams($this->getExtensions());

        // Start timer #1 and enter COOKIE-WAIT state
        $this->timer1->start($chunk);
        $this->setState(State::COOKIE_WAIT);

        $this->sendChunk($chunk);
    }

    /**
     * Send a chunk asynchronously over the DTLS transport.
     *
     * @param Chunk $chunk The SCTP chunk to send.
     * @throws ZeroReturnException
     */
    public function sendChunk(Chunk $chunk): void
    {
        async(function () use ($chunk) {
            $this->log(sprintf(" Sent chunk %s", $chunk));
            $this->transport->sendData($this->encodeChunk($chunk));
        })();
    }

    /**
     * Encode a chunk into a binary SCTP packet.
     *
     * @param Chunk $chunk The chunk to encode.
     * @return string Encoded SCTP packet.
     */
    private function encodeChunk(Chunk $chunk): string
    {
        return SctpPacket::encode($this->localPort, $this->remotePort, $this->remoteVerificationTag, $chunk);
    }

    /**
     * Get the current RTO (Retransmission Timeout) value.
     *
     * @return float|int RTO in milliseconds.
     */
    public function getRto(): float|int
    {
        return $this->rto;
    }

    /**
     * Get the PSR-logging interface.
     *
     * @return LoggerInterface|null The logger instance or null.
     */
    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Set a PSR-compatible logger for internal debugging and events.
     *
     * @param LoggerInterface|null $logger Logger instance or null to disable logging.
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Decrease the congestion window's flight size based on a chunk's size.
     *
     * @param DataChunk $chunk The acknowledged chunk.
     */
    private function decreaseFlightSize(DataChunk $chunk): void
    {
        $this->flightSize = max(0, $this->flightSize - $chunk->getAttributes()->bookSize);
    }

    /**
     * Set the RTO (Retransmission Timeout) value.
     *
     * @param float|int $rto New RTO value.
     */
    public function setRto(float|int $rto): void
    {
        $this->rto = $rto;
    }

    /**
     * Get the maximum number of inbound SCTP streams.
     *
     * @return int Maximum number of inbound streams.
     */
    public function getInboundStreamsMax(): int
    {
        return $this->inboundStreamsMax;
    }

    /**
     * Set the maximum number of inbound SCTP streams.
     *
     * @param int $inboundStreamsMax The new inbound stream limit.
     */
    public function setInboundStreamsMax(int $inboundStreamsMax): void
    {
        $this->inboundStreamsMax = $inboundStreamsMax;
    }

    /**
     * Get the current count of outbound SCTP streams.
     *
     * @return int Number of outbound streams.
     */
    public function getOutboundStreamsCount(): int
    {
        return $this->outboundStreamsCount;
    }

    /**
     * Set the number of outbound SCTP streams.
     *
     * @param int $outboundStreamsCount Number of outbound streams.
     */
    public function setOutboundStreamsCount(int $outboundStreamsCount): void
    {
        $this->outboundStreamsCount = $outboundStreamsCount;
    }

    /**
     * Get the number of negotiated inbound streams.
     *
     * @return int Count of inbound streams.
     */
    public function getInboundStreamsCount(): int
    {
        return $this->inboundStreamsCount;
    }

    /**
     * Set the number of active inbound SCTP streams.
     *
     * @param int $inboundStreamsCount Number of inbound streams.
     */
    public function setInboundStreamsCount(int $inboundStreamsCount): void
    {
        $this->inboundStreamsCount = $inboundStreamsCount;
    }

    /**
     * Get the list of supported remote extensions.
     *
     * @return array SCTP extensions negotiated from remote peer.
     */
    public function getRemoteExtensions(): array
    {
        return $this->remoteExtensions;
    }

    /**
     * Set the list of negotiated remote SCTP extensions.
     *
     * @param array $remoteExtensions List of extensions.
     */
    public function setRemoteExtensions(array $remoteExtensions): void
    {
        $this->remoteExtensions = $remoteExtensions;
    }

    /**
     * Get the current Re-configuration Request Sequence number.
     *
     * @return int Reconfiguration request sequence number.
     */
    public function getReconfigRequestSeq(): int
    {
        return $this->reconfigRequestSeq;
    }

    /**
     * Set the Re-configuration Request Sequence number.
     *
     * @param int $reconfigRequestSeq The sequence number to set.
     */
    public function setReconfigRequestSeq(int $reconfigRequestSeq): void
    {
        $this->reconfigRequestSeq = $reconfigRequestSeq;
    }

    /**
     * Check if local partial reliability (PR-SCTP) is enabled.
     *
     * @return bool True if local PR-SCTP is supported, false otherwise.
     */
    public function isLocalPartialReliability(): bool
    {
        return $this->localPartialReliability;
    }

    /**
     * Enable or disable local partial reliability (PR-SCTP).
     *
     * @param bool $localPartialReliability Whether to enable local PR-SCTP.
     */
    public function setLocalPartialReliability(bool $localPartialReliability): void
    {
        $this->localPartialReliability = $localPartialReliability;
    }

    /**
     * Check if the remote party supports partial reliability (PR-SCTP).
     *
     * @return bool True if remote PR-SCTP is supported, false otherwise.
     */
    public function isRemotePartialReliability(): bool
    {
        return $this->remotePartialReliability;
    }

    /**
     * Set whether the remote party supports partial reliability (PR-SCTP).
     *
     * @param bool $remotePartialReliability Whether remote PR-SCTP is supported.
     */
    public function setRemotePartialReliability(bool $remotePartialReliability): void
    {
        $this->remotePartialReliability = $remotePartialReliability;
    }

    /**
     * Get the current local Transmission Sequence Number (TSN).
     *
     * @return int The local TSN.
     */
    public function getLocalTsn(): int
    {
        return $this->localTsn;
    }

    /**
     * Set the local Transmission Sequence Number (TSN).
     *
     * @param int $localTsn The TSN to set.
     */
    public function setLocalTsn(int $localTsn): void
    {
        $this->localTsn = $localTsn;
    }

    /**
     * Get the queue of sent chunks.
     *
     * @return SplQueue<DataChunk> Queue of sent chunks pending acknowledgment.
     */
    public function getSentQueue(): SplQueue
    {
        return $this->sentQueue;
    }

    /**
     * Get the outbound queue of data chunks.
     *
     * @return SplQueue<DataChunk> Queue of chunks pending transmission.
     */
    public function getOutboundQueue(): SplQueue
    {
        return $this->outboundQueue;
    }

    /**
     * Set the last TSN acknowledged by the peer.
     *
     * @param int $lastSackedTsn The last acknowledged TSN.
     */
    public function setLastSackedTsn(int $lastSackedTsn): void
    {
        $this->lastSackedTsn = $lastSackedTsn;
    }

    /**
     * Set the advanced peer acknowledgment TSN.
     *
     * @param int $advancedPeerAckTsn The advanced cumulative TSN from the peer.
     */
    public function setAdvancedPeerAckTsn(int $advancedPeerAckTsn): void
    {
        $this->advancedPeerAckTsn = $advancedPeerAckTsn;
    }

    /**
     * Get the advanced peer acknowledgment TSN.
     *
     * @return int The advanced peer ACK TSN.
     */
    public function getAdvancedPeerAckTsn(): int
    {
        return $this->advancedPeerAckTsn;
    }

    /**
     * Get the most recently received FORWARD TSN chunk.
     *
     * @return ForwardTsnChunk|null The FORWARD TSN chunk or null if not set.
     */
    public function getForwardTsnChunk(): ?ForwardTsnChunk
    {
        return $this->forwardTsnChunk;
    }

    /**
     * Get the last received TSN from the peer.
     *
     * @return int|null The last received TSN or null if not available.
     */
    public function getLastReceivedTsn(): ?int
    {
        return $this->lastReceivedTsn;
    }

    /**
     * Set the last received TSN from the peer.
     *
     * @param int|null $lastReceivedTsn The TSN value.
     */
    public function setLastReceivedTsn(?int $lastReceivedTsn): void
    {
        $this->lastReceivedTsn = $lastReceivedTsn;
    }

    /**
     * Check if a Selective ACK (SACK) needs to be sent.
     *
     * @return bool True if a SACK is pending, false otherwise.
     */
    public function isSackNeeded(): bool
    {
        return $this->sackNeeded;
    }

    /**
     * Indicate whether a SACK should be sent.
     *
     * @param bool $sackNeeded Whether a SACK is required.
     */
    public function setSackNeeded(bool $sackNeeded): void
    {
        $this->sackNeeded = $sackNeeded;
    }

    /**
     * Get the list of duplicate TSNs received.
     *
     * @return int[] List of duplicate TSNs.
     */
    public function getSackDuplicates(): array
    {
        return $this->sackDuplicates;
    }

    /**
     * Set the list of duplicate TSNs.
     *
     * @param int[] $sackDuplicates Duplicate TSNs.
     */
    public function setSackDuplicates(array $sackDuplicates): void
    {
        $this->sackDuplicates = $sackDuplicates;
    }

    /**
     * Get the list of misordered TSNs.
     *
     * @return int[] List of misordered TSNs.
     */
    public function getSackMisordered(): array
    {
        return $this->sackMisordered;
    }

    /**
     * Set the list of misordered TSNs.
     *
     * @param int[] $sackMisordered Misordered TSNs.
     */
    public function setSackMisordered(array $sackMisordered): void
    {
        $this->sackMisordered = $sackMisordered;
    }

    /**
     * Get the last acknowledged TSN.
     *
     * @return int The last SACKed TSN.
     */
    public function getLastSackedTsn(): int
    {
        return $this->lastSackedTsn;
    }

    /**
     * Get the outbound stream sequence numbers.
     *
     * @return array<int, int> Associative array of stream ID => sequence number.
     */
    public function getOutboundStreamSeq(): array
    {
        return $this->outboundStreamSeq;
    }

    /**
     * Get the current congestion window (cwnd) size.
     *
     * @return int The cwnd in bytes.
     */
    public function getCwnd(): int
    {
        return $this->cwnd;
    }

    /**
     * Set the congestion window (cwnd) size.
     *
     * @param int $cwnd The cwnd value in bytes.
     */
    public function setCwnd(int $cwnd): void
    {
        $this->cwnd = $cwnd;
    }

    /**
     * Get the slow-start threshold (ssthresh).
     *
     * @return int The ssthresh value in bytes.
     */
    public function getSsthresh(): int
    {
        return $this->ssthresh;
    }

    /**
     * Set the slow-start threshold (ssthresh).
     *
     * @param int $ssthresh The threshold in bytes.
     */
    public function setSsthresh(int $ssthresh): void
    {
        $this->ssthresh = $ssthresh;
    }

    /**
     * Get the TSN at which fast recovery ends.
     *
     * @return int|null The TSN value or null if not set.
     */
    public function getFastRecoveryExit(): ?int
    {
        return $this->fastRecoveryExit;
    }

    /**
     * Set the TSN at which fast recovery ends.
     *
     * @param int|null $fastRecoveryExit The TSN value.
     */
    public function setFastRecoveryExit(?int $fastRecoveryExit): void
    {
        $this->fastRecoveryExit = $fastRecoveryExit;
    }

    /**
     * Get the number of bytes currently in flight (unacknowledged).
     *
     * @return int Current flight size in bytes.
     */
    public function getFlightSize(): int
    {
        return $this->flightSize;
    }

    /**
     * Set the current flight size (unacknowledged data).
     *
     * @param int $flightSize Bytes in flight.
     */
    public function setFlightSize(int $flightSize): void
    {
        $this->flightSize = $flightSize;
    }

    /**
     * Get the T2-shutdown timer instance.
     *
     * @return SctpTimer The shutdown timer.
     */
    public function getTimer2(): SctpTimer
    {
        return $this->timer2;
    }

    /**
     * Get the T1-init timer instance.
     *
     * @return SctpTimer The init timer.
     */
    public function getTimer1(): SctpTimer
    {
        return $this->timer1;
    }

    /**
     * Get the media stream identification (MID) associated with this transport.
     *
     * @return string|null The MID value or null if not set.
     */
    public function getMid(): ?string
    {
        return $this->mid;
    }

    /**
     * Check if chunk bundling is currently active.
     *
     * @return bool True if bundling is active, false otherwise.
     */
    public function isBundled(): bool
    {
        return $this->bundled;
    }

    /**
     * Enable or disable chunk bundling.
     *
     * @param bool $bundled Whether bundling is enabled.
     */
    public function setBundled(bool $bundled): void
    {
        $this->bundled = $bundled;
    }

    /**
     * Set the media stream identification (MID).
     *
     * @param string|null $mid The MID value.
     */
    public function setMid(?string $mid): void
    {
        $this->mid = $mid;
    }

    /**
     * Increase the flight size by the book size of the chunk.
     *
     * @param DataChunk $chunk The data chunk.
     */
    private function flightSizeIncrease(DataChunk $chunk): void
    {
        $this->flightSize += $chunk->getAttributes()->bookSize;
    }

    /**
     * Set what extensions are supported by the remote party.
     *
     * @param array $params List of parameters (key-value pairs).
     */
    private function setExtensions(array $params): void
    {
        foreach ($params as [$k, $v]) {
            if ($k == SctpConstant::SCTP_PRSCTP_SUPPORTED) {
                $this->remotePartialReliability = true;
            } elseif ($k == SctpConstant::SCTP_SUPPORTED_CHUNK_EXT) {
                $this->remoteExtensions = array_values(unpack('C*', $v)); // Convert bytes to an array of integers
            }
        }
    }

    /**
     * Get what extensions are supported by the local party.
     *
     * @return array
     */
    private function getExtensions(): array
    {
        $params = [];
        $extensions = [];
        if ($this->localPartialReliability) {
            $params[] = [SctpConstant::SCTP_PRSCTP_SUPPORTED, ""];
            $extensions[] = $this->chunkTypes["ForwardTsnChunk"];
        }

        $extensions[] = $this->chunkTypes["ReconfigChunk"];
        $params[] = [SctpConstant::SCTP_SUPPORTED_CHUNK_EXT, pack('C*', ...$extensions)]; // Convert an array of integers to bytes

        return $params;
    }

    /**
     * Get or create the inbound stream with the specified ID.
     *
     * @param int $streamId The stream ID.
     * @return InboundStream The inbound stream.
     */
    private function getInboundStream(int $streamId): InboundStream
    {
        if (!isset($this->inboundStreams[$streamId])) {
            $this->inboundStreams[$streamId] = new InboundStream();
        }
        return $this->inboundStreams[$streamId];
    }

    /**
     * Handle data received from the network.
     *
     * @param string $data The received data.
     */
    public function onReceived(string $data): void
    {
        try {
            [, , $verificationTag, $chunks] = SctpPacket::decode($data);
        } catch (InvalidArgumentException) {
            return;
        }

        $initChunk = count(array_filter($chunks, fn($chunk) => $chunk instanceof InitChunk));
        $expectedTag = $initChunk > 0 ? 0 : $this->localVerificationTag;
        // Verify tag
        if ($verificationTag != $expectedTag) {
            $this->log(sprintf("Bad verification tag %d vs %d", $verificationTag, $expectedTag));
            return;
        }

        // Handle chunks
        foreach ($chunks as $chunk) {
            $this->receiveChunk($chunk);
        }

        // Send SACK if needed
        if ($this->sackNeeded) {
            $this->sendSack();
        }
    }

    /**
     * Determine if a chunk needs to be marked as abandoned.
     *
     * @param DataChunk $chunk The data chunk.
     * @return bool True if the chunk was abandoned, false otherwise.
     */
    public function maybeAbandon(DataChunk $chunk): bool
    {
        if ($chunk->getAttributes()->abandoned) {
            return true;
        }

        // Check if the chunk should be abandoned
        $abandoned = ($chunk->getAttributes()->maxRetransmits !== null && $chunk->getAttributes()->sentCount > $chunk->getAttributes()->maxRetransmits) ||
            ($chunk->getAttributes()->expiry > 0 && $chunk->getAttributes()->expiry < time());
        if (!$abandoned) {
            return false;
        }

        // Locate chunk position in queue
        $chunkPos = null;
        foreach ($this->sentQueue as $pos => $queuedChunk) {
            if ($queuedChunk === $chunk) {
                $chunkPos = $pos;
                break;
            }
        }

        if ($chunkPos === null) {
            return false; // Chunk is not found in the queue
        }

        // Mark chunks as abandoned (both before and after the current chunk)
        $this->markChunksAbandoned($chunkPos, true);
        $this->markChunksAbandoned($chunkPos, false);

        return true;
    }

    /**
     * Mark chunks as abandoned in the specified direction.
     *
     * @param int $startPos Position of the chunk to start from.
     * @param bool $reverse If true, process backwards; otherwise, forward.
     */
    private function markChunksAbandoned(int $startPos, bool $reverse): void
    {
        $step = $reverse ? -1 : 1;
        $limit = $reverse ? 0 : count($this->sentQueue) - 1;

        for ($pos = $startPos; $reverse ? $pos >= $limit : $pos <= $limit; $pos += $step) {
            $chunk = $this->sentQueue[$pos];
            $chunk->getAttributes()->abandoned = true;
            $chunk->getAttributes()->retransmit = false;

            if (($reverse && ($chunk->getFlags() & SctpConstant::SCTP_DATA_FIRST_FRAG)) ||
                (!$reverse && ($chunk->getFlags() & SctpConstant::SCTP_DATA_LAST_FRAG))) {
                break;
            }
        }
    }

    /**
     * Handle an incoming chunk.
     *
     * @param Chunk $chunk The incoming chunk.
     */
    public function receiveChunk(Chunk $chunk): void
    {
        $this->log(sprintf(" Received chunk %s", $chunk));
        call_user_func([$this, "receive" . basename(str_replace('\\', '/', get_class($chunk)))], $chunk);
    }

    /**
     * Handle a DATA chunk.
     *
     * @param DataChunk $chunk The DATA chunk.
     */
    public function receiveDataChunk(DataChunk $chunk): void
    {
        $this->sackNeeded = true;

        // Mark as received
        if ($this->markReceived($chunk->getTsn())) {
            return;
        }

        // Find stream
        $inboundStream = $this->getInboundStream($chunk->getStreamId());

        // Defragment data
        $inboundStream->addChunk($chunk);
        $this->advertisedRwnd -= strlen($chunk->getUserData());
        foreach ($inboundStream->popMessages() as $message) {
            $this->advertisedRwnd += strlen($message[2]);
            $this->receive(...$message);
        }
    }

    /**
     * Mark incoming data TSN as received.
     *
     * @param int $tsn The TSN to mark.
     * @return bool True if the TSN was a duplicate, false otherwise.
     */
    public function markReceived(int $tsn): bool
    {
        // Mark incoming data TSN as received.

        // It's a duplicate
        if (SctpUtility::uint32Gte($this->lastReceivedTsn, $tsn) || in_array($tsn, $this->sackMisordered, true)) {
            $this->sackDuplicates[] = $tsn;
            return true;
        }

        // Consolidate misordered entries
        $this->sackMisordered[] = $tsn;
        sort($this->sackMisordered);

        foreach ($this->sackMisordered as $index => $misorderedTsn) {
            if ($misorderedTsn === SctpUtility::tsnPlusOne($this->lastReceivedTsn)) {
                $this->lastReceivedTsn = $misorderedTsn;
                unset($this->sackMisordered[$index]);
            } else {
                break;
            }
        }

        // Filter out obsolete entries
        $this->sackDuplicates = array_filter($this->sackDuplicates, fn($x) => SctpUtility::uint32Gt($x, $this->lastReceivedTsn));
        $this->sackMisordered = array_filter($this->sackMisordered, fn($x) => SctpUtility::uint32Gt($x, $this->lastReceivedTsn));

        return false;
    }

    /**
     * Receive data stream -> ULP.
     *
     * @param int $streamId The stream ID.
     * @param int $ppId The payload protocol ID.
     * @param string $data The received data.
     */
    public function receive(int $streamId, int $ppId, string $data): void
    {
        $this->dataChannelReceive($streamId, $ppId, $data);
    }

    /**
     * Handle a FORWARD TSN chunk.
     *
     * @param ForwardTsnChunk $chunk The FORWARD TSN chunk.
     */
    public function receiveForwardTsnChunk(ForwardTsnChunk $chunk): void
    {
        $this->sackNeeded = true;

        // Ignore duplicate TSNs
        if (SctpUtility::uint32Gte($this->lastReceivedTsn, $chunk->getCumulativeTsn())) {
            return;
        }

        $isObsolete = fn($tsn) => SctpUtility::uint32Gt($tsn, $this->lastReceivedTsn);

        // Advance cumulative TSN
        $this->lastReceivedTsn = $chunk->getCumulativeTsn();
        $this->sackMisordered = array_filter($this->sackMisordered, $isObsolete);

        // Sort and update misordered TSNs
        sort($this->sackMisordered);
        foreach ($this->sackMisordered as $tsn) {
            if ($tsn === SctpUtility::tsnPlusOne($this->lastReceivedTsn)) {
                $this->lastReceivedTsn = $tsn;
            } else {
                break;
            }
        }

        // Remove obsolete entries
        $this->sackDuplicates = array_values(array_filter($this->sackDuplicates, $isObsolete));
        $this->sackMisordered = array_values(array_filter($this->sackMisordered, $isObsolete));

        // Process reassembly
        foreach ($chunk->getStreams() as [$streamId, $streamSeq]) {
            $inboundStream = $this->getInboundStream($streamId);

            // Update sequence number and process messages
            $inboundStream->setSequenceNumber(SctpUtility::uint16Add($streamSeq, 1));
            foreach ($inboundStream->popMessages() as $message) {
                $this->advertisedRwnd += strlen($message[2]);
                $this->receive(...$message);
            }
        }

        // Prune obsolete chunks
        foreach ($this->inboundStreams as $inboundStream) {
            $this->advertisedRwnd += $inboundStream->pruneChunks($this->lastReceivedTsn);
        }
    }

    /**
     * Handle a SACK chunk.
     *
     * @param SackChunk $chunk The SACK chunk.
     */
    function receiveSackChunk(SackChunk $chunk): void
    {
        if (SctpUtility::uint32Gt($this->lastSackedTsn, $chunk->getCumulativeTsn())) {
            return;
        }

        $this->lastSackedTsn = $chunk->getCumulativeTsn();
        $cwndFullyUtilized = $this->flightSize >= $this->cwnd;

        [$done, $doneBytes] = $this->handleAcknowledgedData();
        $loss = $this->handleGapBlocks($chunk);

        $this->adjustCongestionWindow($done, $doneBytes, $cwndFullyUtilized, $loss, $chunk);
        $this->handleDataChannelTimer($done);
        $this->updateAdvancedPeerAckPoint();

        $this->dataChannelFlush();
        $this->transmit();
    }

    /**
     * Processes acknowledged data chunks up to the last reported SACK TSN.
     *
     * Chunks are dequeued from the sent queue if their TSN is less than or equal to
     * the last acknowledged TSN. Updates RTO if applicable and decreases flight size.
     *
     * @return array{0: int, 1: int} Number of chunks acknowledged and total acknowledged bytes.
     */
    private function handleAcknowledgedData(): array
    {
        $done = 0;
        $doneBytes = 0;

        while (!$this->sentQueue->isEmpty() && SctpUtility::uint32Gte($this->lastSackedTsn, $this->sentQueue->bottom()->getTsn())) {
            $schunk = $this->sentQueue->dequeue();
            $done++;
            if (!$schunk->getAttributes()->acked) {
                $doneBytes += $schunk->getAttributes()->bookSize;
                $this->decreaseFlightSize($schunk);
            }

            // Update RTO estimate
            if ($done === 1 && $schunk->getAttributes()->sentCount === 1) {
                $this->updateRto(time() - $schunk->getAttributes()->sentTime);
            }
        }

        return [$done, $doneBytes];
    }

    /**
     * Handles reported SACK gap blocks and marks missing chunks for retransmission.
     *
     * Chunks marked as missing for three consecutive SACKs are retransmitted unless abandoned.
     *
     * @param SackChunk $chunk The received SACK chunk with gap acknowledgment blocks.
     * @return bool True if any loss was detected, false otherwise.
     */
    private function handleGapBlocks(SackChunk $chunk): bool
    {
        $loss = false;
        $highestSeenTsn = 0;

        if (!empty($chunk->getGaps())) {
            $seen = [];
            foreach ($chunk->getGaps() as $gap) {
                for ($pos = $gap[0]; $pos <= $gap[1]; $pos++) {
                    $highestSeenTsn = ($chunk->getCumulativeTsn() + $pos) % SctpConstant::SCTP_TSN_MODULO;
                    $seen[$highestSeenTsn] = true;
                }
            }

            $highestNewlyAcked = $chunk->getCumulativeTsn();
            foreach ($this->sentQueue as $schunk) {
                if (SctpUtility::uint32Gt($schunk->getTsn(), $highestSeenTsn)) {
                    break;
                }
                if (isset($seen[$schunk->getTsn()]) && !$schunk->getAttributes()->acked) {
                    $schunk->getAttributes()->acked = true;
                    $this->decreaseFlightSize($schunk);
                    $highestNewlyAcked = $schunk->getTsn();
                }
            }

            // Handle missing chunks before HTNA
            foreach ($this->sentQueue as $schunk) {
                if (SctpUtility::uint32Gt($schunk->getTsn(), $highestNewlyAcked)) {
                    break;
                }
                if (!isset($seen[$schunk->getTsn()])) {
                    $schunk->getAttributes()->misses++;
                    if ($schunk->getAttributes()->misses === 3) {
                        $schunk->getAttributes()->misses = 0;
                        if (!$this->maybeAbandon($schunk)) {
                            $schunk->getAttributes()->retransmit = true;
                        }
                        $schunk->getAttributes()->acked = false;
                        $this->decreaseFlightSize($schunk);
                        $loss = true;
                    }
                }
            }
        }
        return $loss;
    }

    /**
     * Adjusts the congestion window (cwnd) based on acknowledgment and loss information.
     *
     * Implements slow start and congestion avoidance algorithms, as well as fast recovery exit.
     *
     * @param int $done Number of chunks acknowledged.
     * @param int $doneBytes Number of bytes acknowledged.
     * @param bool $cwndFullyUtilized Whether cwnd was fully utilized.
     * @param bool $loss Whether loss was detected.
     * @param SackChunk $chunk The current SACK chunk.
     * @return void
     */
    private function adjustCongestionWindow(int $done, int $doneBytes, bool $cwndFullyUtilized, bool $loss, SackChunk $chunk): void
    {
        if ($this->fastRecoveryExit === null) {
            if ($done && $cwndFullyUtilized) {
                if ($this->cwnd <= $this->ssthresh) {
                    // Slow start
                    $this->cwnd += min($doneBytes, SctpConstant::USERDATA_MAX_LENGTH);
                } else {
                    // Congestion avoidance
                    $this->partialBytesAcked += $doneBytes;
                    if ($this->partialBytesAcked >= $this->cwnd) {
                        $this->partialBytesAcked -= $this->cwnd;
                        $this->cwnd += SctpConstant::USERDATA_MAX_LENGTH;
                    }
                }
            }
            if ($loss) {
                $this->ssthresh = max((int)($this->cwnd / 2), 4 * SctpConstant::USERDATA_MAX_LENGTH);
                $this->cwnd = $this->ssthresh;
                $this->partialBytesAcked = 0;
                $this->fastRecoveryExit = $this->sentQueue->top()->getTsn() ?? null;
                $this->fastRecoveryTransmit = true;
            }
        } elseif (SctpUtility::uint32Gte($chunk->getCumulativeTsn(), $this->fastRecoveryExit)) {
            $this->fastRecoveryExit = null;
        }
    }

    /**
     * Starts or stops the retransmission timer (T3) based on acknowledgment state.
     *
     * Stops the timer if no data is outstanding. Restart it if new data is acknowledged.
     *
     * @param int $done Number of chunks acknowledged in the latest SACK.
     * @return void
     */
    private function handleDataChannelTimer(int $done): void
    {
        if ($this->sentQueue->isEmpty()) {
            // No outstanding data, stop T3
            $this->dataChannelTaskCancel();
        } elseif ($done) {
            // The earliest outstanding chunk was acknowledged, restart T3
            $this->dataChannelTaskRestart();
        }
    }

    /**
     * Responds to a HEARTBEAT chunk by sending a HEARTBEAT_ACK.
     *
     * @param HeartbeatChunk $chunk The received HEARTBEAT chunk.
     * @return void
     */
    public function receiveHeartbeatChunk(HeartbeatChunk $chunk): void
    {
        $ack = new HeartbeatAckChunk();
        $ack->setParams($chunk->getParams());
        $this->sendChunk($ack);
    }

    /**
     * Handles an ABORT chunk and transitions the connection to CLOSED state.
     *
     * @param AbortChunk $chunk The received ABORT chunk.
     * @return void
     */
    public function receiveAbortChunk(AbortChunk $chunk): void
    {
        $this->log(" The connection was terminated by the remote party.");
        $this->setState(State::CLOSED);
    }

    /**
     * Handles a SHUTDOWN chunk and responds with SHUTDOWN_ACK.
     *
     * Also cancels the shutdown timer and sets the appropriate connection state.
     *
     * @param ShutdownChunk $chunk The received SHUTDOWN chunk.
     * @return void
     */
    public function receiveShutdownChunk(ShutdownChunk $chunk): void
    {
        $this->timer2->cancel();
        $this->setState(State::SHUTDOWN_RECEIVED);
        $ack = new ShutdownAckChunk();
        $this->timer2->start($ack);
        $this->setState(State::SHUTDOWN_ACK_SENT);
        $this->sendChunk($ack);
    }

    /**
     * Finalizes connection shutdown on receipt of SHUTDOWN_COMPLETE chunk.
     *
     * Transitions to CLOSED state if in SHUTDOWN_ACK_SENT state.
     *
     * @param ShutdownCompleteChunk $chunk The received SHUTDOWN_COMPLETE chunk.
     * @return void
     */
    public function receiveShutdownCompleteChunk(ShutdownCompleteChunk $chunk): void
    {
        if ($this->state != State::SHUTDOWN_ACK_SENT) {
            return;
        }
        $this->timer2->cancel();
        $this->setState(State::CLOSED);
    }

    /**
     * Handles a RECONFIG chunk by parsing and applying each parameter.
     *
     * Only processes the chunk if in ESTABLISHED state.
     *
     * @param ReconfigChunk $chunk The received RECONFIG chunk.
     * @return void
     */
    public function receiveReconfigChunk(ReconfigChunk $chunk): void
    {
        if ($this->state != State::ESTABLISHED) {
            return;
        }
        foreach ($chunk->getParams() as $param) {
            $cls = "Webrtc\SCTP\Param\\" . self::RECONFIG_PARAM_TYPES[$param[0]] ?? null;
            if (class_exists($cls)) {
                $this->receiveReconfigParam($cls::decode($param[1]));
            }
        }
    }

    /**
     * Handles an INIT chunk from a peer to begin a connection establishment.
     *
     * Sets TSN, verification tag, stream limits, and responds with INIT_ACK.
     * Only valid if the local endpoint is acting as server.
     *
     * @param InitChunk $chunk The received INIT chunk.
     * @return void
     */
    public function receiveInitChunk(InitChunk $chunk): void
    {
        if (!$this->isServer()) {
            return;
        }
        $this->lastReceivedTsn = SctpUtility::tsnMinusOne($chunk->getInitialTsn());
        $this->reconfigResponseSeq = SctpUtility::tsnMinusOne($chunk->getInitialTsn());
        $this->remoteVerificationTag = $chunk->getInitiateTag();

        $this->ssthresh = $chunk->getAdvertisedRwnd();
        $this->setExtensions($chunk->getParams());

        $this->log(sprintf(
            "The peer supports %d outbound and %d inbound streams.",
            $chunk->getOutboundStreams(),
            $chunk->getInboundStreams()
        ));
        $this->inboundStreamsCount = min(
            $chunk->getOutboundStreams(),
            $this->inboundStreamsMax
        );
        $this->outboundStreamsCount = min(
            $this->outboundStreamsCount,
            $chunk->getInboundStreams()
        );

        $ack = new InitAckChunk();
        $ack->setInitiateTag($this->localVerificationTag);
        $ack->setAdvertisedRwnd($this->advertisedRwnd);
        $ack->setOutboundStreams($this->outboundStreamsCount);
        $ack->setInboundStreams($this->inboundStreamsMax);
        $ack->setInitialTsn($this->localTsn);
        $ack->addParams($this->getExtensions());

        // Generate state cookie
        $cookie = pack("N", $this->getTime());
        $cookie .= hash_hmac("sha1", $cookie, $this->hmacKey, true);
        $ack->addParams([[SctpConstant::SCTP_STATE_COOKIE, $cookie]]);
        $this->sendChunk($ack);
    }

    /**
     * Validates a COOKIE_ECHO chunk and establishes the association.
     *
     * Verifies the state cookie MAC and lifetime. Responds with COOKIE_ACK.
     * Only valid if the local endpoint is acting as server.
     *
     * @param CookieEchoChunk $chunk The received COOKIE_ECHO chunk.
     * @return void
     */
    public function receiveCookieEchoChunk(CookieEchoChunk $chunk): void
    {
        if (!$this->isServer()) {
            return;
        }
        // Check state cookie MAC
        $cookie = $chunk->getBody();
        if (
            strlen($cookie) != SctpConstant::COOKIE_LENGTH ||
            hash_hmac("sha1", substr($cookie, 0, 4), $this->hmacKey, true) != substr($cookie, 4)
        ) {
            $this->log("x State cookie is invalid");

            return;
        }

        // Check state cookie lifetime
        $stamp = unpack("N", substr($cookie, 0, 4))[1];
        $now = $this->getTime();
        if ($stamp < $now - SctpConstant::COOKIE_LIFETIME || $stamp > $now) {
            $this->log("Cookie has expired");
            $error = new ErrorChunk();
            $error->addParams([[SctpConstant::SCTP_CAUSE_STALE_COOKIE, str_repeat("\x00", 8)]]);
            $this->sendChunk($error);
            return;
        }
        $this->setState(State::ESTABLISHED);
        $ack = new CookieAckChunk();
        $this->sendChunk($ack);
    }

    /**
     * Handles an INIT_ACK chunk and replies with COOKIE_ECHO.
     *
     * Extracts state cookie and stream parameters from the INIT_ACK.
     * Only processed if currently in COOKIE_WAIT state.
     *
     * @param InitAckChunk $chunk The received INIT_ACK chunk.
     * @return void
     */
    public function receiveInitAckChunk(InitAckChunk $chunk): void
    {
        if ($this->state != State::COOKIE_WAIT) {
            return;
        }
        $this->setState(State::COOKIE_ECHOED);

        $this->timer1->cancel();
        $this->lastReceivedTsn = SctpUtility::tsnMinusOne($chunk->getInitialTsn());
        $this->reconfigResponseSeq = SctpUtility::tsnMinusOne($chunk->getInitialTsn());
        $this->remoteVerificationTag = $chunk->getInitiateTag();
        $this->ssthresh = $chunk->getAdvertisedRwnd();
        $this->setExtensions($chunk->getParams());

        $this->log(sprintf(
            "Peer supports %d outbound streams, %d max inbound streams",
            $chunk->getOutboundStreams(),
            $chunk->getInboundStreams()
        ));
        $this->inboundStreamsCount = min(
            $chunk->getOutboundStreams(),
            $this->inboundStreamsMax
        );
        $this->outboundStreamsCount = min(
            $this->outboundStreamsCount,
            $chunk->getInboundStreams()
        );

        $echo = new CookieEchoChunk();
        foreach ($chunk->getParams() as [$k, $v]) {
            if ($k == SctpConstant::SCTP_STATE_COOKIE) {
                $echo->setBody($v);
                break;
            }
        }

        // Start T1 timer and enter COOKIE-ECHOED state
        $this->timer1->start($echo);

        $this->sendChunk($echo);
    }

    /**
     * Handles a COOKIE_ACK chunk and transitions to ESTABLISHED state.
     *
     * Only processed if currently in COOKIE_ECHOED state.
     *
     * @param CookieAckChunk $chunk The received COOKIE_ACK chunk.
     * @return void
     */
    public function receiveCookieAckChunk(CookieAckChunk $chunk): void
    {
        if ($this->state != State::COOKIE_ECHOED) {
            return;
        }
        $this->setState(State::ESTABLISHED);
        $this->timer1->cancel();
    }

    /**
     * Handles an ERROR chunk received during handshake.
     *
     * Closes the connection if in COOKIE_WAIT or COOKIE_ECHOED state.
     *
     * @param ErrorChunk $chunk The received ERROR chunk.
     * @return void
     */
    public function receiveErrorChunk(ErrorChunk $chunk): void
    {
        if (!in_array($this->state, [State::COOKIE_WAIT, State::COOKIE_ECHOED])) {
            return;
        }

        $this->timer1->cancel();
        $this->setState(State::CLOSED);
        $this->log("Could not establish association");
    }

    /**
     * Handle a RE-CONFIG parameter.
     *
     * @param StreamParamInterface $param The RE-CONFIG parameter.
     */
    public function receiveReconfigParam(StreamParamInterface $param): void
    {
        $this->log(sprintf(" received param: %s", $param));

        if ($param instanceof StreamResetOutgoingParam) {
            // Mark closed inbound streams
            foreach ($param->getStreams() as $streamId) {
                unset($this->inboundStreams[$streamId]);

                // Close data channel
                $channel = $this->dataChannels[$streamId] ?? null;
                if ($channel) {
                    $this->dataChannelClose($channel);
                }
            }

            // Send response
            $responseParam = new StreamResetResponseParam(
                $param->getRequestSequence(),
                1 // Result: success
            );
            $this->reconfigResponseSeq = $param->getRequestSequence();

            $this->sendReconfigParam($responseParam);
        } elseif ($param instanceof StreamAddOutgoingParam) {
            // Increase inbound streams
            $this->inboundStreamsCount += $param->getNewStreams();

            // Send response
            $responseParam = new StreamResetResponseParam(
                $param->getRequestSequence(),
                1 // Result: success
            );
            $this->reconfigResponseSeq = $param->getRequestSequence();

            $this->sendReconfigParam($responseParam);
        } elseif ($param instanceof StreamResetResponseParam) {
            if (
                $this->reconfigRequest !== null &&
                $param->getResponseSequence() == $this->reconfigRequest->getRequestSequence()
            ) {
                // Mark closed streams
                foreach ($this->reconfigRequest->getStreams() as $streamId) {
                    unset($this->outboundStreamSeq[$streamId]);

                    $this->dataChannelClosed($streamId);
                }

                $this->reconfigRequest = null;
                $this->transmitReconfig();
            }
        }
    }

    /**
     * Send a RE-CONFIG parameter.
     *
     * @param StreamParamInterface $param The RE-CONFIG parameter.
     */
    public function sendReconfigParam(StreamParamInterface $param): void
    {
        $paramType = -1;

        $chunk = new ReconfigChunk();
        foreach (self::RECONFIG_PARAM_TYPES as $k => $cls) {
            if ($param instanceof ("Webrtc\SCTP\Param\\" . $cls)) {
                $paramType = $k;
                break;
            }
        }
        $chunk->addParams([[$paramType, $param->encode()]]);

        $this->log(sprintf(" sent stream param %s", $param));
        $this->sendChunk($chunk);
    }


    /**
     * Build and send a selective acknowledgement (SACK) chunk.
     *
     * @return void
     */
    public function sendSack(): void
    {
        $gaps = [];
        $gapNext = null;
        foreach ($this->sackMisordered as $tsn) {
            $pos = ($tsn - $this->lastReceivedTsn) % SctpConstant::SCTP_TSN_MODULO;
            if ($tsn == $gapNext) {
                $gaps[count($gaps) - 1][1] = $pos;
            } else {
                $gaps[] = [$pos, $pos];
            }
            $gapNext = SctpUtility::tsnPlusOne($tsn);
        }

        $sack = new SackChunk();
        $sack->setCumulativeTsn($this->lastReceivedTsn);
        $sack->setAdvertisedRwnd(max(0, $this->advertisedRwnd));
        $sack->setDuplicates($this->sackDuplicates);
        $sack->setGaps($gaps);

        $this->sendChunk($sack);

        $this->sackDuplicates = [];
        $this->sackNeeded = false;
    }

    /**
     * Transition the SCTP association to a new state.
     *
     * @param State $state The new state.
     */
    public function setState(State $state): void
    {
        if ($state != $this->state) {
            $this->log(sprintf(" State has changed FROM: %s TO: %s", $this->state->name, $state->name));
            $this->state = $state;
        }

        if ($state == State::ESTABLISHED) {
            foreach ($this->dataChannels as $channel) {
                if ($channel->isNegotiated() && $channel->getReadyState() != DataChannelState::Open) {
                    $channel->setReadyState(DataChannelState::Open);
                }
            }
            $this->dataChannelFlush();
        } elseif ($state == State::CLOSED) {
            $this->timer1->cancel();
            $this->timer2->cancel();
            $this->dataChannelTaskCancel();
            $this->state = State::CLOSED;

            // Close data channels
            foreach ($this->dataChannels as $streamId => $channel) {
                $this->dataChannelClosed($streamId);
            }

            // Remove all event listeners
            $this->removeAllListeners();
        }
    }

    /**
     * @return void
     */
    public function onErrorOrClosed(): void
    {
        $this->stop();
    }

    /**
     * @param string $message
     * @return void
     */
    private function log(string $message): void
    {
        $this->logger?->debug(sprintf("[RTC_SCTP]: %s", $message));
    }

    /**
     * @return int
     */
    public function getTime(): int
    {
        return time();
    }
}
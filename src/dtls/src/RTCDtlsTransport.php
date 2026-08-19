<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\DTLS;

use altay\dtls\Connection;
use altay\dtls\Exception\DtlsException as NativeDtlsException;
use Evenement\EventEmitter;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Throwable;
use Webrtc\DataChannel\RTCSctpTransportInterface;
use Webrtc\DTLS\Exception\DTLSException;
use Webrtc\ICE\Enum\IceRole;
use Webrtc\ICE\RTCIceTransportInterface;
use Webrtc\Mixin\EventForwarder;
use Webrtc\SCTP\RTCSctpDtlsTransportInterface;
use Webrtc\SCTP\RTCSctpTransport;
use Webrtc\SDP\DtlsParameter\RTCDtlsFingerprint;
use Webrtc\SDP\DtlsParameter\RTCDtlsParameters;
use Webrtc\SDP\Enum\DtlsRole;
use Webrtc\Stats\enum\TLSState;
use Webrtc\Stats\RTCStatsReport;
use Webrtc\Stats\RTCTransportStats;
use function React\Async\async;
use function React\Async\await;

/**
 * Class RTCDtlsTransport
 *
 * The DTLS layer for data channels, carried over the ICE transport underneath.
 *
 * The handshake itself is done by altayofficial/dtls, which is written against ext-openssl and
 * so needs nothing loaded at runtime. Records arriving on the ICE transport are fed straight in,
 * and decrypted application data is handed to the SCTP receiver.
 *
 * @package Webrtc\DTLS
 */
class RTCDtlsTransport extends EventEmitter implements RTCSctpDtlsTransportInterface
{
    use EventForwarder;

    /**
     * How long to wait for the peer to complete the handshake before giving up.
     */
    private const HANDSHAKE_TIMEOUT = 30.0;

    /**
     * How often to check whether a flight needs retransmitting.
     */
    private const RETRANSMIT_INTERVAL = 0.25;

    /** @var TLSState Current state of the DTLS transport */
    private TLSState $state = TLSState::NEW;

    /** @var LoggerInterface|null Optional logger for debugging */
    private ?LoggerInterface $logger = null;

    /** @var RTCTransportStats Transport statistics collector */
    private RTCTransportStats $reportTransport;

    /** @var RTCSctpTransportInterface|null SCTP transport for data channels */
    private ?RTCSctpTransportInterface $sctpReceiver = null;

    /** @var Connection|null The DTLS endpoint, created once the role is known */
    private ?Connection $connection = null;

    /** @var TimerInterface|null Drives handshake retransmission */
    private ?TimerInterface $retransmitTimer = null;

    /** @var DtlsRole The role (client/server) in the DTLS handshake */
    private DtlsRole $role = DtlsRole::Auto;

    public function __construct(private readonly RTCIceTransportInterface $transport, private readonly RTCCertificate $certificate)
    {
        $this->reportTransport = new RTCTransportStats("transport_" . spl_object_id($this));
        $this->forwardEvents2Methods($transport, ['close' => 'handleDisconnectingError', 'error' => 'handleDisconnectingError']);
    }

    /**
     * Encrypts and sends application data over the DTLS connection.
     *
     * @throws DTLSException if the connection is not established
     */
    public function sendData(string $data): void
    {
        if ($this->connection === null || !$this->connection->isEstablished()) {
            throw new DTLSException("Unable to send: no DTLS connection established.");
        }

        $this->connection->send($data);
    }

    /**
     * Gets the local DTLS parameters including certificate fingerprints.
     */
    public function getLocalParameters(): RTCDtlsParameters
    {
        return new RTCDtlsParameters($this->certificate->getFingerprints());
    }

    public function setRole(DtlsRole $getRole): void
    {
        $this->role = $getRole;
    }

    public function getRole(): DtlsRole
    {
        return $this->role;
    }

    /**
     * Sends raw data through the underlying transport.
     */
    public function send(string $data): void
    {
        $this->transport->send($data);
        $this->reportTransport->handleSent($data);
    }

    public function getReportTransport(): RTCTransportStats
    {
        return $this->reportTransport;
    }

    /**
     * Removes an SCTP receiver (placeholder implementation).
     */
    public function removeSctpReceiver(RTCSctpTransport $param): void
    {
        // TODO: Implement proper removal logic
    }

    /**
     * Runs the DTLS handshake and, once it completes, checks the peer against the fingerprints
     * that arrived over signalling.
     *
     * @param RTCDtlsFingerprint[] $certificates fingerprints from the remote description
     * @throws DTLSException if the handshake fails or the peer is not who signalling said
     */
    public function start(array $certificates): void
    {
        $this->setState(TLSState::CONNECTING);

        $established = new Deferred();
        $identity = $this->certificate->getIdentity();
        $send = function (string $datagram): void {
            $this->send($datagram);
        };

        $this->connection = $this->resolveRole() === DtlsRole::Server
            ? Connection::server($identity, $send)
            : Connection::client($identity, $send);

        $this->connection->onApplicationData(function (string $data): void {
            $this->sctpReceiver?->onReceived($data);
        });
        $this->connection->onEstablished(static function () use ($established): void {
            $established->resolve(true);
        });

        // records arrive on the ICE transport from now on
        $this->forwardEvents2Methods($this->transport, ['data' => 'onReceivedData']);

        // a lost flight is only recovered if something keeps nudging the connection
        $this->retransmitTimer = Loop::addPeriodicTimer(self::RETRANSMIT_INTERVAL, function (): void {
            $this->connection?->handleTimeout();
        });

        try {
            $this->connection->start();
            await($this->withTimeout($established->promise()));
        } catch (Throwable $e) {
            $this->cancelRetransmits();
            $this->setFailedState("DTLS: " . $e->getMessage());

            return;
        }

        $this->cancelRetransmits();

        if (!$this->verifyPeer($certificates)) {
            $this->setFailedState("DTLS: Fingerprint mismatch!");

            return;
        }

        $this->logger?->debug("DTLS: Successful handshake");
        $this->setState(TLSState::CONNECTED);
    }

    /**
     * The peer is trusted exactly when the certificate it presented matches a fingerprint from
     * the remote description. There is no chain to walk.
     *
     * @param RTCDtlsFingerprint[] $certificates
     */
    private function verifyPeer(array $certificates): bool
    {
        $actual = $this->connection?->getPeerFingerprint();
        if ($actual === null) {
            return false;
        }

        foreach ($certificates as $fingerprint) {
            if ($fingerprint->isAlgorithm("sha-256") && hash_equals(strtolower($actual), strtolower($fingerprint->value))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rejects the given promise if the handshake has not completed in time, so a peer that goes
     * silent mid flight fails the transport instead of hanging it.
     *
     * @param PromiseInterface<bool> $promise
     * @return PromiseInterface<bool>
     */
    private function withTimeout(PromiseInterface $promise): PromiseInterface
    {
        $deadline = new Deferred();
        $settled = false;

        $timer = Loop::addTimer(self::HANDSHAKE_TIMEOUT, static function () use ($deadline, &$settled): void {
            if (!$settled) {
                $settled = true;
                $deadline->reject(new DTLSException("handshake timed out"));
            }
        });

        $promise->then(static function ($value) use ($deadline, $timer, &$settled): void {
            if (!$settled) {
                $settled = true;
                Loop::cancelTimer($timer);
                $deadline->resolve($value);
            }
        });

        return $deadline->promise();
    }

    /**
     * Stops the DTLS transport and cleans up resources.
     */
    public function stop(): PromiseInterface
    {
        return async(function () {
            $this->cancelRetransmits();

            if (in_array($this->state, [TLSState::CONNECTING, TLSState::CONNECTED])) {
                try {
                    $this->connection?->close();
                } catch (Throwable) {
                    // the peer may already be gone; the state change below is what matters
                }
                $this->setState(TLSState::CLOSED);
                $this->logger?->debug("DTLS: DTLS shutdown process has been successfully completed. All secure connections have been terminated.");
            }
        })();
    }

    public function getIceTransport(): RTCIceTransportInterface
    {
        return $this->transport;
    }

    public function getCertificate(): RTCCertificate
    {
        return $this->certificate;
    }

    /**
     * @return RTCDtlsFingerprint[]
     */
    public function getPeerCertificates(): array
    {
        return $this->certificate->getFingerprints();
    }

    public function getState(): TLSState
    {
        return $this->state;
    }

    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function setSctpReceiver(?RTCSctpTransportInterface $sctpReceiver = null): void
    {
        $this->sctpReceiver = $sctpReceiver;
    }

    public function setState(TLSState $state): void
    {
        if ($state !== $this->state) {
            $this->logger?->debug(sprintf("State changed from %s to %s", $this->state->name, $state->name));
            $this->state = $state;
            $this->emit("statechange");
        }
    }

    private function setFailedState(string $reason): void
    {
        $this->logger?->error("DTLS: Failed handshaking.", ["reason" => $reason]);
        $this->setState(TLSState::FAILED);
    }

    /**
     * Feeds an inbound datagram from the ICE transport into the DTLS connection.
     */
    private function onReceivedData(string $data): void
    {
        $this->reportTransport->handleReceived($data);

        try {
            $this->connection?->handle($data);
        } catch (NativeDtlsException $e) {
            $this->logger?->debug(sprintf("DTLS: %s", $e->getMessage()));
        }
    }

    /**
     * @throws DTLSException Always throws to indicate connection loss
     */
    private function handleDisconnectingError(): void
    {
        $this->cancelRetransmits();
        $this->setState(TLSState::CLOSED);
        $this->logger?->alert("DTLS: Connection lost");
        $this->sctpReceiver?->onErrorOrClosed();

        throw new DTLSException("DTLS: Connection lost");
    }

    public function getStats(): RTCStatsReport
    {
        $this->reportTransport->dateTime = new \DateTimeImmutable();

        $report = new RTCStatsReport();
        $report->add($this->reportTransport);

        return $report;
    }

    /**
     * Resolves an "auto" role the way the ICE role dictates - the controlling agent takes the
     * DTLS server side.
     */
    private function resolveRole(): DtlsRole
    {
        if ($this->role === DtlsRole::Auto) {
            $this->role = $this->transport->getRole() === IceRole::Controlling ? DtlsRole::Server : DtlsRole::Client;
        }

        return $this->role;
    }

    private function cancelRetransmits(): void
    {
        if ($this->retransmitTimer !== null) {
            Loop::cancelTimer($this->retransmitTimer);
            $this->retransmitTimer = null;
        }
    }
}

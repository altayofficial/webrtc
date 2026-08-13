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

use Evenement\EventEmitter;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use Throwable;
use Webrtc\DataChannel\RTCSctpTransportInterface;
use Webrtc\DTLS\Enum\SSLHandshakeState;
use Webrtc\DTLS\Exception\DTLSException;
use Webrtc\DTLS\Exception\TLSException;
use Webrtc\DTLS\TLS\TLS;
use Webrtc\ICE\Enum\IceRole;
use Webrtc\ICE\RTCIceTransportInterface;
use Webrtc\Mixin\EventForwarder;
use Webrtc\NTP\NetworkTimeProtocol;
use Webrtc\RTCP\Exception\RtcpExceptionInterface;
use Webrtc\RTCP\RtcpPacket;
use Webrtc\RTP\Exception\RtpExceptionInterface;
use Webrtc\RTP\HeaderExtension\HeaderExtensionsMap;
use Webrtc\RTP\Receiver\RtpReceiverInterface;
use Webrtc\RTP\RTCRTPDtlsTransportInterface;
use Webrtc\RTP\RtpPacket;
use Webrtc\RTP\RtpRouter;
use Webrtc\RTP\RtpUtility;
use Webrtc\RTP\Sender\RtpSenderInterface;
use Webrtc\RTPParameter\RTCRtpReceiveParameters;
use Webrtc\RTPParameter\RTCRtpSendParameters;
use Webrtc\SCTP\RTCSctpDtlsTransportInterface;
use Webrtc\SCTP\RTCSctpTransport;
use Webrtc\SDP\DtlsParameter\RTCDtlsFingerprint;
use Webrtc\SDP\DtlsParameter\RTCDtlsParameters;
use Webrtc\SDP\Enum\DtlsRole;
use Webrtc\Srtp\Exception\SrtpException;
use Webrtc\Srtp\Exception\SrtpExceptionInterface;
use Webrtc\Srtp\Exception\SrtpValidateException;
use Webrtc\Srtp\Session;
use Webrtc\SSL\Exception\OpenSSLException;
use Webrtc\SSL\Exception\SSLException;
use Webrtc\SSL\Exception\SysCallException;
use Webrtc\SSL\Exception\WantReadException;
use Webrtc\SSL\Exception\WantWriteException;
use Webrtc\SSL\Exception\WantX509LookupException;
use Webrtc\SSL\Exception\ZeroReturnException;
use Webrtc\Stats\enum\TLSState;
use Webrtc\Stats\RTCStatsReport;
use Webrtc\Stats\RTCTransportStats;
use function React\Async\async;
use function React\Async\await;

/**
 * Class RTCDtlsTransport
 *
 * Represents the DTLS transport layer for WebRTC communications.
 * This class manages the secure transport of media and data channels over WebRTC,
 * handling encryption/decryption, SRTP protection, and packet routing.
 *
 * Key responsibilities:
 * - Establishing and maintaining DTLS connections
 * - Managing SRTP sessions for media encryption
 * - Routing RTP/RTCP packets to appropriate receivers/senders
 * - Handling SCTP data channel traffic
 * - Providing transport statistics
 *
 * @package Webrtc\DTLS
 */
class RTCDtlsTransport extends EventEmitter implements RTCRTPDtlsTransportInterface, RTCSctpDtlsTransportInterface
{
    use EventForwarder;

    /** @var TLSState Current state of the DTLS transport */
    private TLSState $state = TLSState::NEW;

    /** @var LoggerInterface|null Optional logger for debugging */
    private ?LoggerInterface $logger = null;

    /** @var RTCTransportStats Transport statistics collector */
    private RTCTransportStats $reportTransport;

    /** @var RtpRouter Routes RTP/RTCP packets to appropriate handlers */
    private RtpRouter $rtpRouter;

    /** @var HeaderExtensionsMap Manages RTP header extensions */
    private HeaderExtensionsMap $headerExtensionsMap;

    /** @var RTCSctpTransportInterface|null SCTP transport for data channels */
    private ?RTCSctpTransportInterface $sctpReceiver = null;

    /** @var Session Inbound SRTP session for decrypting incoming media */
    private Session $inboundSrtp;

    /** @var Session Outbound SRTP session for encrypting outgoing media */
    private Session $outboundSrtp;

    /** @var TLS The underlying TLS/DTLS implementation */
    private TLS $tls;

    /** @var DtlsRole The role (client/server) in the DTLS handshake */
    private DtlsRole $role = DtlsRole::Auto;

    /**
     * RTCDtlsTransport constructor.
     *
     * @param RTCIceTransportInterface $transport The underlying ICE transport
     * @param RTCCertificate $certificate The certificate to use for DTLS
     * @throws OpenSSLException|SrtpException If initialization fails
     */
    public function __construct(private readonly RTCIceTransportInterface $transport, private readonly RTCCertificate $certificate)
    {
        $this->tls = TLS::create($this->certificate);
        $this->reportTransport = new RTCTransportStats("transport_" . spl_object_id($this));
        $this->rtpRouter = new RtpRouter();
        $this->headerExtensionsMap = new HeaderExtensionsMap();
        $this->forwardEvents2Methods($transport, ['close' => 'handleDisconnectingError', 'error' => 'handleDisconnectingError']);
    }

    /**
     * Encrypts and sends application data over the DTLS connection.
     *
     * @param string $data The plaintext data to send
     * @throws SysCallException If a system call fails
     * @throws OpenSSLException If OpenSSL operations fail
     * @throws WantReadException If more data needs to be read
     * @throws WantX509LookupException If certificate lookup is needed
     * @throws TLSException If TLS is not in the correct state
     * @throws WantWriteException If more data needs to be written
     * @throws ZeroReturnException If the connection was closed
     * @throws SSLException For general SSL errors
     */
    public function sendData(string $data): void
    {
        $encryptedData = $this->tls->encrypt($data);
        if (strlen($encryptedData) > 0) {
            $this->send($encryptedData);
        }
    }

    /**
     * Encrypts and sends RTP media data.
     *
     * @param string $data The RTP packet to send
     * @throws SrtpException|DTLSException If encryption fails or connection isn't established
     */
    public function sendRtp(string $data): void
    {
        if ($this->state !== TLSState::CONNECTED) {
            throw new DTLSException("Unable to send encrypted RTP: No connection established.");
        }

        $encryptedRtp = $this->inboundSrtp->protect($data);
        $this->send($encryptedRtp);
    }

    /**
     * Encrypts and sends RTCP control data.
     *
     * @param string $data The RTCP packet to send
     * @throws DTLSException|SrtpException If encryption fails or connection isn't established
     */
    public function sendRtcp(string $data): void
    {
        if ($this->state !== TLSState::CONNECTED) {
            throw new DTLSException("Unable to send encrypted RTCP: No connection established.");
        }

        $encryptedRtcp = $this->inboundSrtp->protectRtcp($data);
        $this->send($encryptedRtcp);
    }

    /**
     * Gets the underlying TLS implementation.
     *
     * @return TLS The TLS instance
     */
    public function getTls(): TLS
    {
        return $this->tls;
    }

    /**
     * Sets the inbound SRTP session.
     *
     * @param Session $inboundSrtp The SRTP session for decrypting incoming media
     */
    public function setInboundSrtp(Session $inboundSrtp): void
    {
        $this->inboundSrtp = $inboundSrtp;
    }

    /**
     * Sets the outbound SRTP session.
     *
     * @param Session $outboundSrtp The SRTP session for encrypting outgoing media
     */
    public function setOutboundSrtp(Session $outboundSrtp): void
    {
        $this->outboundSrtp = $outboundSrtp;
    }

    /**
     * Gets the local DTLS parameters including certificate fingerprints.
     *
     * @return RTCDtlsParameters The local DTLS parameters
     * @throws OpenSSLException
     */
    public function getLocalParameters(): RTCDtlsParameters
    {
        return new RTCDtlsParameters($this->certificate->getFingerprints());
    }

    /**
     * Sets the DTLS role (client/server/auto).
     *
     * @param DtlsRole $getRole The role to set
     */
    public function setRole(DtlsRole $getRole): void
    {
        $this->role = $getRole;
    }

    /**
     * Gets the current DTLS role.
     *
     * @return DtlsRole The current role
     */
    public function getRole(): DtlsRole
    {
        return $this->role;
    }

    /**
     * Sends any pending data from the BIO buffer.
     */
    private function sendBioData(): void
    {
        $data = $this->tls->getBio()->read();
        if ($data && strlen($data) > 0) {
            $this->send($data);
        }
    }

    /**
     * Sends raw data through the underlying transport.
     *
     * @param string $data The data to send
     */
    public function send(string $data): void
    {
        $this->transport->send($data);
        $this->reportTransport->handleSent($data);
    }

    /**
     * Gets the transport statistics collector.
     *
     * @return RTCTransportStats The transport statistics
     */
    public function getReportTransport(): RTCTransportStats
    {
        return $this->reportTransport;
    }

    /**
     * Removes an SCTP receiver (placeholder implementation).
     *
     * @param RTCSctpTransport $param The SCTP transport to remove
     */
    public function removeSctpReceiver(RTCSctpTransport $param): void
    {
        // TODO: Implement proper removal logic
    }

    /**
     * Handles incoming data from the transport.
     *
     * @param string $data The received data
     * @throws SysCallException|DTLSException If a system call fails
     */
    private function onReceivedData(string $data): void
    {
        $this->reportTransport->handleReceived($data);

        $firstByte = ord($data[0]);
        if ($firstByte > 19 && $firstByte < 64) {
            $this->handleSctpData($data);
        } elseif ($firstByte > 127 && $firstByte < 192) {
            $this->handleSrtpData($data);
        }
    }

    /**
     * Starts the DTLS transport and begins the handshake process.
     *
     * @param RTCDtlsFingerprint[] $certificates Array of peer certificate fingerprints to validate
     * @throws DTLSException If handshake or setup fails
     * @throws OpenSSLException If OpenSSL operations fail
     * @throws TLSException If TLS is not in the correct state
     */
    public function start(array $certificates): void
    {
        $this->setState(TLSState::CONNECTING);

        // Start handshaking
        try {
            await($this->tls->startHandshaking($this->transport, $this->getHandShakeState()));
        } catch (Throwable $e) {
            $this->setFailedState("DTLS: " . $e->getMessage());
            return;
        }

        // Check peer certificate fingerprint
        if (!$this->tls->validatePeerCertificate($certificates)) {
            $this->setFailedState("DTLS: Fingerprint mismatch!");
            return;
        }

        // Setup inbound and outbound srtp
        try {
            $this->setupSrtp();
        } catch (SrtpExceptionInterface $e) {
            $this->setFailedState("DTLS: Failed setup srtp. " . $e->getMessage());
            return;
        }

        // Register onReceivedData method
        $this->forwardEvents2Methods($this->transport, ['data' => 'onReceivedData']);

        // Set success log and state
        $this->logger?->debug("DTLS: Successful handshake");
        $this->setState(TLSState::CONNECTED);
    }

    /**
     * Stops the DTLS transport and cleans up resources.
     *
     * @return PromiseInterface A promise that resolves when shutdown is complete
     */
    public function stop(): PromiseInterface
    {
        return async(function () {
            if (in_array($this->state, [TLSState::CONNECTING, TLSState::CONNECTED])) {
                try {
                    $this->tls->shutdown(); // Attempt to shut down, regardless of success or failure.
                    $this->sendBioData();
                } catch (\Throwable) {
                    // TODO: it should try 3 times before giving up
                }
                $this->setState(TLSState::CLOSED);
                $this->logger?->debug("DTLS: DTLS shutdown process has been successfully completed. All secure connections have been terminated.");
            }
        })();
    }

    /**
     * Gets the underlying ICE transport.
     *
     * @return RTCIceTransportInterface The ICE transport
     */
    public function getIceTransport(): RTCIceTransportInterface
    {
        return $this->transport;
    }

    /**
     * Gets the certificate used by this transport.
     *
     * @return RTCCertificate The certificate
     */
    public function getCertificate(): RTCCertificate
    {
        return $this->certificate;
    }

    /**
     * Gets the peer certificate fingerprints.
     *
     * @return array<RTCDtlsFingerprint> Array of certificate fingerprints
     * @throws OpenSSLException If fingerprint retrieval fails
     */
    public function getPeerCertificates(): array
    {
        return $this->certificate->getFingerprints();
    }

    /**
     * Gets the current transport state.
     *
     * @return TLSState The current state
     */
    public function getState(): TLSState
    {
        return $this->state;
    }

    /**
     * Gets the logger instance.
     *
     * @return LoggerInterface|null The logger or null if not set
     */
    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Sets the logger instance.
     *
     * @param LoggerInterface|null $logger The logger to set
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
        $this->tls->setLogger($logger);
    }

    /**
     * Sets the SCTP transport for data channels.
     *
     * @param RTCSctpTransportInterface|null $sctpReceiver The SCTP transport to set
     */
    public function setSctpReceiver(?RTCSctpTransportInterface $sctpReceiver = null): void
    {
        $this->sctpReceiver = $sctpReceiver;
    }

    /**
     * Sets up SRTP sessions for media encryption.
     *
     * @throws TLSException If TLS is not in the correct state
     * @throws SrtpException If SRTP setup fails
     * @throws DTLSException If connection isn't established
     * @throws OpenSSLException If OpenSSL operations fail
     * @throws SrtpValidateException If SRTP validation fails
     */
    public function setupSrtp(): void
    {
        $srtp = new Srtp();

        $selectedProfile = $srtp->getProfile($this->tls->getSelectedSrtpProfile());
        $srtpKeyMaterial = $this->tls->exportKeyingMaterial($selectedProfile["keyLength"], $selectedProfile["saltLent"]);

        $isServer = $this->transport->getRole() === IceRole::Controlling;

        $this->inboundSrtp = $srtp->getInbound($srtpKeyMaterial, intval($isServer));
        $this->outboundSrtp = $srtp->getOutbound($srtpKeyMaterial, intval(!$isServer));
    }

    /**
     * Sets the transport state and emits statechange event if changed.
     *
     * @param TLSState $state The new state
     */
    public function setState(TLSState $state): void
    {
        if ($state !== $this->state) {
            $this->logger?->debug(sprintf("State changed from %s to %s", $this->state->name, $state->name));
            $this->state = $state;
            $this->emit("statechange");
        }
    }

    /**
     * Sets the transport to failed state with the given reason.
     *
     * @param string $reason The failure reason
     * @param Throwable|null $e Optional exception that caused the failure
     */
    private function setFailedState(string $reason, ?Throwable $e = null): void
    {
        $this->logger?->error("DTLS: Failed handshaking or srtp setup.", ["reason" => $reason]);
        $this->setState(TLSState::FAILED);
    }

    /**
     * Handles transport disconnection or errors.
     *
     * @throws DTLSException Always throws to indicate connection loss
     */
    private function handleDisconnectingError()
    {
        $this->setState(TLSState::CLOSED);
        $this->logger?->alert("DTLS: Connection lost");
        $this->sctpReceiver?->onErrorOrClosed();

        throw new DTLSException("DTLS: Connection lost");
    }

    /**
     * Handles incoming SCTP data channel messages.
     *
     * @param string $data The received SCTP data
     * @throws SysCallException If a system call fails
     */
    private function handleSctpData(string $data): void
    {
        try {
            $decryptedData = $this->tls->decrypt($data);
        } catch (ZeroReturnException) {
            $this->stop();
            return;
        } catch (TLSException|OpenSSLException) {
            return;
        }
        $this->sendBioData();

        if (strlen($decryptedData) > 0) {
            $this->sctpReceiver->onReceived($decryptedData);
        } else {
            $this->logger->debug("DTLS: failed decrypted data");
        }
    }

    /**
     * Handles incoming SRTP media packets.
     *
     * @param string $data The received SRTP data
     * @throws DTLSException If RTP processing fails
     */
    private function handleSrtpData(string $data): void
    {
        $arrivalTimeMs = NetworkTimeProtocol::currentMs();
        try {
            if (RtpUtility::isRtcp($data)) {
                $decryptedRtcp = $this->outboundSrtp->unprotectRtcp($data);
                $this->handleRtcpData($decryptedRtcp);
            } else {
                $decryptedRtp = $this->outboundSrtp->unprotect($data);
                $this->handleRtpData($decryptedRtp, $arrivalTimeMs);
            }
        } catch (SrtpExceptionInterface $e) {
            $this->logger?->debug(sprintf("DTLS: SRTP decryption failed: %s", $e->getMessage()));
        }
    }

    /**
     * Handles incoming RTCP control packets.
     *
     * @param string $data The RTCP packet data
     * @throws DTLSException If connection isn't established
     * @throws SrtpException If decryption fails
     */
    public function handleRtcpData(string $data): void
    {
        try {
            $packets = RtcpPacket::decode($data);
        } catch (RtcpExceptionInterface $e) {
            $this->logger?->debug(sprintf("DTLS: RTCP parsing failed: %s", $e->getMessage()));
            return;
        }

        foreach ($packets as $packet) {
            // Route RTCP packet
            foreach ($this->rtpRouter->routeRtcp($packet) as $recipient) {
                $recipient->handleRtcpPacket($packet);
            }
        }
    }

    /**
     * Handles incoming RTP media packets.
     *
     * @param string $data The RTP packet data
     * @param int $arrivalTimeMs The packet arrival time in milliseconds
     */
    public function handleRtpData(string $data, int $arrivalTimeMs): void
    {
        try {
            $packet = RtpPacket::decode($data, $this->headerExtensionsMap);
        } catch (RtpExceptionInterface $e) {
            $this->logger?->debug(sprintf("DTLS: RTP parsing failed: %s", $e->getMessage()));
            return;
        }

        // Route RTP packet
        $receiver = $this->rtpRouter->routeRtp($packet);
        $receiver?->handleRtpPacket($packet, $arrivalTimeMs);
    }

    /**
     * Gets the current transport statistics.
     *
     * @return RTCStatsReport The statistics report
     */
    public function getStats(): RTCStatsReport
    {
        $this->reportTransport->dateTime = new \DateTimeImmutable();

        $report = new RTCStatsReport();
        $report->add($this->reportTransport);

        return $report;
    }

    /**
     * Registers an RTP receiver with the transport.
     *
     * @param RtpReceiverInterface $receiver The RTP receiver to register
     * @param RTCRtpReceiveParameters $parameters The receiver parameters
     */
    public function setRtpReceiver(RtpReceiverInterface $receiver, RTCRtpReceiveParameters $parameters): void
    {
        $ssrcs = [];
        foreach ($parameters->encodings as $encoding) {
            $ssrcs[] = $encoding->ssrc;
        }

        $this->headerExtensionsMap->configure($parameters);
        $this->rtpRouter->setReceiver(
            $receiver,
            $ssrcs,
            array_map(fn($codec) => $codec->payloadType, $parameters->codecs),
            $parameters->muxId
        );
    }

    /**
     * Removes an RTP receiver from the transport.
     *
     * @param RtpReceiverInterface $receiver The receiver to remove
     */
    public function removeRtpReceiver(RtpReceiverInterface $receiver): void
    {
        $this->rtpRouter->removeReceiver($receiver);
    }

    /**
     * Registers an RTP sender with the transport.
     *
     * @param RtpSenderInterface $sender The RTP sender to register
     * @param RTCRtpSendParameters $parameters The sender parameters
     */
    public function setRtpSender(RtpSenderInterface $sender, RTCRtpSendParameters $parameters): void
    {
        $this->headerExtensionsMap->configure($parameters);
        $this->rtpRouter->setSender($sender, $sender->getSsrc());
    }

    /**
     * Removes an RTP sender from the transport.
     *
     * @param RtpSenderInterface $sender The sender to remove
     */
    public function removeRtpSender(RtpSenderInterface $sender): void
    {
        $this->rtpRouter->removeSender($sender);
    }

    /**
     * Determines the handshake state based on the current role.
     *
     * @return SSLHandshakeState The appropriate handshake state
     */
    private function getHandShakeState(): SSLHandshakeState
    {
        if ($this->role === DtlsRole::Auto) {
            $this->role = $this->transport->getRole() === IceRole::Controlling ? DtlsRole::Server : DtlsRole::Client;
        }

        return $this->role === DtlsRole::Server ? SSLHandshakeState::Accept : SSLHandshakeState::Connect;
    }
}
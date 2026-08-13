<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\DTLS\TLS;

use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use Webrtc\DTLS\Enum\SSLHandshakeState;
use Webrtc\DTLS\Exception\TLSException;
use Webrtc\DTLS\RTCCertificate;
use Webrtc\DTLS\Srtp;
use Webrtc\ICE\RTCIceTransportInterface;
use Webrtc\SDP\DtlsParameter\RTCDtlsFingerprint;
use Webrtc\Srtp\Exception\SrtpException;
use Webrtc\SSL\Crypto\PrivateKeyInterface;
use Webrtc\SSL\Crypto\X509;
use Webrtc\SSL\Enum\BioMethod;
use Webrtc\SSL\Enum\ContextMethod;
use Webrtc\SSL\Enum\Verify;
use Webrtc\SSL\Exception\OpenSSLException;
use Webrtc\SSL\Exception\SSLException;
use Webrtc\SSL\Exception\SysCallException;
use Webrtc\SSL\Exception\WantReadException;
use Webrtc\SSL\Exception\WantWriteException;
use Webrtc\SSL\Exception\WantX509LookupException;
use Webrtc\SSL\Exception\ZeroReturnException;
use Webrtc\SSL\OpenSSL;
use Webrtc\SSL\SSL\BIO;
use Webrtc\SSL\SSL\BIOInterface;
use Webrtc\SSL\SSL\Context;
use Webrtc\SSL\SSL\SSL;
use Webrtc\SSL\SSL\SSLInterface;
use Webrtc\Stats\enum\TLSState;

/**
 * Class TLS
 *
 * Manages DTLS/TLS connections for WebRTC, providing encryption, decryption, and handshake functionality.
 * This class encapsulates the OpenSSL operations needed for secure communication over WebRTC data channels.
 *
 * Key responsibilities:
 * - Managing the TLS/DTLS connection state
 * - Performing encryption and decryption of data
 * - Handling the DTLS handshake process
 * - Validating peer certificates
 * - Managing SRTP (Secure Real-time Transport Protocol) profiles
 * - Exporting keying material for SRTP
 *
 * @package Webrtc\DTLS\TLS
 */
class TLS
{
    /** @var int Default buffer size for BIO operations */
    private const BUFFER_SIZE = 1500;

    /** @var array Supported cipher suites for the TLS connection */
    private const SUPPORTED_CIPHER_SUITES = [
        // AES-128-GCM-SHA256
        "ECDHE-ECDSA-AES128-GCM-SHA256",
        "ECDHE-RSA-AES128-GCM-SHA256",

        // AES-256-CBC-SHA
        "ECDHE-ECDSA-AES256-SHA",
        "ECDHE-RSA-AES256-SHA",

        // AES-256-GCM-SHA384
        "ECDHE-ECDSA-AES256-GCM-SHA384",
        "ECDHE-RSA-AES256-GCM-SHA384"
    ];

    /** @var SSLInterface The SSL connection instance */
    private SSLInterface $ssl;

    /** @var Context The SSL context */
    private Context $context;

    /** @var BIOInterface The BIO buffer for SSL operations */
    private BIOInterface $bio;

    /** @var TLSState Current state of the TLS connection */
    private TLSState $state = TLSState::NEW;

    /** @var LoggerInterface|null Optional logger for debugging */
    private ?LoggerInterface $logger = null;

    /**
     * TLS constructor.
     *
     * Initializes the TLS instance with the provided certificate and sets up the SSL context,
     * BIO buffer, and SSL connection.
     *
     * @param RTCCertificate $certificate The certificate to use for this TLS connection
     * @throws OpenSSLException If OpenSSL initialization fails
     * @throws SrtpException If SRTP profile setup fails
     */
    public function __construct(private readonly RTCCertificate $certificate)
    {
        OpenSSL::init();
        $this->context = $this->createContext();
        $this->bio = $this->createBIO();
        $this->ssl = $this->createSSL();
    }

    /**
     * Starts the DTLS handshake process.
     *
     * Creates a new Handshake instance and initiates the handshake process with the given transport.
     * The handshake can be either as a client (Connect) or server (Accept).
     *
     * @param RTCIceTransportInterface $transport The ICE transport to use for communication
     * @param SSLHandshakeState $state Whether to initiate as client or server
     * @return PromiseInterface A promise that resolves when the handshake completes
     */
    public function startHandshaking(RTCIceTransportInterface $transport, SSLHandshakeState $state): PromiseInterface
    {
        $handshake = new Handshake($this, $transport, $state);
        return $handshake->do();
    }

    /**
     * Encrypts data using the established TLS connection.
     *
     * @param string $data The plaintext data to encrypt
     * @return string The encrypted data
     * @throws OpenSSLException If an OpenSSL error occurs
     * @throws SysCallException If a system call fails
     * @throws TLSException If the TLS connection is not in the correct state
     * @throws WantReadException If more data needs to be read to complete the operation
     * @throws WantWriteException If more data needs to be written to complete the operation
     * @throws WantX509LookupException If certificate lookup is required
     * @throws ZeroReturnException If the connection was closed
     * @throws SSLException For general SSL errors
     */
    public function encrypt(string $data): string
    {
        $this->checkState();
        $this->ssl->write($data);
        return $this->bio->read();
    }

    /**
     * Decrypts data using the established TLS connection.
     *
     * @param string $data The encrypted data to decrypt
     * @return string The decrypted plaintext data
     * @throws OpenSSLException If an OpenSSL error occurs
     * @throws SSLException For general SSL errors
     * @throws SysCallException If a system call fails
     * @throws TLSException If the TLS connection is not in the correct state
     * @throws WantReadException If more data needs to be read to complete the operation
     * @throws WantWriteException If more data needs to be written to complete the operation
     * @throws WantX509LookupException If certificate lookup is required
     * @throws ZeroReturnException If the connection was closed
     */
    public function decrypt(string $data): string
    {
        $this->checkState();
        $this->bio->write($data);
        return $this->ssl->read(self::BUFFER_SIZE);
    }

    /**
     * Factory method to create a new TLS instance.
     *
     * @param RTCCertificate $certificate The certificate to use
     * @return static A new TLS instance
     * @throws OpenSSLException|SrtpException If initialization fails
     */
    public static function create(RTCCertificate $certificate): static
    {
        return new static($certificate);
    }

    /**
     * Validates the peer's certificate against provided fingerprints.
     *
     * @param RTCDtlsFingerprint[] $peerCerts Array of peer certificate fingerprints to validate against
     * @return bool True if the peer's certificate matches one of the provided fingerprints
     * @throws OpenSSLException If certificate validation fails
     * @throws TLSException If the TLS connection is not in the correct state
     */
    public function validatePeerCertificate(array $peerCerts): bool
    {
        $this->checkState();
        $peerCertDigits = $this->ssl->getPeerCertificateDigest();
        $found = false;
        foreach ($peerCerts as $cert) {
            if ($cert->isAlgorithm("sha-256") && strtolower($cert->value) === strtolower($peerCertDigits)) {
                $found = true;
                break;
            }
        }
        return $found;
    }

    /**
     * Exports keying material for SRTP.
     *
     * @param int $keyLength Length of the key material needed
     * @param int $saltLent Length of the salt needed
     * @return string The exported keying material
     * @throws OpenSSLException If key export fails
     * @throws TLSException If the TLS connection is not in the correct state
     */
    public function exportKeyingMaterial(int $keyLength, int $saltLent): string
    {
        $this->checkState();
        return $this->ssl->exportKeyingMaterial("EXTRACTOR-dtls_srtp", 2 * ($keyLength + $saltLent));
    }

    /**
     * Gets the selected SRTP protection profile.
     *
     * @return string The name of the selected SRTP profile
     * @throws TLSException If the TLS connection is not in the correct state
     */
    public function getSelectedSrtpProfile(): string
    {
        $this->checkState();
        return $this->ssl->getSelectedSrtpProfile();
    }

    /**
     * Creates the SSL context for the connection.
     *
     * @return Context The created SSL context
     * @throws SrtpException If SRTP profile setup fails
     */
    private function createContext(): Context
    {
        $ctx = new Context(ContextMethod::DTLS_METHOD);
        $ctx->setVerify(Verify::PEER->value | Verify::FAIL_IF_NO_PEER_CERT->value, fn(...$args) => true);
        $ctx->setCipherList(implode(":", self::SUPPORTED_CIPHER_SUITES));
        $ctx->setTlsextUseSrtp(implode(":", array_map(fn($profile) => $profile["sslProfile"], Srtp::getProfiles())));
        $this->setCertAndPrivateKey($ctx);
        $this->setLoggerInfo($ctx);

        return $ctx;
    }

    /**
     * Creates the SSL connection instance.
     *
     * @return SSL The created SSL connection
     */
    private function createSSL(): SSL
    {
        return new SSL($this->context, $this->bio);
    }

    /**
     * Creates the BIO buffer for SSL operations.
     *
     * @return BIOInterface The created BIO buffer
     */
    private function createBIO(): BIOInterface
    {
        $method = BioMethod::s_mem;
        $bio = new BIO($method);
        $bio->setBufferSize(self::BUFFER_SIZE);

        return $bio;
    }

    /**
     * Shuts down the SSL connection gracefully.
     *
     * @return bool True if shutdown was successful
     * @throws OpenSSLException If an OpenSSL error occurs
     * @throws SSLException For general SSL errors
     * @throws SysCallException If a system call fails
     * @throws WantReadException If more data needs to be read to complete the operation
     * @throws WantWriteException If more data needs to be written to complete the operation
     * @throws WantX509LookupException If certificate lookup is required
     * @throws ZeroReturnException If the connection was closed
     */
    public function shutdown(): bool
    {
        if ($this->ssl->shutdown()) {
            $this->setState(TLSState::CLOSED);
            return true;
        }

        return false;
    }

    /**
     * Gets the SSL connection instance.
     *
     * @return SSLInterface The SSL connection
     */
    public function getSsl(): SSLInterface
    {
        return $this->ssl;
    }

    /**
     * Gets the BIO buffer instance.
     *
     * @return BIOInterface The BIO buffer
     */
    public function getBio(): BIOInterface
    {
        return $this->bio;
    }

    /**
     * Gets the current TLS connection state.
     *
     * @return TLSState The current state
     */
    public function getState(): TLSState
    {
        return $this->state;
    }

    /**
     * Sets the TLS connection state.
     *
     * @param TLSState $state The new state to set
     */
    public function setState(TLSState $state): void
    {
        $this->state = $state;
    }

    /**
     * Verifies that the TLS connection is in the correct state for operations.
     *
     * @throws TLSException If the connection is not in the CONNECTED state
     */
    private function checkState(): void
    {
        if ($this->state !== TLSState::CONNECTED) {
            throw new TLSException("TLS is not connected");
        }
    }

    /**
     * Configures the certificate and private key in the SSL context.
     *
     * @param Context $ctx The SSL context to configure
     */
    private function setCertAndPrivateKey(Context $ctx): void
    {
        $certificate = $this->certificate->getCertificate();
        $privateKey = $this->certificate->getPrivateKey();

        if ($certificate instanceof X509 && $privateKey instanceof PrivateKeyInterface) {
            $ctx->useCertificate($certificate);
            $ctx->usePrivateKey($privateKey);
        } elseif (is_string($certificate) && is_string($privateKey)) {
            $ctx->useCertificateFile($certificate);
            $ctx->usePrivateKeyFile($privateKey);
        }

        $ctx->checkPrivateKey();
    }

    /**
     * Configures logging for the SSL context if a logger is set.
     *
     * @param Context $ctx The SSL context to configure
     */
    private function setLoggerInfo(Context $ctx): void
    {
        // Currently commented out - would enable logging if logger is set
        if ($this->logger) {
            $ctx->setInfoCallBack($this->logger);
        }
    }

    /**
     * Sets the logger for this TLS instance.
     *
     * @param LoggerInterface|null $logger The logger instance or null to disable logging
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}
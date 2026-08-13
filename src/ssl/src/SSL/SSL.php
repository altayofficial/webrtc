<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SSL\SSL;

use FFI;
use FFI\CData;
use Webrtc\Exception\RuntimeException;
use Webrtc\Mixin\SharedLibraryInterface;
use Webrtc\SSL\Crypto\X509;
use Webrtc\SSL\Exception\OpenSSLException;
use Webrtc\SSL\Exception\SSLException;
use Webrtc\SSL\Exception\SysCallException;
use Webrtc\SSL\Exception\WantReadException;
use Webrtc\SSL\Exception\WantWriteException;
use Webrtc\SSL\Exception\WantX509LookupException;
use Webrtc\SSL\Exception\ZeroReturnException;

/**
 * SSL/TLS Connection Handler
 *
 * Provides secure socket layer communication functionality including
 * - Connection establishment and teardown
 * - Data encryption/decryption
 * - Certificate verification
 * - DTLS timeout handling
 * - SRTP profile management
 *
 * Implements both SSLInterface and SharedLibraryInterface
 */
class SSL implements SSLInterface, SharedLibraryInterface
{
    /** @var CData The SSL connection structure */
    private CData $ssl;

    /** @var FFI Reference to OpenSSL library */
    private FFI $libssl;

    /** @var Context The SSL context */
    private Context $context;

    /**
     * Initializes SSL connection
     *
     * @param Context $context SSL context configuration
     * @param BIO $bio Basic I/O object for data transfer
     */
    public function __construct(Context $context, BIO $bio)
    {
        $this->initiateSharedLibrary();
        $this->ssl = $this->libssl->SSL_new($context->getContext());

        $this->libssl->SSL_ctrl($this->ssl, SSL_CTRL_MODE, SSL_MODE_AUTO_RETRY, null);
        $this->libssl->SSL_set_bio($this->ssl, $bio->getInput(), $bio->getOutput());
        $this->context = $context;
    }

    /**
     * Configures connection for server-side operation
     */
    public function setAcceptState(): void
    {
        $this->libssl->SSL_set_accept_state($this->ssl);
    }

    /**
     * Configures connection for client-side operation
     */
    public function setConnectState(): void
    {
        $this->libssl->SSL_set_connect_state($this->ssl);
    }

    /**
     * Performs SSL/TLS handshake
     *
     * @throws OpenSSLException For general SSL errors
     * @throws SSLException For protocol-level errors
     * @throws SysCallException For system call failures
     * @throws WantReadException When more data needs to be read
     * @throws WantWriteException When more data needs to be written
     * @throws WantX509LookupException When certificate lookup is needed
     * @throws ZeroReturnException When connection is closed
     */
    public function doHandshake(): void
    {
        $res = $this->libssl->SSL_do_handshake($this->ssl);
        $this->throwSslExceptionIfGotError($this->ssl, $res);
    }

    /**
     * Gets peer certificate fingerprint
     *
     * @return string SHA-256 digest of peer certificate
     * @throws OpenSSLException If certificate retrieval fails
     */
    public function getPeerCertificateDigest(): string
    {
        $peerCertX509 = $this->libssl->SSL_get1_peer_certificate($this->ssl);
        if (!$peerCertX509) {
            throw new RuntimeException("Failed to get peer certificate");
        }

        $x509 = new X509($peerCertX509);
        return $x509->getDigits("sha256");
    }

    /**
     * Gets negotiated SRTP profile
     *
     * @return string Name of selected SRTP profile or empty string if none
     */
    public function getSelectedSrtpProfile(): string
    {
        $profile = $this->libssl->SSL_get_selected_srtp_profile($this->ssl);
        if (!$profile) {
            return "";
        }
        return FFI::string($profile->name);
    }

    /**
     * Exports key material for application use
     *
     * @param string $label Disambiguating label per RFC 5705
     * @param int $keyLength Length of key material in bytes
     * @param string|null $context Optional association context
     * @return string Exported key material
     * @throws OpenSSLException If export fails
     */
    public function exportKeyingMaterial(string $label, int $keyLength, ?string $context = null): string
    {
        $outputBuffer = $this->libssl->new("unsigned char[$keyLength]");

        $contextBuf = null;
        $contextLen = 0;
        $useContext = 0;

        if ($context !== null) {
            $contextBuf = $this->libssl->new("unsigned char[" . strlen($context) . "]");
            FFI::memcpy($contextBuf, $context, strlen($context));
            $contextLen = strlen($context);
            $useContext = 1;
        }

        $success = $this->libssl->SSL_export_keying_material(
            $this->ssl,
            $outputBuffer,
            $keyLength,
            $label,
            strlen($label),
            $contextBuf,
            $contextLen,
            $useContext
        );

        if ($success != 1) {
            throw new OpenSSLException("SSL export keying material failed");
        }

        return FFI::string($outputBuffer, $keyLength);
    }

    /**
     * Initiates SSL connection shutdown
     *
     * @return bool True if shutdown completed
     * @throws OpenSSLException For general SSL errors
     * @throws SSLException For protocol-level errors
     * @throws SysCallException For system call failures
     * @throws WantReadException When more data needs to be read
     * @throws WantWriteException When more data needs to be written
     * @throws WantX509LookupException When certificate lookup is needed
     * @throws ZeroReturnException When connection is closed
     */
    public function shutdown(): bool
    {
        $result = $this->libssl->SSL_shutdown($this->ssl);
        if ($result < 0) {
            $this->throwSslExceptionIfGotError($this->ssl, $result);
        } else {
            return true;
        }

        return false;
    }

    /**
     * Gets DTLS timeout value
     *
     * @return float|null Timeout in seconds or null if no timeout active
     */
    public function dtlsV1GetTimeout(): ?float
    {
        $arg = $this->libssl->new("timeval");
        $result = $this->libssl->SSL_ctrl($this->ssl, DTLS_CTRL_GET_TIMEOUT, 0, FFI::addr($arg));
        if ($result) {
            return $arg->tv_sec + ($arg->tv_usec / 1000000);
        }
        return null;
    }

    /**
     * Handles DTLS timeout
     *
     * @return bool True if timeout was pending
     * @throws OpenSSLException For general SSL errors
     * @throws SSLException For protocol-level errors
     * @throws SysCallException For system call failures
     * @throws WantReadException When more data needs to be read
     * @throws WantWriteException When more data needs to be written
     * @throws WantX509LookupException When certificate lookup is needed
     * @throws ZeroReturnException When connection is closed
     */
    public function dtlsV1HandleTimeout(): bool
    {
        $result = $this->libssl->SSL_ctrl($this->ssl, DTLS_CTRL_HANDLE_TIMEOUT, 0, null);
        if ($result < 0) {
            $this->throwSslExceptionIfGotError($this->ssl, $result);
        } else {
            return boolval($result);
        }
        return false;
    }

    /**
     * Reads data from SSL connection
     *
     * @param int $bufsiz Maximum bytes to read
     * @param int|null $flags Optional STREAM_PEEK flag
     * @return string Data read
     * @throws OpenSSLException For general SSL errors
     * @throws SSLException For protocol-level errors
     * @throws SysCallException For system call failures
     * @throws WantReadException When more data needs to be read
     * @throws WantWriteException When more data needs to be written
     * @throws WantX509LookupException When certificate lookup is needed
     * @throws ZeroReturnException When connection is closed
     */
    public function read(int $bufsiz, ?int $flags = null): string
    {
        $buf = $this->libssl->new("char[$bufsiz]");

        if ($flags !== null && ($flags & STREAM_PEEK)) {
            $result = $this->libssl->SSL_peek($this->ssl, $buf, $bufsiz);
        } else {
            $result = $this->libssl->SSL_read($this->ssl, $buf, $bufsiz);
        }

        $this->throwSslExceptionIfGotError($this->ssl, $result);

        return FFI::string($buf, $result);
    }

    /**
     * Writes data to SSL connection
     *
     * @param string $buf Data to write
     * @param int $flags Unused (for API compatibility)
     * @throws OpenSSLException For general SSL errors
     * @throws SSLException For protocol-level errors
     * @throws SysCallException For system call failures
     * @throws WantReadException When more data needs to be read
     * @throws WantWriteException When more data needs to be written
     * @throws WantX509LookupException When certificate lookup is needed
     * @throws ZeroReturnException When connection is closed
     */
    public function write(string $buf, int $flags = 0): void
    {
        $data = $this->libssl->new("unsigned char[" . strlen($buf) . "]");
        FFI::memcpy($data, $buf, strlen($buf));
        $result = $this->libssl->SSL_write($this->ssl, $data, strlen($buf));
        $this->throwSslExceptionIfGotError($this->ssl, $result);
    }

    /**
     * Gets certificate verification result
     *
     * @return int Verification result code
     */
    public function getVerify(): int
    {
        return $this->libssl->SSL_get_verify_result($this->ssl);
    }

    /**
     * Gets SSL connection state
     *
     * @return mixed SSL state information
     */
    public function getState(): mixed
    {
        return $this->libssl->SSL_get_state($this->ssl);
    }

    /**
     * Gets a list of supported ciphers
     *
     * @return array List of cipher strings
     */
    public function getCipherList(): array
    {
        $ciphers = [];
        $i = 0;
        while (true) {
            $result = $this->libssl->SSL_get_cipher_list($this->ssl, $i);
            if ($result === null) {
                break;
            }
            $ciphers[] = $result;
            $i++;
        }

        return $ciphers;
    }

    /**
     * Gets SSL context
     *
     * @return Context The SSL context
     */
    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * Initializes shared library references
     */
    public function initiateSharedLibrary(): void
    {
        global $libssl;

        if ($libssl instanceof FFI) {
            $this->libssl = $libssl;
        }
    }

    /**
     * Frees SSL resources
     */
    public function __destruct()
    {
        $this->libssl->SSL_free($this->ssl);
    }

    /**
     * Handles SSL errors and throws appropriate exceptions
     *
     * @param CData $ssl SSL connection
     * @param int $result Operation result code
     * @throws WantWriteException When write needed
     * @throws ZeroReturnException When connection closed
     * @throws WantX509LookupException When cert lookup needed
     * @throws SysCallException For system errors
     * @throws OpenSSLException For general SSL errors
     * @throws WantReadException When read needed
     * @throws SSLException For protocol errors
     */
    private function throwSslExceptionIfGotError(CData $ssl, int $result): void
    {
        $sslErrorCode = $this->libssl->SSL_get_error($ssl, $result);

        if ($sslErrorCode == 0) {
            return;
        }

        throw match ($sslErrorCode) {
            1 => new SSLException("SSL error occurred"),
            2 => new WantReadException("SSL wants to read data"),
            3 => new WantWriteException("SSL wants to write data"),
            4 => new WantX509LookupException("SSL wants to 509 lookup"),
            5 => new SysCallException("Unexpected EOF"),
            6 => new ZeroReturnException("Zero returned"),
            default => new OpenSSLException("SSL error occurred"),
        };
    }
}
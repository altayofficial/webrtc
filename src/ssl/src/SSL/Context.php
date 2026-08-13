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
use FFI\Exception;
use Psr\Log\LoggerInterface;
use Webrtc\Exception\RuntimeException;
use Webrtc\Mixin\SharedLibraryInterface;
use Webrtc\SSL\Crypto\PrivateKeyInterface;
use Webrtc\SSL\Crypto\X509;
use Webrtc\SSL\Enum\ContextMethod;
use Webrtc\SSL\Enum\PrivateKeyType;

/**
 * SSL/TLS Context Manager
 *
 * Provides comprehensive configuration and management of SSL/TLS contexts,
 * including certificate handling, protocol version control, cipher suite
 * configuration, and verification settings. Implements SharedLibraryInterface
 * for FFI library management.
 */
class Context implements SharedLibraryInterface
{
    /** @var int ASN.1 format constant */
    public const SSL_FILETYPE_ASN1 = 2;

    /** @var int PEM format constant */
    public const SSL_FILETYPE_PEM = 1;

    /** @var CData The SSL_CTX structure */
    private CData $context;

    /** @var array Mapping of context methods to OpenSSL functions and versions */
    private const METHODS = [
        'SSLv23_METHOD' => ['TLS_method', null],
        'TLSv1_METHOD' => ['TLS_method', 'TLS1_VERSION'],
        'TLSv1_1_METHOD' => ['TLS_method', 'TLS1_1_VERSION'],
        'TLSv1_2_METHOD' => ['TLS_method', 'TLS1_2_VERSION'],
        'TLS_METHOD' => ['TLS_method', null],
        'TLS_SERVER_METHOD' => ['TLS_server_method', null],
        'TLS_CLIENT_METHOD' => ['TLS_client_method', null],
        'DTLS_METHOD' => ['DTLS_method', null],
        'DTLS_SERVER_METHOD' => ['DTLS_server_method', null],
        'DTLS_CLIENT_METHOD' => ['DTLS_client_method', null],
    ];

    /** @var FFI OpenSSL library reference */
    private FFI $libssl;

    /** @var FFI Crypto library reference */
    private FFI $libcrypto;

    /**
     * Initializes SSL/TLS context with specified method
     *
     * @param ContextMethod $method The SSL/TLS method to use
     * @throws RuntimeException If context creation fails
     */
    public function __construct(private readonly ContextMethod $method)
    {
        $this->initiateSharedLibrary();
        [$methodFunction, $version] = self::METHODS[$this->method->name];
        try {
            $method = $this->libssl->{$methodFunction}();
            if (!$method) {
                throw new RuntimeException("Couldn't get the OpenSSL method");
            }
            $this->context = $this->libssl->SSL_CTX_new($method);
            $this->setMode(SSL_MODE_ENABLE_PARTIAL_WRITE);
            if ($version) {
                $version = $this->libssl->{$version};
                $this->setMinProtoVersion($version);
                $this->setMaxProtoVersion($version);
            }
        } catch (Exception $e) {
            throw new RuntimeException("Couldn't get the OpenSSL method: " . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Sets SSL context operational mode
     *
     * @param int $mode The mode flag to set
     * @return int Result of mode setting operation
     * @throws RuntimeException If mode setting fails
     */
    public function setMode(int $mode): int
    {
        $res = $this->libssl->SSL_CTX_ctrl($this->context, SSL_CTRL_MODE, $mode, null);
        if ($res === 4294966301) {
            throw new RuntimeException("Failed to set Mode");
        }

        return $res;
    }

    /**
     * Sets SSL info callback for debugging/logging
     *
     * @param LoggerInterface|null $logger Optional logger for callback output
     */
    public function setInfoCallBack(?LoggerInterface $logger): void
    {
        $this->libssl->SSL_CTX_set_info_callback($this->context, function ($ssl, int $where, int $ret) use ($logger) {
            $w = $where & ~0x0FFF;
            $str = "undefined";

            if ($w & 0x1000) {
                $str = "SSL_connect";
            } elseif ($w & 0x2000) {
                $str = "SSL_accept";
            }

            if ($where & 0x01) {
                echo (sprintf("%s: %s\n", $str, $this->libssl->SSL_state_string_long($ssl)));
            } elseif ($where & 0x4000) {
                $alertType = ($where & 0x04) ? "read" : "write";
                echo (sprintf("SSL3 alert %s: %s: %s\n",
                    $alertType,
                    $this->libssl->SSL_alert_type_string_long($ret),
                    $this->libssl->SSL_alert_desc_string_long($ret)
                ));
            } elseif ($where & 0x02) {
                if ($ret == 0) {
                    echo (sprintf("%s: failed in %s\n", $str, $this->libssl->SSL_state_string_long($ssl)));
                } elseif ($ret < 0) {
                    echo (sprintf("%s: error in %s\n", $str, $this->libssl->SSL_state_string_long($ssl)));
                }
            }
        });
    }

    /**
     * Sets minimum protocol version
     *
     * @param int $version Minimum protocol version constant
     * @throws RuntimeException If version setting fails
     */
    public function setMinProtoVersion(int $version): void
    {
        if (!$this->libssl->SSL_CTX_set_min_proto_version($this->context, $version)) {
            throw new RuntimeException("Failed to set min version");
        }
    }

    /**
     * Sets maximum protocol version
     *
     * @param int $version Maximum protocol version constant
     * @throws RuntimeException If version setting fails
     */
    public function setMaxProtoVersion(int $version): void
    {
        if (!$this->libssl->SSL_CTX_set_max_proto_version($this->context, $version)) {
            throw new RuntimeException("Failed to set max version");
        }
    }

    /**
     * Configures certificate verification
     *
     * @param int $mode Verification mode flags
     * @param callable $verify Custom verification callback
     */
    public function setVerify(int $mode, callable $verify): void
    {
        $this->libssl->SSL_CTX_set_verify($this->context, $mode, function (int $ok, CData $x509StoreContext) use ($verify) {
            return $verify();

            // TODO: verify CA if needed(at this moment we do not want to develop entire PHP OpenSSL library)
            // $x509 = $this->libssl->X509_STORE_CTX_get_current_cert($x509StoreContext);
            // rest of code...
            //return ture or false;
        });
    }

    /**
     * Loads ASN.1 encoded certificate
     *
     * @param array $asn1Cert DER-encoded certificate data
     * @throws RuntimeException If certificate loading fails
     */
    public function useCertificateASN1(array $asn1Cert): void
    {
        $length = count($asn1Cert);
        $der = $this->libssl->new("byte[$length]");
        foreach ($asn1Cert as $key => $value) {
            $der[$key] = $value;
        }
        $res = $this->libssl->SSL_CTX_use_certificate_ASN1($this->context, $length, $der);
        if ($res != 1) {
            throw new RuntimeException("Couldn't use the certificate");
        }
    }

    /**
     * Loads certificate from a file
     *
     * @param string $certFile Path to a certificate file
     * @param int $type File type (SSL_FILETYPE_PEM or SSL_FILETYPE_ASN1)
     * @throws RuntimeException If file loading fails
     */
    public function useCertificateFile(string $certFile, int $type = self::SSL_FILETYPE_PEM): void
    {
        $res = $this->libssl->SSL_CTX_use_certificate_file($this->context, $certFile, $type);
        if ($res <= 0) {
            throw new RuntimeException("Failed to load cert file or corrupted file");
        }
    }

    /**
     * Uses X509 certificate object
     *
     * @param X509 $x509 Prepared X509 certificate
     * @throws RuntimeException If certificate setting fails
     */
    public function useCertificate(X509 $x509): void
    {
        $res = $this->libssl->SSL_CTX_use_certificate($this->context, $x509->getX509());
        if ($res !== 1) {
            throw new RuntimeException("Failed to set SSL context certificate");
        }
    }

    /**
     * Uses a private key object
     *
     * @param PrivateKeyInterface $privateKey Prepared private key
     * @throws RuntimeException If key setting fails
     */
    public function usePrivateKey(PrivateKeyInterface $privateKey): void
    {
        $res = $this->libssl->SSL_CTX_use_PrivateKey($this->context, $privateKey->getPKey()->getPkey());
        if ($res != 1) {
            throw new RuntimeException("Failed to set SSL context private key");
        }
    }

    /**
     * Loads ASN.1 encoded private key
     *
     * @param array $asn1privateKey DER-encoded private key data
     * @param PrivateKeyType $type Key type (default: RSA)
     * @throws RuntimeException If key loading fails
     */
    public function usePrivateKeyASN1(array $asn1privateKey, PrivateKeyType $type = PrivateKeyType::RSA): void
    {
        $length = count($asn1privateKey);
        $der = $this->libssl->new("byte[$length]");
        foreach ($asn1privateKey as $key => $value) {
            $der[$key] = $value;
        }
        $res = $this->libssl->SSL_CTX_use_PrivateKey_ASN1($type->value, $this->context, $der, count($asn1privateKey));
        if ($res != 1) {
            throw new RuntimeException();
        }
    }

    /**
     * Loads private key from a file
     *
     * @param string $privateKey Path to a private key file
     * @param int $type File type (SSL_FILETYPE_PEM or SSL_FILETYPE_ASN1)
     * @throws RuntimeException If file loading fails
     */
    public function usePrivateKeyFile(string $privateKey, int $type = self::SSL_FILETYPE_PEM): void
    {
        $res = $this->libssl->SSL_CTX_use_PrivateKey_file($this->context, $privateKey, $type);
        if ($res <= 0) {
            throw new RuntimeException("Failed to load private key file or corrupted file");
        }
    }

    /**
     * Verifies private key matches certificate
     *
     * @throws RuntimeException If key verification fails
     */
    public function checkPrivateKey(): void
    {
        if (!$this->libssl->SSL_CTX_check_private_key($this->context)) {
            throw new RuntimeException("Private key does not match the certificate public key");
        }
    }

    /**
     * Configures allowed cipher suites
     *
     * @param string $cipherList Colon-separated cipher list
     * @throws RuntimeException If cipher configuration fails
     */
    public function setCipherList(string $cipherList): void
    {
        $res = $this->libssl->SSL_CTX_set_cipher_list($this->context, $cipherList);
        if ($res != 1) {
            throw new RuntimeException("Wrong cipher list!");
        }
    }

    /**
     * Configures SRTP profiles for DTLS
     *
     * @param string $profiles Colon-separated SRTP profiles
     * @throws RuntimeException If SRTP configuration fails
     */
    public function setTlsextUseSrtp(string $profiles): void
    {
        $res = $this->libssl->SSL_CTX_set_tlsext_use_srtp($this->context, $profiles);
        if ($res != 0) {
            throw new RuntimeException("Couldn't set tlsext!");
        }
    }

    /**
     * Gets the SSL_CTX structure
     *
     * @return CData The SSL context structure
     */
    public function getContext(): CData
    {
        return $this->context;
    }

    /**
     * Initializes shared library references
     */
    public function initiateSharedLibrary(): void
    {
        global $libssl, $libcrypto;

        if ($libssl instanceof FFI) {
            $this->libssl = $libssl;
        }

        if ($libcrypto instanceof FFI) {
            $this->libcrypto = $libcrypto;
        }
    }

    /**
     * Destructor - frees SSL context resources
     */
    public function __destruct()
    {
        $this->libssl->SSL_CTX_free($this->context);
    }
}
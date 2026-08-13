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

use DateInterval;
use DateInvalidOperationException;
use DateTimeImmutable;
use Webrtc\DTLS\Exception\RTCCertificateException;
use Webrtc\SDP\DtlsParameter\RTCDtlsFingerprint;
use Webrtc\SSL\Crypto\EC\EC;
use Webrtc\SSL\Crypto\PrivateKeyInterface;
use Webrtc\SSL\Crypto\X509;
use Webrtc\SSL\Enum\ECCurveName;
use Webrtc\SSL\Exception\OpenSSLException;
use Webrtc\SSL\OpenSSL;

/**
 * Class RTCCertificate
 *
 * Represents a certificate used by an RTCDtlsTransport for WebRTC communications.
 * This class handles certificate generation, management, and fingerprinting for DTLS connections.
 *
 * The certificate can be either:
 * - Generated automatically with EC secp256r1 private key (default behavior)
 * - Loaded from existing certificate and private key files
 *
 * @package Webrtc\DTLS
 */
class RTCCertificate
{
    /** @var string Default country for a certificate subject */
    private const COUNTRY = "US";

    /** @var string Default state for a certificate subject */
    private const STATE = "Virginia";

    /** @var string Default city for a certificate subject */
    private const CITY = "Fairfax";

    /** @var string Default organization for certificate subject */
    private const ORGANIZATION = "Quasar Stream";

    /** @var string Default common name for a certificate subject */
    private const ORGANIZATION_WEBSITE = "QuasarStream.com";

    /** @var PrivateKeyInterface|string The private key (either as object or file path) */
    private PrivateKeyInterface|string $privateKey;

    /** @var X509|string The certificate (either as X509 object or file path) */
    private X509|string $certificate;

    /** @var X509 The X509 certificate object */
    private X509 $x509;

    /**
     * RTCCertificate constructor.
     *
     * Initializes the certificate either from provided files or by generating a new one.
     *
     * @param string|null $privateKey Path to an existing private key file (PEM format)
     * @param string|null $certificate Path to an existing certificate file (PEM format)
     *
     * @throws RTCCertificateException If provided files don't exist or are invalid
     * @throws OpenSSLException If OpenSSL operations fail
     * @throws DateInvalidOperationException
     */
    public function __construct(?string $privateKey = null, ?string $certificate = null)
    {
        OpenSSL::init();

        if ($privateKey && $certificate) {
            if (!is_file($privateKey) || !is_file($certificate)) {
                throw new RTCCertificateException(sprintf(
                    "Either the private key file or the certificate file (or both) does not exist. Private key path: %s, Certificate path: %s",
                    $privateKey,
                    $certificate
                ));
            }
            $this->privateKey = $privateKey;
            $this->certificate = $certificate;
            $this->x509 = X509::loadFile($certificate);
        } else {
            $this->generate();
        }
    }

    /**
     * Gets the certificate expiration date.
     *
     * @return DateTimeImmutable The date when the certificate expires
     *
     * @throws OpenSSLException If certificate expiration date can’t be retrieved
     */
    public function expires(): DateTimeImmutable
    {
        return $this->x509->getExpires();
    }

    /**
     * Gets the certificate fingerprints.
     *
     * Returns an array of fingerprint objects containing the certificate's SHA-256 digest.
     * This is used for DTLS fingerprint verification during the WebRTC handshake.
     *
     * @return array<RTCDtlsFingerprint> Array of fingerprint objects
     *
     * @throws OpenSSLException If fingerprint calculation fails
     */
    public function getFingerprints(): array
    {
        return [new RTCDtlsFingerprint('sha-256', $this->x509->getDigits("sha256"))];
    }

    /**
     * Generates a new certificate and private key pair.
     *
     * Creates:
     * - A new EC private key using secp256r1 curve
     * - A corresponding X.509 certificate with default subject information
     *
     * @return void
     *
     * @throws OpenSSLException|DateInvalidOperationException If generation fails
     */
    private function generate(): void
    {
        $this->privateKey = $this->generatePrivateKey();
        $this->certificate = $this->createCertificate();
    }

    /**
     * Generates a new EC private key using secp256r1 curve.
     *
     * @return PrivateKeyInterface The generated private key
     *
     * @throws OpenSSLException If key generation fails
     */
    private function generatePrivateKey(): PrivateKeyInterface
    {
        $ecKey = new EC(ECCurveName::secp256r1);
        $ecKey->generate();

        return $ecKey;
    }

    /**
     * Creates a new X.509 certificate.
     *
     * The certificate will:
     * - Used the generated private key
     * - Be valid from 1 day ago to 30 days in the future
     * - Contain default subject information (organization, location, etc.)
     *
     * @return X509 The created certificate
     *
     * @throws OpenSSLException If certificate creation fails
     * @throws DateInvalidOperationException If date operations fail
     */
    private function createCertificate(): X509
    {
        $x509 = new X509();

        $x509->setSerialNumberDefualt();

        $now = new DateTimeImmutable();
        $x509->setDateNotBefore($now->sub(new DateInterval('P1D'))); // The certificate validity started from a day ago.
        $x509->setDateNotAfter($now->add(new DateInterval('P30D'))); // The certificate is valid until 30 days from now.

        $x509->setPublicKey($this->privateKey);

        $x509->setSubjectName();

        $x509->addEntry("C", self::COUNTRY);
        $x509->addEntry("ST", self::STATE);
        $x509->addEntry("L", self::CITY);
        $x509->addEntry("O", self::ORGANIZATION);
        $x509->addEntry("CN", self::ORGANIZATION_WEBSITE);

        $x509->setIssuerName();

        $x509->sign($this->privateKey);

        $this->x509 = $x509;

        return $x509;
    }

    /**
     * Gets the certificate.
     *
     * @return X509|string The certificate as X509 object or file path string
     */
    public function getCertificate(): X509|string
    {
        return $this->certificate;
    }

    /**
     * Gets the private key.
     *
     * @return PrivateKeyInterface|string The private key as object or file path string
     */
    public function getPrivateKey(): PrivateKeyInterface|string
    {
        return $this->privateKey;
    }
}
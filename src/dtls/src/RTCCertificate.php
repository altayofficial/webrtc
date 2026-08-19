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

use altay\dtls\Certificate;
use altay\dtls\Exception\DtlsException;
use Webrtc\DTLS\Exception\RTCCertificateException;
use Webrtc\SDP\DtlsParameter\RTCDtlsFingerprint;

/**
 * The local DTLS identity.
 *
 * A self signed ECDSA P-256 certificate, either generated on the spot or loaded from disk. The
 * only thing the peer ever checks is the SHA-256 fingerprint that travels in the SDP, so no
 * chain is built and none is expected back.
 */
class RTCCertificate
{
    private Certificate $certificate;

    /**
     * @param string|null $privateKey path to a PEM private key, or null to generate one
     * @param string|null $certificate path to a PEM certificate, or null to generate one
     * @throws RTCCertificateException if the pair cannot be loaded or generated
     */
    public function __construct(?string $privateKey = null, ?string $certificate = null)
    {
        try {
            if ($privateKey !== null && $certificate !== null) {
                $this->certificate = Certificate::fromPem(
                    self::read($certificate),
                    self::read($privateKey)
                );
            } else {
                $this->certificate = Certificate::generate();
            }
        } catch (DtlsException $e) {
            throw new RTCCertificateException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * The underlying identity, as the DTLS layer wants it.
     */
    public function getIdentity(): Certificate
    {
        return $this->certificate;
    }

    /**
     * The fingerprints advertised on the SDP a=fingerprint line.
     *
     * @return RTCDtlsFingerprint[]
     */
    public function getFingerprints(): array
    {
        return [new RTCDtlsFingerprint("sha-256", $this->certificate->fingerprint())];
    }

    public function getCertificate(): string
    {
        return $this->certificate->der();
    }

    private static function read(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RTCCertificateException("cannot read $path");
        }

        return $contents;
    }
}

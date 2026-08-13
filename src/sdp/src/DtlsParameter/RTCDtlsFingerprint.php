<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SDP\DtlsParameter;

use Webrtc\Mixin\DataClass;

/**
 * Represents a DTLS fingerprint.
 */
#[DataClass]
class RTCDtlsFingerprint
{
    /**
     * Initializes a new RTCDtlsFingerprint instance.
     *
     * @param string $algorithm The hash algorithm (e.g., "sha-256").
     * @param string $value The fingerprint value.
     */
    public function __construct(
        public string $algorithm,
        public string $value
    )
    {
    }

    public function isAlgorithm(string $algorithm): bool
    {
        return $this->algorithm === $algorithm;
    }
}
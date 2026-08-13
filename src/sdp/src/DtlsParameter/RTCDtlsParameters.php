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
use Webrtc\SDP\Enum\DtlsRole;

/**
 * Represents DTLS (Datagram Transport Layer Security) parameters.
 */
#[DataClass]
class RTCDtlsParameters
{
    /**
     * Initializes a new RTCDtlsParameters instance.
     *
     * @param RTCDtlsFingerprint[] $fingerprints List of DTLS fingerprints, one for each certificate.
     * @param DtlsRole $role The DTLS role, with a default of "auto".
     */
    public function __construct(
        public array    $fingerprints = [],
        public DtlsRole $role = DtlsRole::Auto
    )
    {
    }
}
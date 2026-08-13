<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SDP\SctpParameter;

/**
 * Provides information about the capabilities of the RTCSctpTransport.
 */
class RTCSctpCapabilities
{
    /**
     * The maximum size of data that the implementation can send or
     * 0 if the implementation can handle messages of any size.
     */
    public function __construct(public int $maxMessageSize = 0)
    {
    }
}

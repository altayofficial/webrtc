<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Srtp\Enum;
/**
 * SsrcType describes the type of SSRC.
 *
 * An SsrcType enumeration is used to indicate a type of SSRC.
 */
enum SsrcType: int
{
    /**
     * Indicates an undefined SSRC type.
     */
    case UNDEFINED = 0;
    /**
     * Indicates a specific SSRC value
     */
    case SPECIFIC = 1;
    /**
     *  Indicates any inbound SSRC value
     *  (i.e., a value that is used in the function srtp_unprotect())
     */
    case ANY_INBOUND = 2;
    /**
     * Indicates any outbound SSRC value
     * (i.e., a value that is used in the function srtp_protect())
     */
    case ANY_OUTBOUND = 3;
}

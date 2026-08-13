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
 * @brief identifies a particular SRTP profile
 *
 * An SrtpProfile enumeration is used to identify a particular SRTP
 * profile (that is, a set of algorithms and parameters).
 */
enum SrtpProfile: int
{
    case AES128_CM_SHA1_80 = 1;
    case AES128_CM_SHA1_32 = 2;
    case NULL_SHA1_80 = 5;
    case NULL_SHA1_32 = 6;
    case AEAD_AES_128_GCM = 7;
    case AEAD_AES_256_GCM = 8;
}

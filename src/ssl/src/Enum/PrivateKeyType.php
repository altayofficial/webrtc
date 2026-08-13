<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SSL\Enum;
enum PrivateKeyType: int
{
    case RSA = 0;
    case RSA_PSS = 1;
    case EC = 2;
    case ED25519 = 3;
    case ED448 = 4;
    case X25519 = 5;
    case X448 = 6;
    case SM2 = 7;
    case DH = 8;
    case X9_42_DH = 9;
    case DHX = 10;
    case DSA = 11;
}
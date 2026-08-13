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

enum ContextMethod: int
{
    case SSLv23_METHOD = 3;
    case TLSv1_METHOD = 4;
    case TLSv1_1_METHOD = 5;
    case TLSv1_2_METHOD = 6;
    case TLS_METHOD = 7;
    case TLS_SERVER_METHOD = 8;
    case TLS_CLIENT_METHOD = 9;
    case DTLS_METHOD = 10;
    case DTLS_SERVER_METHOD = 11;
    case DTLS_CLIENT_METHOD = 12;
}
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

enum BioMethod: int
{
    case s_mem = 0;
    case s_dgram_mem = 1;
    case s_secmem = 2;
    case s_socket = 3;
    case s_connect = 4;
    case s_accept = 5;
    case s_fd = 6;
    case s_log = 7;
    case s_bio = 8;
    case s_null = 9;
    case f_null = 10;
    case f_buffer = 11;
    case f_readbuffer = 12;
    case f_linebuffer = 13;
    case f_ntest = 14;
    case f_prefix = 15;
    case s_core = 16;
    case s_dgram_pair = 17;
    case s_datagram = 18;
}
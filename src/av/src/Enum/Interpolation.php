<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec\Enum;

enum Interpolation: int {
    case FAST_BILINEAR = 1;
    case BILINEAR = 2;
    case BICUBIC = 3;
    case X = 4;
    case POINT = 5;
    case AREA = 6;
    case BICUBLIN = 7;
    case GAUSS = 8;
    case SINC = 9;
    case LANCZOS = 10;
}

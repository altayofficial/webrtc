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

enum ColorRange: int {
    case UNSPECIFIED = 18;
    case MPEG = 19;
    case JPEG = 20;
    case NB = 21;
}
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
enum Colorspace: int {
    case ITU709 = 11;
    case FCC = 12;
    case ITU601 = 13;
    case ITU624 = 14;
    case SMPTE170M = 15;
    case SMPTE240M = 16;
    case DEFAULT = 17;
}
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
enum PictureType: int
{
    case NONE = 0; // Undefined
    case I = 1; // Intra
    case P = 2; // Predicted
    case B = 3; // Bi-dir predicted
    case S = 4; // S(GMC)-VOP MPEG-4
    case SI = 5; // Switching Intra
    case SP = 6; // Switching Predicted
    case BI = 7; // BI type
}
<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SDP\Enum;

enum H264Level: int
{
    case LEVEL1_B = -1;
    case LEVEL1 = 10;
    case LEVEL1_1 = 11;
    case LEVEL1_2 = 12;
    case LEVEL1_3 = 13;
    case LEVEL2 = 20;
    case LEVEL2_1 = 21;
    case LEVEL2_2 = 22;
    case LEVEL3 = 30;
    case LEVEL3_1 = 31;
    case LEVEL3_2 = 32;
    case LEVEL4 = 40;
    case LEVEL4_1 = 41;
    case LEVEL4_2 = 42;
    case LEVEL5 = 50;
    case LEVEL5_1 = 51;
    case LEVEL5_2 = 52;
}
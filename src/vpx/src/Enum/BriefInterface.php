<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\VPX\Enum;

/**
 * @deprecated
 */
enum BriefInterface: string
{
    case VP8Encoder = "vpx_codec_vp8_cx";
    case VP8Decoder = "vpx_codec_vp8_dx";
}
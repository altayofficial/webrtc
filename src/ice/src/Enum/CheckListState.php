<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\ICE\Enum;

/**
 * Candidate pair state.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8445#section-6.1.2.6
 */
enum CheckListState: int
{
    case ICE_COMPLETED = 0;
    case ICE_FAILED = 1;
}

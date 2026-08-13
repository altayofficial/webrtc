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
 * Candidate types and their Type Preferences value
 *
 * @see https://datatracker.ietf.org/doc/html/rfc8445#section-5.1.2.2
 */
enum CandidateType: int
{
    case host = 126;
    case prflx = 110;
    case srflx = 100;
    case relay = 0;
}

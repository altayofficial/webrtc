<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\STUN;

use Webrtc\STUN\Trait\CandidateSetterGetter;

/**
 * Interface for a base protocol in WebRTC.
 */
abstract class BaseProtocol implements BaseProtocolInterface
{
    use CandidateSetterGetter;
}
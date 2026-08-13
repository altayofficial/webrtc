<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\ICE;

use Webrtc\ICE\Enum\IceGatheringState;

interface RTCIceGathererInterface
{
    public function getIceConnection(): RTCIceConnectionInterface;
    public function getState(): IceGatheringState;

    /** @return list<RTCIceCandidate> */
    public function getLocalCandidates(): array;
    public function getLocalParameters(): RTCIceParameters;
}
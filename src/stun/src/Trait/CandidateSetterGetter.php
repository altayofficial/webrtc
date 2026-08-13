<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\STUN\Trait;

use Webrtc\ICE\RTCIceCandidate;

/**
 * Trait CandidateSetterGetter
 *
 * Provides getter and setter methods for RTCIceCandidate object
 */
trait CandidateSetterGetter
{
    /** @var RTCIceCandidate|null The ICE candidate object */
    private ?RTCIceCandidate $candidate = null;

    /**
     * Get the current ICE candidate
     *
     * @return RTCIceCandidate|null The current ICE candidate or null if is not set
     */
    public function getCandidate(): ?RTCIceCandidate
    {
        return $this->candidate;
    }

    /**
     * Set the ICE candidate
     *
     * @param RTCIceCandidate $candidate The ICE candidate to set
     * @return void
     */
    public function setCandidate(RTCIceCandidate $candidate): void
    {
        $this->candidate = $candidate;
    }
}
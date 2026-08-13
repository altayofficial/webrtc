<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTPParameter;

use DateTimeImmutable;
use Webrtc\Mixin\DataClass;

/**
 * information about a synchronization source (SSRC).
 */
#[DataClass]
class RTCRtpSynchronizationSource
{
    /**
     * @param DateTimeImmutable $timestamp The timestamp associated with this source.
     * @param int $source The SSRC identifier associated with this source.
     */
    public function __construct(
        public readonly DateTimeImmutable $timestamp,
        public readonly int       $source
    )
    {
    }
}

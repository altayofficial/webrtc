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

use DateTime;
use Webrtc\Mixin\DataClass;

#[DataClass]
class RTCRtpContributingSource
{
    /**
     * @param DateTime $timestamp The timestamp associated with this source.
     * @param int $source The CSRC identifier associated with this source.
     */
    public function __construct(
        public readonly DateTime $timestamp,
        public readonly int       $source
    )
    {
    }
}

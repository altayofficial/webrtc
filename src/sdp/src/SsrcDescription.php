<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SDP;

use Webrtc\Mixin\DataClass;

/**
 * Represents an SSRC (Synchronization Source) description.
 */
#[DataClass]
class SsrcDescription
{
    /**
     * Initializes a new SsrcDescription instance.
     *
     * @param int $ssrc The SSRC identifier.
     * @param string|null $cname The CNAME associated with the SSRC (optional).
     * @param string|null $msid The MSID associated with the SSRC (optional).
     * @param string|null $mslabel The MSLABEL associated with the SSRC (optional).
     * @param string|null $label The label associated with the SSRC (optional).
     */
    public function __construct(
        public int     $ssrc,
        public ?string $cname = null,
        public ?string $msid = null,
        public ?string $mslabel = null,
        public ?string $label = null
    )
    {
    }
}
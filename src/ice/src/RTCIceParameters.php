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

use Webrtc\Mixin\DataClass;

#[DataClass]
class RTCIceParameters
{
    /**
     * @param string|null $usernameFragment Remote/Local Ice username
     * @param string|null $password Remote/Local Ice password
     * @param bool $iceLite Remote/Local if is ice lite
     */
    public function __construct(
        public ?string $usernameFragment = null,
        public ?string $password = null,
        public bool    $iceLite = false
    )
    {
    }
}
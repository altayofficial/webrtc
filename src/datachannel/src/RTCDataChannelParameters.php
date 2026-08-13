<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\DataChannel;

class RTCDataChannelParameters
{
    /**
     * Create a new RTCDataChannelParameters instance.
     *
     * @param string $label A name describing the data channel.
     * @param int|null $maxPacketLifeTime The maximum packet lifetime in milliseconds.
     * @param int|null $maxRetransmits The maximum number of retransmissions.
     * @param bool $ordered Whether the data channel guarantees in-order delivery.
     * @param string $protocol The name of the subprotocol in use.
     * @param bool $negotiated Whether the data channel is negotiated out-of-band.
     * @param int|null $id A numeric ID for the channel.
     */
    public function __construct(
        public string $label = "",
        public ?int   $maxPacketLifeTime = null,
        public ?int   $maxRetransmits = null,
        public bool   $ordered = true,
        public string $protocol = "",
        public bool   $negotiated = false,
        public ?int   $id = null
    )
    {
    }
}
<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTCP;

/**
 * RTCP Source Description Item
 *
 * Represents a single source description chunk containing:
 * - SSRC identifier
 * - Array of SDES items (type-length-value tuples)
 *
 * SDES item types include:
 * - CNAME (canonical name) - required
 * - NAME (username)
 * - EMAIL (email address)
 * - PHONE (phone number)
 * - LOC (geographic location)
 * - TOOL (application/tool name)
 * - NOTE (notice/status)
 * - PRIV (private extensions)
 */
class RtcpSourceInfo
{
    /**
     * Constructs new source description chunk
     *
     * @param int $ssrc Synchronization source identifier
     * @param array $items Array of SDES items as [type, value] tuples
     */
    public function __construct(private readonly int $ssrc, private readonly array $items = [])
    {
    }

    /**
     * Get SSRC identifier
     *
     * @return int Source identifier
     */
    public function getSsrc(): int
    {
        return $this->ssrc;
    }

    /**
     * Get SDES items
     *
     * @return array Array of [type, value] tuples
     */
    public function getItems(): array
    {
        return $this->items;
    }
}
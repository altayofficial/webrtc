<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SCTP\Chunk;

use DateTimeImmutable;
use Webrtc\Mixin\DataClass;

#[DataClass]
class AttributeChunk
{
    /**
     * Attrs of chunk
     *
     * @param int $bookSize
     * @param float $expiry
     * @param int|null $maxRetransmits
     * @param bool $abandoned
     * @param bool $acked
     * @param int $misses
     * @param bool $retransmit
     * @param int $sentCount
     * @param float|null $sentTime
     */
    public function __construct(
        public int $bookSize = 0,
        public float $expiry = 0,
        public ?int $maxRetransmits = null,
        public bool $abandoned = false,
        public bool $acked = false,
        public int $misses = 0,
        public bool $retransmit = false,
        public int $sentCount = 0,
        public ?float $sentTime = null,
    )
    {
    }
}
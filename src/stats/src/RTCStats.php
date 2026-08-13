<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Stats;

use DateTimeImmutable;
use Webrtc\Mixin\DataClass;
use Webrtc\Stats\enum\StatType;

/**
 * Base class for statistics.
 */
#[DataClass]
class RTCStats
{
    /**
     * @param string $id The id associated with this stat.
     * @param StatType $type The type of the stat.
     * @param DateTimeImmutable $dateTime The timestamp associated with this object.
     */
    public function __construct(
        public string $id,
        public StatType $type,
        public DateTimeImmutable $dateTime = new DateTimeImmutable()
    )
    {
    }

}
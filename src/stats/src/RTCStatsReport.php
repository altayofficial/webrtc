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

/**
 * Provides statistics data about WebRTC connections.
 */
class RTCStatsReport
{
    private array $stats = [];

    public function add(RTCStats $stats): void
    {
        $this->stats[$stats->id] = $stats;
    }

    /**
     * @return RTCTransportStats[]
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * @param RTCStatsReport $report
     * @return void
     */
    public function merge(RTCStatsReport $report): void
    {
        foreach ($report->getStats() as $stat) {
            $this->add($stat);
        }
    }
}
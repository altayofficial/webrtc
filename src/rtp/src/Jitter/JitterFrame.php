<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\RTP\Jitter;

use Webrtc\Codecs\JitterFrameInterface;
use Webrtc\Mixin\DataClass;

#[DataClass]
class JitterFrame implements JitterFrameInterface
{
    /**
     * @param string $data Raw data to decode
     * @param int|null $timestamp The time of receiving data
     */
    public function __construct(
        private string $data,
        private ?int   $timestamp = null
    )
    {
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function setTimestamp(int $timestamp): void
    {
        $this->timestamp = $timestamp;
    }

    public function setData(string $data): void
    {
        $this->data = $data;
    }
}

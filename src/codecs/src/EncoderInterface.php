<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Codecs;

use Webrtc\AVCodec\Data\Packet;
use Webrtc\AVCodec\Frame\FrameInterface;

interface EncoderInterface
{
    public function encode(FrameInterface $frame, bool $useKeyframe): string|array;
    public function pack(Packet $packet): string|array;
    public function setBitrate(int $bitrate): void;
}
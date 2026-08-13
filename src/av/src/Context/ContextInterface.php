<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\AVCodec\Context;

use FFI\CData;
use Webrtc\AVCodec\Frame\FrameInterface;

interface ContextInterface
{
    public function open(bool $strict = true): void;

    public function getContext(): CData;

    public function prepareFramesForEncode(?FrameInterface $frame): array;

    public function getTimeBase(): CData;

    public function getNewFrame(): FrameInterface;
}
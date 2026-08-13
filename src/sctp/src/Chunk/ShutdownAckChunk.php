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

/**
 * Shutdown Acknowledgment chunk.
 */
class ShutdownAckChunk extends Chunk
{
    /** @var int Chunk type identifier. */
    protected int $type = 8;
}


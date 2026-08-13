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

interface ChunkInterface
{
    public function encode(): string;

    public function __toString(): string;

    public function getAttributes(): AttributeChunk;

    public function getType(): int;

    public function getBody(): string;

    public function setBody(string $body): void;

    public function getFlags(): int;

    public function setFlags(int $flags): void;
}

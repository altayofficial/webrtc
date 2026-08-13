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

use Webrtc\SCTP\SctpUtility;

/**
 * Base class for SCTP chunks.
 */
class Chunk implements ChunkInterface
{
    /** @var int Chunk type identifier. */
    protected int $type = -1;

    /** @var int Chunk flags. */
    protected int $flags = 0;

    /** @var string Chunk body. */
    protected string $body;
    private AttributeChunk $attributes;

    /**
     * Chunk constructor.
     *
     * @param int $flags Chunk flags.
     * @param string $body Chunk body.
     */
    public function __construct(int $flags = 0, string $body = "")
    {
        $this->flags = $flags;
        $this->body = $body;
        $this->attributes = new AttributeChunk();
    }

    /**
     * Encodes the chunk into a binary string.
     *
     * @return string Binary representation of the chunk.
     */
    public function encode(): string
    {
        $body = $this->getBody();

        $length = strlen($body) + 4;
        $data = pack("CCn", $this->type, $this->flags, $length) . $body;
        $data .= str_repeat("\x00", SctpUtility::padl($length));
        return $data;
    }

    /**
     * Returns a string representation of the chunk.
     *
     * @return string String representation.
     */
    public function __toString(): string
    {
        return sprintf("%s(flags=%d)", SctpUtility::chunkType($this), $this->flags);
    }

    /**
     * @return AttributeChunk
     */
    public function getAttributes(): AttributeChunk
    {
        return $this->attributes;
    }

    /**
     * @return int
     */
    public function getType(): int
    {
        return $this->type;
    }

    /**
     * @return string
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @param string $body
     * @return void
     */
    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    /**
     * @return int
     */
    public function getFlags(): int
    {
        return $this->flags;
    }

    /**
     * @param int $flags
     * @return void
     */
    public function setFlags(int $flags): void
    {
        $this->flags = $flags;
    }
}

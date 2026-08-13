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
 * Shutdown chunk.
 */
class ShutdownChunk extends Chunk
{
    /** @var int Chunk type identifier. */
    protected int $type = 7;

    /** @var int Cumulative TSN. */
    protected int $cumulativeTsn;

    /**
     * ShutdownChunk constructor.
     *
     * @param int $flags Chunk flags.
     * @param string|null $body Chunk body.
     */
    public function __construct(int $flags = 0, ?string $body = null)
    {
        parent::__construct($flags);
        if ($body) {
            $this->cumulativeTsn = unpack("N", $body)[1];
        } else {
            $this->cumulativeTsn = 0;
        }
    }

    /**
     * Gets the encoded body of the chunk.
     *
     * @return string Encoded body.
     */
    public function getBody(): string
    {
        return pack("N", $this->cumulativeTsn);
    }

    /**
     * Returns a string representation of the chunk.
     *
     * @return string String representation.
     */
    public function __toString(): string
    {
        return sprintf(
            "ShutdownChunk(flags=%d, cumulativeTsn=%d)",
            $this->flags, $this->cumulativeTsn
        );
    }

    /**
     * @return int
     */
    public function getCumulativeTsn(): int
    {
        return $this->cumulativeTsn;
    }

    /**
     * @param int $cumulativeTsn
     * @return void
     */
    public function setCumulativeTsn(int $cumulativeTsn): void
    {
        $this->cumulativeTsn = $cumulativeTsn;
    }
}


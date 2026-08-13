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
 * Forward TSN chunk.
 */
class ForwardTsnChunk extends Chunk
{
    /** @var int Chunk type identifier. */
    protected int $type = 192;

    /** @var int Cumulative TSN. */
    protected mixed $cumulativeTsn;

    /** @var array List of streams. */
    protected array $streams = [];

    /**
     * ForwardTsnChunk constructor.
     *
     * @param int $flags Chunk flags.
     * @param string|null $body Chunk body.
     */
    public function __construct(int $flags = 0, ?string $body = null)
    {
        parent::__construct($flags);
        if ($body) {
            $this->cumulativeTsn = unpack("N", substr($body, 0, 4))[1];
            $pos = 4;
            while ($pos < strlen($body)) {
                $this->streams[] = array_values(unpack("nstreamId/nstreamSeq", substr($body, $pos, 4)));
                $pos += 4;
            }
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
        $body = pack("N", $this->cumulativeTsn);
        foreach ($this->streams as [$streamId, $streamSeq]) {
            $body .= pack("nn", $streamId, $streamSeq);
        }
        return $body;
    }

    /**
     * Returns a string representation of the chunk.
     *
     * @return string String representation.
     */
    public function __toString(): string
    {
        return sprintf(
            "ForwardTsnChunk(cumulativeTsn=%d, streams=%s)",
            $this->cumulativeTsn, json_encode($this->streams)
        );
    }

    /**
     * @return mixed
     */
    public function getCumulativeTsn(): mixed
    {
        return $this->cumulativeTsn;
    }

    /**
     * @param mixed $cumulativeTsn
     * @return void
     */
    public function setCumulativeTsn(mixed $cumulativeTsn): void
    {
        $this->cumulativeTsn = $cumulativeTsn;
    }

    /**
     * @return array
     */
    public function getStreams(): array
    {
        return $this->streams;
    }

    /**
     * @param array $streams
     * @return void
     */
    public function setStreams(array $streams): void
    {
        $this->streams = $streams;
    }
}


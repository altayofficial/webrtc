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
 * Data chunk.
 */
class DataChunk extends Chunk
{
    /** @var int Chunk type identifier. */
    protected int $type = 0;

    /** @var int Transmission Sequence Number. */
    protected int $tsn;

    /** @var int Stream identifier. */
    protected int $streamId;

    /** @var int Stream sequence number. */
    protected int $streamSeq;

    /** @var int Protocol identifier. */
    protected int $protocol;

    /** @var string User data. */
    protected string $userData;

    /**
     * DataChunk constructor.
     *
     * @param int $flags Chunk flags.
     * @param string|null $body Chunk body.
     */
    public function __construct(int $flags = 0, ?string $body = null)
    {
        parent::__construct($flags);
        if ($body) {
            $unpacked = unpack("Ntsn/nstreamId/nstreamSeq/Nprotocol", $body);
            $this->tsn = $unpacked["tsn"];
            $this->streamId = $unpacked["streamId"];
            $this->streamSeq = $unpacked["streamSeq"];
            $this->protocol = $unpacked["protocol"];
            $this->userData = substr($body, 12);
        } else {
            $this->tsn = 0;
            $this->streamId = 0;
            $this->streamSeq = 0;
            $this->protocol = 0;
            $this->userData = "";
        }
    }

    /**
     * Encodes the chunk into a binary string.
     *
     * @return string Binary representation of the chunk.
     */
    public function encode(): string
    {
        $length = 16 + strlen($this->userData);
        $data = pack("CCnNnnN", $this->type, $this->flags, $length, $this->tsn, $this->streamId, $this->streamSeq, $this->protocol) . $this->userData;
        if ($length % 4) {
            $data .= str_repeat("\x00", SctpUtility::padl($length));
        }
        return $data;
    }

    /**
     * Returns a string representation of the chunk.
     *
     * @return string String representation.
     */
    public function __toString(): string
    {
        return sprintf(
            "DataChunk(flags=%d, tsn=%d, streamId=%d, streamSeq=%d)",
            $this->flags, $this->tsn, $this->streamId, $this->streamSeq
        );
    }

    /**
     * @return int
     */
    public function getTsn(): int
    {
        return $this->tsn;
    }

    /**
     * @return int
     */
    public function getStreamId(): int
    {
        return $this->streamId;
    }

    /**
     * @param int $streamId
     * @return void
     */
    public function setStreamId(int $streamId): void
    {
        $this->streamId = $streamId;
    }

    /**
     * @return string
     */
    public function getUserData(): string
    {
        return $this->userData;
    }

    /**
     * @param string $userData
     * @return void
     */
    public function setUserData(string $userData): void
    {
        $this->userData = $userData;
    }

    /**
     * @param int $tsn
     * @return void
     */
    public function setTsn(int $tsn): void
    {
        $this->tsn = $tsn;
    }

    /**
     * @return int
     */
    public function getStreamSeq(): int
    {
        return $this->streamSeq;
    }

    /**
     * @param int $streamSeq
     * @return void
     */
    public function setStreamSeq(int $streamSeq): void
    {
        $this->streamSeq = $streamSeq;
    }

    /**
     * @return int
     */
    public function getProtocol(): int
    {
        return $this->protocol;
    }

    /**
     * @param int $protocol
     * @return void
     */
    public function setProtocol(int $protocol): void
    {
        $this->protocol = $protocol;
    }
}

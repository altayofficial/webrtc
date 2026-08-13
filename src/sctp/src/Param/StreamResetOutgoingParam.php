<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SCTP\Param;

/**
 * Represents a Stream Reset Outgoing Parameter.
 */
class StreamResetOutgoingParam implements StreamParamInterface
{
    /**
     * StreamResetOutgoingParam constructor.
     *
     * @param int $requestSequence Request sequence number.
     * @param int $responseSequence Response sequence number.
     * @param int $lastTsn Last Transmission Sequence Number (TSN).
     * @param array $streams List of streams.
     */
    public function __construct(
        private readonly int   $requestSequence,
        private readonly int   $responseSequence,
        private readonly int   $lastTsn,
        private readonly array $streams = []
    )
    {
    }

    /**
     * Encodes the parameter into a binary string.
     *
     * @return string Binary representation of the parameter.
     */
    public function encode(): string
    {
        $data = pack("NNN", $this->requestSequence, $this->responseSequence, $this->lastTsn);
        foreach ($this->streams as $stream) {
            $data .= pack("n", $stream);
        }
        return $data;
    }

    /**
     * Decodes a binary string into a StreamResetOutgoingParam object.
     *
     * @param string $data Binary string to decode.
     * @return StreamResetOutgoingParam Decoded object.
     */
    public static function decode(string $data): self
    {
        $unpacked = unpack("NrequestSequence/NresponseSequence/NlastTsn", substr($data, 0, 12));
        $streams = [];
        for ($pos = 12; $pos < strlen($data); $pos += 2) {
            $streams[] = unpack("n", substr($data, $pos, 2))[1];
        }
        return new self(
            $unpacked["requestSequence"],
            $unpacked["responseSequence"],
            $unpacked["lastTsn"],
            $streams
        );
    }

    /**
     * @return array
     */
    public function getStreams(): array
    {
        return $this->streams;
    }

    /**
     * @return int
     */
    public function getRequestSequence(): int
    {
        return $this->requestSequence;
    }

    /**
     * @return int
     */
    public function getResponseSequence(): int
    {
        return $this->responseSequence;
    }

    /**
     * @return int
     */
    public function getLastTsn(): int
    {
        return $this->lastTsn;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return sprintf(
            "StreamResetOutgoingParam requestSequence: %d, responseSequence: %d, lastTsn: %d streams: %d",
            $this->requestSequence,
            $this->responseSequence,
            $this->lastTsn,
            count($this->streams)
        );
    }
}

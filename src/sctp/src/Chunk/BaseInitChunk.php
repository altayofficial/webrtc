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
 * Represents the base structure of an SCTP INIT or INIT-ACK chunk.
 *
 * This class provides common functionality and fields used by both INIT and INIT-ACK
 * chunks, including fields such as initiate tag, receiver window size, stream numbers,
 * initial TSN, and optional parameters.
 */
class BaseInitChunk extends Chunk
{
    /** @var int Initiate tag used for association identification. */
    protected int $initiateTag;

    /** @var int Advertised receiver window size. */
    protected int $advertisedRwnd;

    /** @var int Maximum number of outbound streams supported. */
    protected int $outboundStreams;

    /** @var int Maximum number of inbound streams supported. */
    protected int $inboundStreams;

    /** @var int Initial Transmission Sequence Number. */
    protected int $initialTsn;

    /** @var array List of encoded optional parameters. */
    protected array $params = [];

    /**
     * Constructs a new BaseInitChunk.
     *
     * If a body is provided, it will be parsed into the appropriate fields.
     *
     * @param int $flags Flags associated with the chunk.
     * @param string|null $body Binary body content of the chunk.
     */
    public function __construct(int $flags = 0, ?string $body = null)
    {
        parent::__construct($flags);
        if ($body) {
            $unpacked = unpack("NinitiateTag/NadvertisedRwnd/noutboundStreams/ninboundStreams/NinitialTsn", substr($body, 0, 16));
            $this->initiateTag = $unpacked["initiateTag"];
            $this->advertisedRwnd = $unpacked["advertisedRwnd"];
            $this->outboundStreams = $unpacked["outboundStreams"];
            $this->inboundStreams = $unpacked["inboundStreams"];
            $this->initialTsn = $unpacked["initialTsn"];
            $this->params = SctpUtility::decodeParams(substr($body, 16));
        } else {
            $this->initiateTag = 0;
            $this->advertisedRwnd = 0;
            $this->outboundStreams = 0;
            $this->inboundStreams = 0;
            $this->initialTsn = 0;
            $this->params = [];
        }
    }

    /**
     * Gets the initiate tag.
     *
     * @return int Initiate tag value.
     */
    public function getInitiateTag(): int
    {
        return $this->initiateTag;
    }

    /**
     * Sets the initiate tag.
     *
     * @param int $initiateTag Initiate tag value.
     */
    public function setInitiateTag(int $initiateTag): void
    {
        $this->initiateTag = $initiateTag;
    }

    /**
     * Gets the advertised receiver window.
     *
     * @return int Receiver window size.
     */
    public function getAdvertisedRwnd(): int
    {
        return $this->advertisedRwnd;
    }

    /**
     * Sets the advertised receiver window.
     *
     * @param int $advertisedRwnd Receiver window size.
     */
    public function setAdvertisedRwnd(int $advertisedRwnd): void
    {
        $this->advertisedRwnd = $advertisedRwnd;
    }

    /**
     * Gets the number of outbound streams.
     *
     * @return int Outbound stream count.
     */
    public function getOutboundStreams(): int
    {
        return $this->outboundStreams;
    }

    /**
     * Sets the number of outbound streams.
     *
     * @param int $outboundStreams Outbound stream count.
     */
    public function setOutboundStreams(int $outboundStreams): void
    {
        $this->outboundStreams = $outboundStreams;
    }

    /**
     * Gets the number of inbound streams.
     *
     * @return int Inbound stream count.
     */
    public function getInboundStreams(): int
    {
        return $this->inboundStreams;
    }

    /**
     * Sets the number of inbound streams.
     *
     * @param int $inboundStreams Inbound stream count.
     */
    public function setInboundStreams(int $inboundStreams): void
    {
        $this->inboundStreams = $inboundStreams;
    }

    /**
     * Gets the initial transmission sequence number.
     *
     * @return int Initial TSN.
     */
    public function getInitialTsn(): int
    {
        return $this->initialTsn;
    }

    /**
     * Sets the initial transmission sequence number.
     *
     * @param int $initialTsn Initial TSN value.
     */
    public function setInitialTsn(int $initialTsn): void
    {
        $this->initialTsn = $initialTsn;
    }

    /**
     * Gets the list of optional parameters.
     *
     * @return array Parameters array.
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Sets the list of optional parameters.
     *
     * @param array $params Parameters to set.
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /**
     * Adds one or more parameters to the existing parameter list.
     *
     * @param mixed $param A parameter or array of parameters to add.
     */
    public function addParams(mixed $param): void
    {
        $this->params = array_merge($this->params, $param);
    }

    /**
     * Gets the binary representation of the chunk body.
     *
     * @return string Encoded chunk body.
     */
    public function getBody(): string
    {
        $body = pack("NNnnN", $this->initiateTag, $this->advertisedRwnd, $this->outboundStreams, $this->inboundStreams, $this->initialTsn);
        $body .= SctpUtility::encodeParams($this->params);
        return $body;
    }
}

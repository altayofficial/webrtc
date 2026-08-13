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
 * Base class for chunks that contain parameters.
 */
class BaseParamsChunk extends Chunk
{
    /** @var array List of parameters. */
    protected array $params;

    /**
     * BaseParamsChunk constructor.
     *
     * @param int $flags Chunk flags.
     * @param string|null $body Chunk body.
     */
    public function __construct(int $flags = 0, ?string $body = null)
    {
        parent::__construct($flags);
        $this->params = $body ? SctpUtility::decodeParams($body) : [];
    }

    /**
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @param array $params
     * @return void
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /**
     * Add params and merge with existing ones
     *
     * @param array $param
     * @return void
     */
    public function addParams(array $param): void
    {
        $this->params = array_merge($this->params, $param);
    }

    /**
     * Gets the encoded body of the chunk.
     *
     * @return string Encoded body.
     */
    public function getBody(): string
    {
        return SctpUtility::encodeParams($this->params);
    }
}


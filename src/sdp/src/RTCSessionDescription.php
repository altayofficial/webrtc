<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SDP;

use Webrtc\Exception\InvalidArgumentException;

/**
 * Represents a session description for WebRTC.
 */
class RTCSessionDescription
{
    /** @var string The SDP (Session Description Protocol) string. */
    private string $sdp;

    /** @var string The type of session description. */
    private string $type;

    /** @var string[] Valid types for the session description. */
    private const VALID_TYPES = ["offer", "pranswer", "answer", "rollback"];

    /**
     * Initializes a new RTCSessionDescription instance.
     *
     * @param string $sdp The SDP string.
     * @param string $type The type of session description.
     * @throws InvalidArgumentException If the type is invalid.
     */
    public function __construct(string $sdp, string $type)
    {
        $this->validateType($type);
        $this->sdp = $sdp;
        $this->type = $type;
    }

    /**
     * Validates the session description type.
     *
     * @param string $type The type to validate.
     * @throws InvalidArgumentException If the type is invalid.
     */
    private function validateType(string $type): void
    {
        if (!in_array($type, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException(
                "'type' must be one of ['offer', 'pranswer', 'answer', 'rollback'] (got '$type')"
            );
        }
    }

    /**
     * Returns the SDP string.
     *
     * @return string The SDP string.
     */
    public function getSdp(): string
    {
        return $this->sdp;
    }

    /**
     * Returns the session description type.
     *
     * @return string The type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Returns the string representation of the session description.
     *
     * @return string The formatted string containing the SDP and type.
     */
    public function __toString(): string
    {
        return sprintf("RTCSessionDescription(sdp=%s, type=%s)", $this->sdp, $this->type);
    }

    /**
     * @param string $sdp
     * @return void
     */
    public function setSdp(string $sdp): void
    {
        $this->sdp = $sdp;
    }

    /**
     * @param string $type
     * @return void
     */
    public function setType(string $type): void
    {
        $this->type = $type;
    }
}
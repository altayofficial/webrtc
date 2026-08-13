<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\ICE;

/**
 * Class RTCIceServer
 *
 * Represents a STUN or TURN server configuration used during ICE candidate gathering.
 * This class holds information such as the server URLs, credentials, and credential type
 * needed to authenticate with the server.
 *
 * Typically used to configure ICE transport behavior when establishing peer-to-peer
 * WebRTC connections.
 */
class RTCIceServer implements RTCIceServerInterface
{
    /**
     * @var array<string> List of STUN/TURN server URLs.
     */
    private array $urls = [];

    /**
     * @var string|null Username used for TURN server authentication.
     */
    private ?string $username = null;

    /**
     * @var string|null Credential (password or token) used for TURN server authentication.
     */
    private ?string $credential = null;

    /**
     * @var string Type of credential (e.g., "password" or "token"). Defaults to "password".
     */
    private string $credentialType = "password";

    /**
     * Get the list of STUN or TURN server URLs.
     *
     * @return array<string> Array of server URLs.
     */
    public function getUrls(): array
    {
        return $this->urls;
    }

    /**
     * Set one or more server URLs for the ICE server.
     *
     * @param array|string $urls A string or array of STUN/TURN URLs.
     *                           If a string is passed, it is added to the existing list.
     *
     * @return void
     */
    public function setUrls(array|string $urls): void
    {
        $this->urls = is_string($urls) ? array_merge($this->urls, [$urls]) : $urls;
    }

    /**
     * Get the TURN username used for authentication.
     *
     * @return string|null Username or null if not set.
     */
    public function getUsername(): ?string
    {
        return $this->username;
    }

    /**
     * Set the TURN username used for authentication.
     *
     * @param string|null $username The username to set or null to unset.
     *
     * @return void
     */
    public function setUsername(?string $username): void
    {
        $this->username = $username;
    }

    /**
     * Get the credential (e.g., password) for TURN authentication.
     *
     * @return string|null Credential or null if not set.
     */
    public function getCredential(): ?string
    {
        return $this->credential;
    }

    /**
     * Set the credential (e.g., password) for TURN authentication.
     *
     * @param string|null $credential Credential or null to unset.
     *
     * @return void
     */
    public function setCredential(?string $credential): void
    {
        $this->credential = $credential;
    }

    /**
     * Get the credential type used for TURN authentication.
     *
     * @return string Credential type (typically "password" or "token").
     */
    public function getCredentialType(): string
    {
        return $this->credentialType;
    }

    /**
     * Set the credential type used for TURN authentication.
     *
     * @param string $credentialType Credential type to set.
     *
     * @return void
     */
    public function setCredentialType(string $credentialType): void
    {
        $this->credentialType = $credentialType;
    }
}

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

use Webrtc\Exception\InvalidArgumentException;

/**
 * Class RTCIceProtocolConfiguration
 *
 * Represents the configuration for ICE servers (STUN and TURN) used in ICE negotiation.
 *
 * This class allows setting and retrieving information about the STUN server and TURN server,
 * including credentials and transport settings. It is used internally by ICE components
 * to determine how candidates should be gathered and communicated.
 */
class RTCIceProtocolConfiguration implements RTCIceProtocolConfigurationInterface
{
    private ?array $stunServer = null;
    private ?array $turnServer = null;
    private bool $turnSsl = false;
    private string $turnTransport = 'udp';
    private ?string $turnUsername = null;
    private ?string $turnPassword = null;

    /**
     * Get the STUN server configuration.
     *
     * @return array|null The STUN server as an array (e.g., ['host' => ..., 'port' => ...]) or null.
     */
    public function getStunServer(): ?array
    {
        return $this->stunServer;
    }

    /**
     * Set the STUN server configuration.
     *
     * @param array|null $stunServer The STUN server array or null to unset.
     *
     * @return void
     */
    public function setStunServer(?array $stunServer): void
    {
        $this->stunServer = $stunServer;
    }

    /**
     * Get the TURN server configuration.
     *
     * @return array|null The TURN server as an array (e.g., ['host' => ..., 'port' => ...]) or null.
     */
    public function getTurnServer(): ?array
    {
        return $this->turnServer;
    }

    /**
     * Set the TURN server configuration.
     *
     * @param array|null $turnServer The TURN server array or null to unset.
     *
     * @return void
     */
    public function setTurnServer(?array $turnServer): void
    {
        $this->turnServer = $turnServer;
    }

    /**
     * Check if TURN over SSL (TURNS) is enabled.
     *
     * @return bool True if SSL is enabled, false otherwise.
     */
    public function getTurnSsl(): bool
    {
        return $this->turnSsl;
    }

    /**
     * Set whether TURN over SSL (TURNS) should be used.
     *
     * @param bool $turnSsl True to enable TURNS, false to disable.
     *
     * @return void
     */
    public function setTurnSsl(bool $turnSsl): void
    {
        $this->turnSsl = $turnSsl;
    }

    /**
     * Get the TURN server username for authentication.
     *
     * @return string|null The username or null if not set.
     */
    public function getTurnUsername(): ?string
    {
        return $this->turnUsername;
    }

    /**
     * Set the TURN server username for authentication.
     *
     * @param string|null $turnUsername The username or null to unset.
     *
     * @return void
     */
    public function setTurnUsername(?string $turnUsername): void
    {
        $this->turnUsername = $turnUsername;
    }

    /**
     * Get the TURN server password for authentication.
     *
     * @return string|null The password or null if not set.
     */
    public function getTurnPassword(): ?string
    {
        return $this->turnPassword;
    }

    /**
     * Set the TURN server password for authentication.
     *
     * @param string|null $turnPassword The password or null to unset.
     *
     * @return void
     */
    public function setTurnPassword(?string $turnPassword): void
    {
        $this->turnPassword = $turnPassword;
    }

    /**
     * Get the TURN server transport protocol.
     *
     * @return string Either 'udp' or 'tcp'.
     */
    public function getTurnTransport(): string
    {
        return $this->turnTransport;
    }

    /**
     * Set the TURN server transport protocol.
     *
     * @param string $turnTransport Must be either 'udp' or 'tcp'.
     *
     * @throws InvalidArgumentException If an invalid transport protocol is given.
     *
     * @return void
     */
    public function setTurnTransport(string $turnTransport): void
    {
        if (!in_array($turnTransport, ['udp', 'tcp'], true)) {
            throw new InvalidArgumentException('Invalid turn transport');
        }
        $this->turnTransport = $turnTransport;
    }
}

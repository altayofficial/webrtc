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

use stdClass;
use Webrtc\Exception\InvalidArgumentException;

/**
 * ICE Protocol Parser
 *
 * Parses ICE server configurations and generates protocol-specific connection settings.
 * Handles both STUN and TURN server configurations with proper URL parsing and validation.
 *
 * Key Features:
 * - Parses ICE server URLs (STUN/TURN)
 * - Validates URL schemes and formats
 * - Configures STUN server connections
 * - Configures TURN server connections with authentication
 * - Handles both UDP and TCP transport
 * - Supports secure (TLS) connections
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7064 STUN URI Scheme
 * @see https://datatracker.ietf.org/doc/html/rfc7065 TURN URI Scheme
 */
class IceProtocolParser
{
    /**
     * @param RTCIceServer[] $iceServes Array of ICE server configurations
     */
    public function __construct(private readonly array $iceServes)
    {
    }

    /**
     * Generate ICE connection configuration
     *
     * Processes all configured ICE servers and created a unified connection configuration
     * with proper STUN/TURN server settings, transport, and authentication.
     *
     * @return RTCIceProtocolConfigurationInterface Fully configured ICE protocol settings
     *
     * @example
     * $parser = new IceProtocolParser($iceServers);
     * $config = $parser->getIceConnectionConfiguration();
     */
    public function getIceConnectionConfiguration(): RTCIceProtocolConfigurationInterface
    {
        $iceConnectionConfiguration = new RTCIceProtocolConfiguration();

        foreach ($this->iceServes as $iceServe) {
            foreach ($iceServe->getUrls() as $url) {
                $parsedUrl = $this->parseUrl($url);

                switch ($parsedUrl->scheme) {
                    case 'stun':
                    case 'stuns':
                        $this->configureStunServer($iceConnectionConfiguration, $parsedUrl);
                        break;

                    case 'turn':
                    case 'turns':
                        $this->configureTurnServer($iceConnectionConfiguration, $parsedUrl, $iceServe);
                        break;

                    default:
                        break;
                }
            }
        }

        return $iceConnectionConfiguration;
    }

    /**
     * Parse and validate ICE server URL
     *
     * Processes ICE server URLs with the following capabilities:
     * - Handles both standard (with ://) and shorthand (with :) URL formats
     * - Extracts scheme, host, port, and query parameters
     * - Sets default ports for protocols (3478 for STUN/TURN, 5349 for secure variants)
     * - Validates transport protocols for TURN servers
     *
     * @param string $url ICE server URL to parse
     * @return stdClass Parsed URL components including:
     *                  - Scheme (stun/stuns/turn/turns)
     *                  - host
     *                  - port
     *                  - transport (for TURN servers)
     * @throws InvalidArgumentException If URL is malformed or contains invalid components
     *
     * @example
     * $parsed = $parser->parseUrl('turn:example.com?transport=udp');
     * // Returns object with a scheme=turn, host=example.com, port=3478, transport=udp
     */
    public function parseUrl(string $url): stdClass
    {
        if (preg_match('/^[a-zA-Z][a-zA-Z\d+\-.]*:/', $url) && !str_contains($url, '://')) {
            $url = preg_replace('/^([^:]+):/', '$1://', $url, 1);
        }

        $parts = parse_url($url);
        if (isset($parts['query'])) {
            parse_str($parts['query'], $parts['query']);
        }

        if (!isset($parts["scheme"]) || !isset($parts["host"]) || !in_array($parts["scheme"], ["stun", "stuns", "turn", "turns"])) {
            throw new InvalidArgumentException("Invalid url: {$url}");
        }

        $parts["port"] = $parts["port"] ?? (in_array($parts["scheme"], ["stuns", "turns"]) ? 5349 : 3478);

        if (in_array($parts["scheme"], ["turn", "turns"])) {
            $parts["transport"] = $parts["query"]["transport"] ?? ($parts["scheme"] == "turn" ? "udp" : ($parts["scheme"] == "turns" ? "tcp" : null));
            unset($parts["query"]);
        }

        return (object)$parts;
    }

    /**
     * Configure STUN server settings
     *
     * Sets the primary STUN server configuration if not already set.
     * Only configure the first valid STUN server encountered.
     *
     * @param RTCIceProtocolConfiguration $config Configuration object to modify
     * @param object $parsedUrl Parsed URL components from parseUrl()
     */
    private function configureStunServer(RTCIceProtocolConfiguration $config, object $parsedUrl): void
    {
        if (!$config->getStunServer()) {
            $config->setStunServer([$parsedUrl->host, $parsedUrl->port]);
        }
    }

    /**
     * Configure TURN server settings
     *
     * Sets TURN server configuration with authentication if:
     * - No TURN server is already configured
     * - Transport protocol is valid for the scheme
     * - Credential type is 'password'
     *
     * @param RTCIceProtocolConfiguration $config Configuration object to modify
     * @param stdClass $parsedUrl Parsed URL components from parseUrl()
     * @param RTCIceServer $iceServe Source ICE server configuration containing credentials
     */
    private function configureTurnServer(RTCIceProtocolConfiguration $config, stdClass $parsedUrl, RTCIceServer $iceServe): void
    {
        if ($config->getTurnServer()) {
            return;
        }

        if (($parsedUrl->scheme === 'turn' && !in_array($parsedUrl->transport, ['udp', 'tcp'])) ||
            ($parsedUrl->scheme === 'turns' && $parsedUrl->transport !== 'tcp') ||
            ($iceServe->getCredentialType() !== 'password')) {
            return;
        }

        $config->setTurnServer([$parsedUrl->host, $parsedUrl->port]);
        $config->setTurnSsl($parsedUrl->scheme === 'turns');
        $config->setTurnTransport($parsedUrl->transport);
        $config->setTurnUsername($iceServe->getUsername());
        $config->setTurnPassword($iceServe->getCredential());
    }
}
<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\ICE\Trait;

use React\Dns\Config\Config;
use React\Dns\Resolver\Factory;
use Throwable;
use function React\Async\await;

/**
 * DNS Resolution Trait
 *
 * Provides DNS resolution functionality for ICE (Interactive Connectivity Establishment) implementations.
 * This trait handles both direct IP addresses and domain name resolution with caching support.
 *
 * Features:
 * - Automatic fallback to Google's public DNS (8.8.8.8) if no system nameservers are configured
 * - Asynchronous DNS resolution using ReactPHP
 * - Caching of DNS queries for improved performance
 * - Support for both IPv4 and IPv6 addresses
 *
 */
trait DNS
{
    /**
     * Resolve a network address to its IP equivalent
     *
     * This method takes an address array (typically [host, port]) and resolves the host
     * to an IP address if it isn't already one. The resolution process uses system-configured
     * nameservers with a fallback to Google's public DNS.
     *
     * The method supports both synchronous and asynchronous operation through ReactPHP's async
     * functionality and includes built-in DNS caching.
     *
     * @param array $address An array containing [host, port] where host can be either:
     *                       - A domain name (e.g., 'example.com')
     *                       - An IP address (IPv4 or IPv6)
     *
     * @return array Returns an array in the format [resolved_ip, original_port]
     *
     * @throws Throwable If DNS resolution fails or encounters an error
     *
     * @example
     * // Resolve a domain name
     * $resolved = $this->resolveDNS(['example.com', 1234]);
     * // Might return ['93.184.216.34', 1234]
     *
     * // Pass through an existing IP
     * $resolved = $this->resolveDNS(['192.0.2.1', 5678]);
     * // Returns ['192.0.2.1', 5678]
     */
    private function resolveDNS(array $address): array
    {
        if (filter_var($address[0], FILTER_VALIDATE_IP)) {
            return $address;
        }

        $config = Config::loadSystemConfigBlocking();
        if (!$config->nameservers) {
            $config->nameservers[] = '8.8.8.8';
        }

        $factory = new Factory();
        $dns = $factory->createCached($config);

        $ipAddresses = await($dns->resolve($address[0]));

        return [$ipAddresses, $address[1]];
    }
}
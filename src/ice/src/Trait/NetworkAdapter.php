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

use Webrtc\ICE\Utils;
use function React\Async\await;

/**
 * Network Adapter Trait
 *
 * Provides functionality for retrieving and managing local network interface addresses.
 * This trait handles both IPv4 and IPv6 addresses with caching support and filtering capabilities.
 *
 * Key Features:
 * - Retrieves all available network interface addresses
 * - Filters loopback addresses (127.0.0.1 and ::1)
 * - Supports both IPv4 and IPv6 addresses
 * - Includes address caching mechanism
 * - Provides filtered results based on protocol preferences
 */
trait NetworkAdapter
{
    /**
     * Retrieve host addresses from local network interfaces
     *
     * Gets all available host addresses from network adapters, with optional protocol filtering.
     * Results are cached to improve performance on subsequent calls.
     *
     * @param bool $useIPv4 Whether to include IPv4 addresses (default: true)
     * @param bool $useIPv6 Whether to include IPv6 addresses (default: true)
     * @return array Array of filtered IP addresses based on protocol preferences
     * @throws \Throwable If network interface retrieval fails
     *
     * @example
     * // Get all addresses
     * $addresses = $this->getHostAddresses(true, true);
     *
     * // Get only IPv6 addresses
     * $addresses = $this->getHostAddresses(false, true);
     */
    public function getHostAddresses(bool $useIPv4, bool $useIPv6): array
    {
        $hostAddresses = await($this?->cache->has("hostAddresses"))
            ? await($this->cache->get("hostAddresses"))
            : $this->getHostFromNetworkAdapter();

        return $this->filterAddresses($hostAddresses, $useIPv4, $useIPv6);
    }

    /**
     * Retrieve and process addresses from network interfaces
     *
     * Collects all unicast addresses from available network interfaces,
     * excluding loopback addresses, and organizes them by IP version.
     * IPv6 addresses are enclosed in square brackets for URI compatibility.
     *
     * @return array Array of addresses grouped by IP version (v4, v6)
     *
     * @example
     * $addresses = $this->getHostFromNetworkAdapter();
     * // Returns ['v4' => ['192.168.1.2'], 'v6' => ['[fe80::1]']]
     */
    private function getHostFromNetworkAdapter(): array
    {
        $hostAddresses = [];

        foreach ($this->getInterfaces() as $interface) {
            if (!isset($interface["unicast"])) {
                continue;
            }

            foreach ($interface["unicast"] as $adapter) {
                if ($address = $adapter["address"] ?? null) {
                    if (!in_array($address ,["127.0.0.1", "::1"])) {
                        $version = Utils::IPVersion($address);
                        $hostAddresses["v$version"][] = $version === 6 ? "[" .$address ."]" : $address;
                    }
                }
            }
        }

        $this->cache->set("hostAddresses", $hostAddresses);
        return $hostAddresses;
    }

    /**
     * Filter addresses by protocol version
     *
     * Filters the collected addresses based on the requested protocol versions.
     * Can return IPv4 only, IPv6 only, or combined addresses.
     *
     * @param array $hostAddresses Addresses to filter (grouped by v4/v6)
     * @param bool $useIPv4 Whether to include IPv4 addresses
     * @param bool $useIPv6 Whether to include IPv6 addresses
     * @return array Filtered array of addresses
     *
     * @example
     * $filtered = $this->filterAddresses($hostAddresses, true, false);
     * // Returns only IPv4 addresses
     */
    private function filterAddresses(array $hostAddresses, bool $useIPv4, bool $useIPv6): array
    {
        $v4 = $hostAddresses["v4"] ?? [];
        $v6 = $hostAddresses["v6"] ?? [];

        return match (true) {
            $useIPv4 && $useIPv6 => array_merge($v4, $v6),
            $useIPv4 => $v4,
            default => $v6
        };
    }

    /**
     * Get network interface information
     *
     * Wrapper for PHP's net_get_interfaces() function.
     * Returns an array of all the network interfaces on the machine.
     *
     * @return array Network interface information
     * @see https://www.php.net/manual/en/function.net-get-interfaces.php
     */
    public function getInterfaces(): array
    {
        return net_get_interfaces();
    }
}
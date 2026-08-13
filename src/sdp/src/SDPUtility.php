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

class SDPUtility
{
    /**
     * @var string
     */
    private const IP_REGEX = "/^IN (IP4|IP6) ([^ ]+)$/";
    /**
     * @var mixed[]
     */
    public const FMTP_INT_PARAMETERS = ["cname", "msid", "mslabel", "label"];

    /**
     * Extracts the IP address from an SDP string.
     *
     * @param string $sdp
     * @return string
     * @throws InvalidArgumentException
     */
    public static function ipAddressFromSDP(string $sdp): string
    {
        if (!preg_match(self::IP_REGEX, $sdp, $matches)) {
            throw new InvalidArgumentException("Invalid SDP format: IP address not found.");
        }
        return $matches[2];
    }

    /**
     * Converts an IP address to an SDP string.
     *
     * @param string $addr
     * @return string
     */
    public static function ipAddressToSDP(string $addr): string
    {
        $version = filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 6 : 4;
        return "IN IP$version $addr";
    }

    /**
     * Extracts parameters from an SDP string.
     *
     * @param string $sdp
     * @return array
     */
    public static function parametersFromSDP(string $sdp): array
    {
        $parameters = [];
        $params = explode(';', $sdp);

        foreach ($params as $param) {
            if (str_contains($param, '=')) {
                [$k, $v] = explode('=', $param, 2);
                $parameters[$k] = in_array($k, self::FMTP_INT_PARAMETERS) ? (int)$v : $v;
            } else {
                $parameters[$param] = null;
            }
        }

        return $parameters;
    }

    /**
     * Converts parameters to an SDP string.
     *
     * @param array $parameters
     * @return string
     */
    public static function parametersToSDP(array $parameters): string
    {
        $params = [];
        foreach ($parameters as $k => $v) {
            $params[] = $v !== null ? "$k=$v" : $k;
        }
        return implode(';', $params);
    }

    /**
     * Parses a group description from an SDP string.
     *
     * @param string $value
     * @param string $type
     * @return ?GroupDescription
     */
    public static function parseGroup(string $value, string $type = 'string'): ?GroupDescription
    {
        $bits = explode(' ', trim($value));
        if (!empty($bits)) {
            $items = array_map(
                fn($item) => $type === 'int' ? (int)$item : $item,
                array_slice($bits, 1)
            );
            return new GroupDescription(semantic: $bits[0], items: $items);
        }

        return null;
    }
}
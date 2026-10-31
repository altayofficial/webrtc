<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\STUN;

/**
 * STUN Utility Class
 *
 * Provides helper methods for STUN (Session Traversal Utilities for NAT) protocol operations
 */
class Utils
{
    /**
     * Calculate required padding length for STUN attributes
     *
     * STUN attributes are padded to 4-byte boundaries. This method calculates
     * how many additional bytes are needed to reach the next 4-byte boundary.
     *
     * @param int $length The original length of the attribute/data
     * @return int The number of padding bytes needed (0-3)
     *
     * @example
     * <code>
     * Utils::paddingLength(5); // returns 3 (since 5 + 3 = 8, which is divisible by 4)
     * Utils::paddingLength(4); // returns 0
     * Utils::paddingLength(7); // returns 1
     * </code>
     */
    public static function paddingLength(int $length): int
    {
        $rest = $length % 4;
        return $rest === 0 ? 0 : 4 - $rest;
    }

    /**
     * An IP without the brackets a URI or a stream name puts around IPv6. Candidates, SDP and
     * map keys want it bare, anything joining it with a port puts them back via formatAddress().
     */
    public static function bareHost(string $host): string
    {
        return trim($host, "[]");
    }

    /**
     * "host:port" the way stream_socket_sendto() and stream_socket_server() take it, which for
     * IPv6 means "[host]:port". Joining an IPv6 address with a plain colon gives one they reject.
     */
    public static function formatAddress(string $host, int|string $port): string
    {
        $host = self::bareHost($host);
        return str_contains($host, ":") ? "[$host]:$port" : "$host:$port";
    }
}
<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\MDNS;

use React\Dns\Query\ExecutorInterface;
use React\Dns\Resolver\Resolver;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;

/**
 * Factory for creating mDNS (Multicast DNS) resolvers using ReactPHP components.
 *
 * This class initializes a multicast DNS resolver suitable for use with
 * WebRTC local peer discovery and service resolution scenarios.
 */
class Factory
{
    /**
     * Default mDNS address used for multicast DNS queries (RFC 6762).
     * @var string
     */
    const DNS = '224.0.0.251:5353';

    /**
     * The event loop instance used to drive asynchronous operations.
     */
    private LoopInterface $loop;

    /**
     * The DNS executor responsible for sending DNS queries over multicast.
     */
    private ExecutorInterface $executor;

    /**
     * Constructs the Factory with optional custom event loop and executor.
     *
     * If no loop is provided, the default global loop is used.
     * If no executor is provided, a multicast executor is created using the default DNS address.
     *
     * @param ?LoopInterface $loop Optional ReactPHP event loop instance.
     * @param ?ExecutorInterface $executor Optional DNS executor for query resolution.
     */
    public function __construct(?LoopInterface $loop = null, ?ExecutorInterface $executor = null)
    {
        $this->loop = $loop ?: Loop::get();
        $this->executor = $executor ?: new MulticastExecutor(self::DNS, $loop);
    }

    /**
     * Creates and returns a ReactPHP DNS resolver using the configured executor.
     *
     * @return Resolver A DNS resolver capable of performing mDNS queries.
     */
    public function createResolver(): Resolver
    {
        return new Resolver($this->executor);
    }
}

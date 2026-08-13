<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\TURN;

use React\Socket\ConnectionInterface;
use Throwable;
use Webrtc\Mixin\EventForwarder;
use Webrtc\STUN\BaseProtocol;
use function call_user_func_array;
use function parse_url;

/**
 * Abstract TCP Connection Class
 *
 * Provides base functionality for TCP-based communication in TURN protocol.
 * Handles TCP socket operations, event forwarding, and requires implementation
 * of protocol-specific message handling.
 */
abstract class TCPConnection extends BaseProtocol
{
    use EventForwarder;

    /**
     * @var array<string, string> Map of socket events to handler methods
     */
    private const FORWARD_EVENT_METHOD_MAP = [
        "data" => "onTCPReceived",
        "end" => "onEnded",
        "error" => "onError",
        "close" => "onClose"
    ];

    /**
     * TCP connection constructor.
     *
     * @param ConnectionInterface $socket The established TCP socket connection
     */
    public function __construct(protected ConnectionInterface $socket)
    {
        $this->forwardEvents2Methods($socket, self::FORWARD_EVENT_METHOD_MAP);
    }

    /**
     * Send data over the TCP connection
     *
     * @param string $data The data to send
     * @param string|null $remoteAddress Unused parameter (maintained for interface compatibility)
     * @return void
     */
    public function send(string $data, ?string $remoteAddress = null): void
    {
        $this->socket->write($this->padded($data));
    }

    /**
     * Immediately close the TCP connection
     *
     * @return void
     */
    public function close(): void
    {
        $this->socket->close();
    }

    /**
     * End the TCP connection gracefully after writing pending data
     *
     * @return void
     */
    public function end(): void
    {
        $this->socket->end();
    }

    /**
     * Resume reading from the connection
     *
     * @return void
     */
    public function resume(): void
    {
        $this->socket->resume();
    }

    /**
     * Pause reading from the connection
     *
     * @return void
     */
    public function pause(): void
    {
        $this->socket->pause();
    }

    /**
     * Get the local connection address
     *
     * @return string The local address in "host:port" format
     */
    public function getLocalAddress(): string
    {
        return $this->socket->getLocalAddress();
    }

    /**
     * Get the local host address
     *
     * @return string The local hostname/IP address
     */
    public function getLocalHost(): string
    {
        return parse_url($this->getLocalAddress(), PHP_URL_HOST);
    }

    /**
     * Get the local port number
     *
     * @return int The local port number
     */
    public function getLocalPort(): int
    {
        return parse_url($this->getLocalAddress(), PHP_URL_PORT);
    }

    /**
     * Get the remote connection address
     *
     * @return string|null The remote address in "host:port" format or null if not connected
     */
    public function getRemoteAddress(): ?string
    {
        return $this->socket->getRemoteAddress();
    }

    /**
     * Handle received TCP data (abstract method)
     *
     * @param string $data The received data
     * @return void
     */
    protected abstract function onTCPReceived(string $data): void;

    /**
     * Handle connection errors (abstract method)
     *
     * @param Throwable $e The thrown exception
     * @return void
     */
    protected abstract function onError(Throwable $e): void;

    /**
     * Handle connection end event (abstract method)
     *
     * @return void
     */
    protected abstract function onEnded(): void;

    /**
     * Handle connection close event (abstract method)
     *
     * @return void
     */
    protected abstract function onClose(): void;

    /**
     * Apply protocol-specific padding to data (abstract method)
     *
     * @param string $data The data to pad
     * @return string The padded data
     */
    protected abstract function padded(string $data): string;

    /**
     * Check if argument is the socket or this instance
     *
     * @param mixed $argument The argument to check
     * @return TCPConnection|ConnectionInterface Returns $this if argument is the socket, otherwise returns the argument
     */
    private function isInstanceofArgument(mixed $argument): TCPConnection|ConnectionInterface
    {
        return ($argument instanceof $this->socket) ? $this : $argument;
    }

    /**
     * Magic method to delegate calls to the underlying socket
     *
     * @param string $method The method name to call
     * @param array $parameters The method parameters
     * @return TCPConnection|ConnectionInterface
     */
    public function __call(string $method, array $parameters)
    {
        return $this->isInstanceofArgument(
            call_user_func_array([$this->socket, $method], $parameters)
        );
    }
}
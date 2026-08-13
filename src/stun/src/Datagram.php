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

use React\Datagram\Socket;
use React\Datagram\SocketInterface;
use Throwable;
use Webrtc\Mixin\EventForwarder;
use function call_user_func_array;
use function parse_url;

/**
 * User Datagram Protocol
 *
 * Handles the UDP communication for WebRTC.
 * Provides an abstraction layer over ReactPHP's Datagram socket with additional WebRTC-specific functionality.
 *
 * @mixin Socket
 * @see https://datatracker.ietf.org/doc/html/rfc768 User Datagram Protocol
 */
abstract class Datagram extends BaseProtocol
{
    use EventForwarder;

    /**
     * @var array<string, string> Map of socket events to corresponding handler methods
     */
    private const FORWARD_EVENT_METHOD_MAP = [
        "message" => "onReceived",
        "error" => "onError",
        "close" => "onClose"
    ];

    /** @var string The remote address for communication */
    protected string $remoteAddress;

    /** @var string The local host address */
    private string $localHost;

    /** @var int The local port number */
    private int $localPort;

    /**
     * Datagram constructor.
     *
     * @param SocketInterface $socket The socket interface to use for UDP communication
     */
    public function __construct(protected SocketInterface $socket)
    {
        $this->forwardEvents2Methods($this->socket, self::FORWARD_EVENT_METHOD_MAP);
        $this->parseLocalAddress();
    }

    /**
     * Send data through the UDP socket
     *
     * @param string $data The data to send
     * @param string|null $remoteAddress The target address (uses default remote address if null)
     * @return void
     */
    public function send(string $data, ?string $remoteAddress = null): void
    {
        $this->socket->send($data, $remoteAddress ?? $this->remoteAddress);
    }

    /**
     * Close the UDP socket immediately
     *
     * @return void
     */
    public function close(): void
    {
        $this->socket->close();
    }

    /**
     * End the UDP socket gracefully after flushing write buffer
     *
     * @return void
     */
    public function end(): void
    {
        $this->socket->end();
    }

    /**
     * Resume reading from the socket
     *
     * @return void
     */
    public function resume(): void
    {
        $this->socket->resume();
    }

    /**
     * Pause reading from the socket
     *
     * @return void
     */
    public function pause(): void
    {
        $this->socket->pause();
    }

    /**
     * Get the local socket address
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
     * @return string The local host IP address
     */
    public function getLocalHost(): string
    {
        return $this->localHost;
    }

    /**
     * Get the local port number
     *
     * @return int The local port number
     */
    public function getLocalPort(): int
    {
        return $this->localPort;
    }

    /**
     * Get the remote address
     *
     * @return string|null The remote address in "host:port" format or null if not connected
     */
    public function getRemoteAddress(): ?string
    {
        return $this->socket->getRemoteAddress();
    }

    /**
     * Handle received messages (abstract method to be implemented by concrete classes)
     *
     * @param string $data The received data
     * @param string $peerAddress The address of the peer that sent the data
     * @return void
     */
    protected abstract function onReceived(string $data, string $peerAddress): void;

    /**
     * Handle socket errors (abstract method to be implemented by concrete classes)
     *
     * @param Throwable $e The thrown exception
     * @return void
     */
    protected abstract function onError(Throwable $e): void;

    /**
     * Handle socket close events (abstract method to be implemented by concrete classes)
     *
     * @return void
     */
    protected abstract function onClose(): void;

    /**
     * Check if an argument is an instance of Datagram or Socket
     *
     * @param mixed $argument The argument to check
     * @return Datagram|Socket Returns $this if argument is instanceof $this->socket, otherwise returns the argument
     */
    private function isInstanceofArgument(mixed $argument): Datagram|Socket
    {
        return ($argument instanceof $this->socket) ? $this : $argument;
    }

    /**
     * Magic method to delegate calls to the underlying socket
     *
     * @param string $method The method name to call
     * @param array $parameters The method parameters
     * @return Datagram|Socket
     */
    public function __call(string $method, array $parameters)
    {
        return $this->isInstanceofArgument(
            call_user_func_array([$this->socket, $method], $parameters)
        );
    }

    /**
     * Parse and store the local address components (host and port)
     *
     * @return void
     */
    private function parseLocalAddress(): void
    {
        $address = $this->socket->getLocalAddress();

        if (!str_contains($address, '://')) {
            $address = 'udp://' . $address;
        }
        $address = parse_url($address);

        $this->localHost = $address['host'];
        $this->localPort = $address['port'];
    }
}
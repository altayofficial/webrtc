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

use Psr\Log\LoggerInterface;
use Random\RandomException;
use React\Promise\PromiseInterface;
use Throwable;
use Webrtc\ICE\RTCIceCandidate;
use Webrtc\STUN\Message\MessageInterface;
use Webrtc\STUN\ReceiverInterface;

/**
 * Class TurnTransport
 * Behaves like a Datagram transport but uses a TURN allocation.
 */
class Turn implements TurnInterface
{
    public function __construct(private TurnConnectionInterface $connectionProtocol)
    {
    }

    /**
     * Initiates a TURN connection and returns a PromiseInterface.
     *
     * This method establishes a connection with a TURN server and allocates resources.
     * It uses promises to handle the asynchronous nature of the connection process.
     *
     * @return PromiseInterface A promise that resolves to the connection details or rejects with an error.
     * @throws RandomException
     */
    public function connect(): PromiseInterface
    {
        return $this->connectionProtocol->connect();
    }

    /**
     * Deletes the TURN allocation and closes the connection.
     *
     * This method sends a request to deallocate the previously allocated resources and then closes the connection.
     * It also logs the successful deletion of the allocation.
     *
     * @return void
     * @throws RandomException
     * @throws Throwable
     */
    public function delete(): void
    {
        $this->connectionProtocol->delete();
    }

    /**
     * Send the data bytes to the remote peer at the given address.
     * This will bind a TURN channel as necessary.
     *
     * @param string $data The data to send.
     * @param string|null $remoteAddress The remote address as string.
     * @throws RandomException
     */
    public function send(string $data, ?string $remoteAddress = null): void
    {
        $this->connectionProtocol->sendData($data, $remoteAddress);
    }

    /**
     * Close the transport.
     * After the TURN allocation has been deleted, the protocol's
     * connection_lost() method will be called with None as its argument.
     *
     * @return void
     * @throws RandomException
     * @throws Throwable
     */
    public function close(): void
    {
        $this->delete();
    }

    /**
     * End the connection gracefully.
     *
     * @return void
     */
    public function end(): void
    {
        $this->connectionProtocol->end();
    }

    /**
     * Resume the connection.
     *
     * @return void
     */
    public function resume(): void
    {
        $this->connectionProtocol->resume();
    }

    /**
     * Pause the connection.
     *
     * @return void
     */
    public function pause(): void
    {
        $this->connectionProtocol->pause();
    }

    /**
     * Get the local address.
     *
     * @return string The local address.
     */
    public function getLocalAddress(): string
    {
        return $this->connectionProtocol->getLocalAddress();
    }

    /**
     * Get the local host.
     *
     * @return string The local address.
     */
    public function getLocalHost(): string
    {
        return $this->connectionProtocol->getLocalHost();
    }

    /**
     * Get the local host.
     *
     * @return int The local address.
     */
    public function getLocalPort(): int
    {
        return $this->connectionProtocol->getLocalPort();
    }

    /**
     * Get the remote address.
     *
     * @return ?string The remote address.
     */
    public function getRemoteAddress(): ?string
    {
        return $this->connectionProtocol->getRemoteAddress();
    }

    /**
     * @return RTCIceCandidate
     */
    public function getCandidate(): RTCIceCandidate
    {
        return $this->connectionProtocol->getCandidate();
    }

    /**
     * @param RTCIceCandidate $candidate
     * @return void
     */
    public function setCandidate(RTCIceCandidate $candidate): void
    {
        $this->connectionProtocol->setCandidate($candidate);
    }

    /**
     * Send a STUN message.
     *
     * @param MessageInterface $message
     * @param ?string $address
     * @return void
     */
    public function sendMessage(MessageInterface $message, ?string $address): void
    {
        $this->connectionProtocol->sendMessage($message, $address);
    }

    /**
     * Execute a STUN transaction and return the response.
     *
     * @param MessageInterface $message
     * @param ?string $address
     * @param ?string $integrity_key
     * @param int $retransmissions
     * @return PromiseInterface
     */
    public function request(MessageInterface $message, ?string $address, ?string $integrity_key, int $retransmissions = 0): PromiseInterface
    {
        return $this->connectionProtocol->request($message, $address, $integrity_key, $retransmissions);
    }

    /**
     * Protocol ID
     *
     * @return string
     */
    public function getId(): string
    {
        return $this->connectionProtocol->getId();
    }

    /**
     * Gets relayed address
     *
     * @return ?array
     */
    function getRelayedAddress(): ?array
    {
        return $this->connectionProtocol->getRelayedAddress();
    }

    /**
     * Gets relayed host
     *
     * @return string
     */
    function getRelayedHost(): string
    {
        return $this->connectionProtocol->getRelayedAddress()[0];
    }

    /**
     * Gets relayed port
     *
     * @return string
     */
    function getRelayedPort(): string
    {
        return $this->connectionProtocol->getRelayedAddress()[1];
    }

    /**
     * Create an instance of TURN object based on the configuration provided
     *
     * @param TurnConfigurationInterface $configuration
     * @param ReceiverInterface $receiver
     * @param LoggerInterface|null $logger
     * @return Turn
     */
    public static function create(TurnConfigurationInterface $configuration, ReceiverInterface $receiver, ?LoggerInterface $logger = null): Turn
    {
        $turnConnection = $configuration->getTurnTransport() === "tcp" ?
            TurnTcpConnection::create($configuration, $receiver, $logger) : TurnUdpConnection::create($configuration, $receiver, $logger);

        return new static($turnConnection);
    }
}

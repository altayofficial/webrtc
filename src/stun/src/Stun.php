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

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Random\RandomException;
use React\Datagram\Socket;
use React\Datagram\SocketInterface;
use React\EventLoop\Loop;
use Throwable;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;
use Webrtc\STUN\Enum\MessageClass;
use Webrtc\STUN\Message\Message;
use Webrtc\STUN\Message\MessageInterface;
use Webrtc\STUN\Trait\Request;

/**
 * STUN (Session Traversal Utilities for NAT) Protocol Implementation
 *
 * Handles STUN protocol operations including message encoding/decoding,
 * transaction management, and communication with STUN servers.
 *
 * @implements StunInterface
 */
class Stun extends Datagram implements StunInterface
{
    use Request;

    /** @var string Unique identifier for this STUN instance */
    private string $id;

    /**
     * Constructor
     *
     * @param ReceiverInterface $receiver Message receiver handler
     * @param SocketInterface $socket Datagram socket interface
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     */
    public function __construct(
        private readonly ReceiverInterface $receiver,
        SocketInterface $socket,
        private readonly ?LoggerInterface $logger = null
    ) {
        parent::__construct($socket);
        $this->id = Uuid::uuid4()->toString();
    }


    /**
     * Handle received messages
     *
     * Processes incoming STUN messages or forwards non-STUN data to receiver
     *
     * @param string $data The received raw data
     * @param string $peerAddress The peer's IP address and port
     * @return void
     * @throws RandomException
     */
    protected function onReceived(string $data, string $peerAddress): void
    {
        if ($message = $this->decodeMessage($data)) {
            $this->handleMessage($message, $peerAddress, $data);
        } else {
            $this->receiver->onDataReceived($data, $this->getCandidate()->getComponentId());
        }
    }

    /**
     * Decode a STUN message from raw data
     *
     * @param string $data Raw message data to decode
     * @return MessageInterface|false Decoded message object or false on failure
     * @throws RandomException
     */
    private function decodeMessage(string $data): MessageInterface|bool
    {
        try {
            return Message::decode($data);
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Process a decoded STUN message
     *
     * Handles different message types (requests, responses, errors) appropriately
     *
     * @param MessageInterface $message The decoded STUN message
     * @param string $address Peer address
     * @param string $data Raw message data
     * @return void
     */
    private function handleMessage(MessageInterface $message, string $address, string $data): void
    {
        $this->logger?->info(
            "A new STUN message has been received",
            [
                "Message" => $message->humanReadable(),
                "FromAddress" => $address
            ]
        );

        $messageClass = $message->getMessageClass();
        $transactionId = $message->getTransactionId();

        if (in_array($messageClass, [MessageClass::RESPONSE, MessageClass::ERROR])
            && isset($this->transactionIds[$transactionId])) {
            $transaction = $this->transactionIds[$transactionId];
            $transaction->responseReceived($message, $address);
        } elseif ($messageClass === MessageClass::REQUEST) {
            $this->receiver->onRequestReceived($message, $address, $this, $data);
        }
    }

    /**
     * Handle socket errors
     *
     * @param Throwable $e The thrown exception
     * @return void
     */
    protected function onError(Throwable $e): void
    {
        $this->receiver->onError($e);
    }

    /**
     * Handle socket close events
     *
     * @return void
     */
    protected function onClose(): void
    {
        $this->receiver->onClose();
    }

    /**
     * Get the unique STUN instance ID
     *
     * @return string UUID string identifier
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Create a new STUN instance
     *
     * Factory method to create and bind a new STUN server instance
     *
     * @param ReceiverInterface $receiver Message receiver handler
     * @param string $host Host address to bind to
     * @param LoggerInterface|null $logger Optional PSR-3 logger
     * @param array|null $portRange
     * @return StunInterface
     */
    public static function create(
        ReceiverInterface $receiver,
        string $host,
        ?LoggerInterface $logger = null,
        ?array $portRange = null
    ): StunInterface {
        $port = 0;
        if ($portRange) {
            $port = self::getRandomPort($portRange, $host);
        }

        // Binding a UDP socket to a literal address needs no name resolution, so it is done directly
        // instead of through the promise based factory. await() would run the event loop nested
        // inside whatever callback triggered gathering, and a nested run can consume the outer
        // driver's pending stop callback - which aborts the await before the socket ever resolves.
        $address = "udp://$host:$port";
        $stream = @stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND);
        if ($stream === false) {
            throw new RuntimeException(sprintf("Could not bind to %s - %s", "$host:$port", $errstr), $errno);
        }

        try {
            return new static($receiver, new Socket(Loop::get(), $stream), $logger);
        } catch (Throwable $e) {
            @fclose($stream);
            throw new RuntimeException(
                sprintf("Could not bind to %s - %s", "$host:$port", $e->getMessage()),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get a random available port within a given range
     *
     * @param array $portRange Array containing [minPort, maxPort]
     * @param string $host Host address to test binding
     * @return int
     * @throws RuntimeException If no available port is found
     */
    private static function getRandomPort(array $portRange, string $host): int
    {
        [$min, $max] = $portRange;

        if ($min < 1 || $max > 65535 || $min > $max) {
            throw new InvalidArgumentException("Invalid port range [$min, $max]");
        }

        $ports = range($min, $max);
        shuffle($ports);

        foreach ($ports as $port) {
            $socket = @stream_socket_server("udp://$host:$port", $errno, $errstr);
            if ($socket) {
                fclose($socket);
                return $port;
            }
        }

        throw new RuntimeException("No available ports found in range [$min, $max]");
    }
}
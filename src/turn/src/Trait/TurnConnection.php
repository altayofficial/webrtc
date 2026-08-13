<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\TURN\Trait;

use Exception;
use Random\RandomException;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Throwable;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\STUN\Enum\MessageAttribute;
use Webrtc\STUN\Enum\MessageClass;
use Webrtc\STUN\Enum\MessageMethod;
use Webrtc\STUN\Exception\TransactionException;
use Webrtc\STUN\Exception\TransactionExceptionInterface;
use Webrtc\STUN\Message\Message;
use Webrtc\STUN\Message\MessageInterface;
final class TurnConnectionConstants
{
    /**
     * @var int
     */
    public const UDP_TRANSPORT = 0x11000000;
    /**
     * @var int
     */
    public const TCP_TRANSPORT = 0x06000000;
}

/**
 * This trait represents a TURN connection.
 *
 * TURN (Traversal Using Relays around NAT) is a protocol that allows clients
 * behind firewalls or Network Address Translators (NATs) to communicate with each other.
 */
trait TurnConnection
{
    /**
     * @var int The lifetime of the connection in seconds.
     */
    private int $lifetime = 600;

    /**
     * @var ?string The integrity key to sign the package.
     */
    private ?string $integrityKey = null;

    /**
     * @var ?array The relayed address of the connection[Host, Port].
     */
    private ?array $relayedAddress = null;

    /**
     * @var string The nonce used for authentication.
     */
    private string $nonce;

    /**
     * @var ?string The realm for authentication (if provided by the server).
     */
    private ?string $realm = null;

    /**
     * @var ?TimerInterface The timer used for refreshing the connection.
     */
    private ?TimerInterface $refreshPeriodicTimer = null;

    /**
     * @var array An associative array to store waiters for peer connection.
     * Key: peer address, Value: array of Deferred objects.
     */
    private array $peerConnectWaiters = [];

    /**
     * @var array An associative array to map peers to channels.
     * Key: peer address, Value: channel number.
     */
    private array $peerToChannel = [];

    /**
     * @var array An associative array to map channels to peers.
     * Key: channel number, Value: peer address.
     */
    private array $channelToPeer = [];

    /**
     * @var int The starting and ending range for channel numbers (inclusive).
     */
    private int $channelNumber = 0x4000; // to 0x7FFF (reference: https://datatracker.ietf.org/doc/html/rfc5766#section-11)

    /**
     * @var int The refresh time for channels in seconds.
     */
    private int $channelRefreshTime = 500;

    /**
     * @var array An associative array to store channel refresh timestamps.
     * Key: channel number, Value: timestamp (seconds since epoch).
     */
    private array $channelRefreshAt = [];

    /**
     * @var ?LoopInterface An instance of the loop interface (optional).
     */
    private ?LoopInterface $_loop = null;

    private array $sendQueue = [];
    private bool $isProcessing = false;

    /**
     * Get the lifetime of the connection.
     *
     * @return int The lifetime in seconds.
     */
    public function getLifetime(): int
    {
        return $this->lifetime;
    }

    /**
     * Set the lifetime of the connection.
     *
     * @param int $lifetime The lifetime in seconds.
     * @throws \InvalidArgumentException if the lifetime is less than 0.
     */
    public function setLifetime(int $lifetime): void
    {
        if ($lifetime < 0) {
            throw new \InvalidArgumentException('Lifetime cannot be less than 0');
        }

        $this->lifetime = $lifetime;
    }

    /**
     * Binds a channel to a peer address.
     *
     * @param int $channelNumber The channel number.
     * @param string $address The peer address.
     * @return PromiseInterface A promise that resolves when the channel is bound.
     * @throws RandomException
     */
    private function channelBind(int $channelNumber, string $address): PromiseInterface
    {
        $deferred = new Deferred();
        $messageAttr = [
            MessageAttribute::CHANNEL_NUMBER->name => $channelNumber,
            MessageAttribute::XOR_PEER_ADDRESS->name => explode(":", $address)
        ];
        $message = Message::new(MessageClass::REQUEST, MessageMethod::CHANNEL_BIND, $messageAttr);

        $this->requestWithRetry($message)->then(
            function () use ($deferred): void {
                $deferred->resolve(null);
            },
            function ($e) use ($deferred): void {
                $deferred->reject($e);
            }
        );

        return $deferred->promise();
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
        $deferred = new Deferred();

        $messageAttr = [
            MessageAttribute::LIFETIME->name => $this->lifetime,
            MessageAttribute::REQUESTED_TRANSPORT->name => \Webrtc\TURN\Trait\TurnConnectionConstants::UDP_TRANSPORT
        ];
        $message = Message::new(MessageClass::REQUEST, MessageMethod::ALLOCATE, $messageAttr);

        $this->requestWithRetry($message)
            ->then(
                function (array $response) use ($deferred) {
                    $message = $response[0];
                    if ($message instanceof Message) {
                        $timeToExpiry = $message->attributes()->get(MessageAttribute::LIFETIME);
                        $this->relayedAddress = $message->attributes()->get(MessageAttribute::XOR_RELAYED_ADDRESS);
                    }

                    return $timeToExpiry ?? null;
                })
            ->then(function (?int $timeToExpiry) use ($deferred): void {
                if ($timeToExpiry) {
                    $this->refreshPeriodicTimer = $this->_loop->addPeriodicTimer($timeToExpiry * 5 / 6, function (): void {
                        $this->refresh();
                    });
                }

                $deferred->resolve($this->relayedAddress);
            })
            ->catch(function (Exception $e) use ($deferred): void {
                $deferred->reject($e);
            });

        return $deferred->promise();
    }

    /**
     * Gets relayed address
     *
     * @return ?array
     */
    public function getRelayedAddress(): ?array
    {
        return $this->relayedAddress;
    }

    /**
     * Handles errors that occur during transmission.
     *
     * This method logs the error message and forwards it to the receiver for further handling.
     *
     * @param Throwable $e The exception object representing the error.
     * @return void
     * @throws RandomException
     * @throws Throwable
     */
    protected function onError(Throwable $e): void
    {
        $this->logger?->error("An error occurred while transmitting", ["ErrorMessage" => $e->getMessage()]);
        $this->receiver->onError($e);
        $this->delete();
    }

    /**
     * Called when the connection is closed.
     *
     * This method logs the closure event and informs the receiver about the closed connection.
     *
     * @return void
     * @throws RandomException
     * @throws Throwable
     */
    protected function onClose(): void
    {
        $this->logger?->debug("The connection has been closed", ["Address" => $this->getLocalAddress()]);
        $this->receiver->onClose();
        @$this->delete();
    }

    /**
     * Called when the connection is ended (similar to onClose).
     *
     * This method logs the ending event and informs the receiver about the ended connection.
     * The behavior is similar to `onClose` but might be used for specific purposes depending on the implementation.
     *
     * @return void
     */
    protected function onEnded(): void
    {
        $this->logger?->debug("The connection has been Ended", ["Address" => $this->getLocalAddress()]);
        $this->receiver->onClose();
    }

    /**
     * Handles received messages.
     *
     * This method parses received data and determines the message type based on its format.
     * It then calls appropriate methods for further handling depending on the message class and transaction ID.
     *
     * @param string $data The received data.
     * @param string|null $peerAddress The address of the peer who sent the message.
     * @return void
     * @throws RandomException
     */
    public function onReceived(string $data, ?string $peerAddress): void
    {
        if (strlen($data) >= 4 && $this->isChannelData($data)) {
            [$channel, $length] = array_values(unpack("nChannel/nLength", substr($data, 0, 4)));
            if (strlen($data) >= $length + 4 && $peerAddress = $this->channelToPeer[$channel] ?? null) {
                $payload = substr($data, 4, $length);
                if ($message = $this->decodeMessage($payload)) {
                    $this->handleMessage($message, $peerAddress, $data);
                } else {
                    $this->receiver->onDataReceived($payload, $this->getCandidate()?->getComponentId() ?? 0);
                }
            }

            return;
        }

        if ($message = $this->decodeMessage($data)) {
            $messageClass = $message->getMessageClass();
            $transactionId = $message->getTransactionId();

            if (in_array($messageClass, [MessageClass::RESPONSE, MessageClass::ERROR]) && isset($this->transactionIds[$transactionId])) {
                $transaction = $this->transactionIds[$transactionId];
                $transaction->responseReceived($message, $peerAddress);
            }elseif ($messageClass === MessageClass::REQUEST) {
                $this->receiver->onRequestReceived($message, $peerAddress, $this, $data);
            }
        }
    }

    /**
     * Attempts to decode a received message.
     *
     * This method tries to decode the provided data into a MessageInterface object.
     * It returns the decoded message on success or `false` if the decoding fails.
     *
     * @param string $data The data to be decoded.
     * @return MessageInterface|false The decoded message object or `false` if decoding fails.
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
     * Handles a newly received message.
     *
     * This method takes a decoded message object and processes it based on its class and transaction ID.
     * It performs actions like updating internal state, notifying the receiver, or handling errors.
     *
     * @param MessageInterface $message The decoded message object.
     * @param string $address The address of the peer who sent the message.
     * @param string $data The raw received data (might be used for additional processing).
     * @return void
     */
    private function handleMessage(MessageInterface $message, string $address, string $data): void
    {

        $this?->logger->info("A new TURN message has been received", ["Message" => $message->humanReadable(), "FromAddress" => $address]);

        $messageClass = $message->getMessageClass();
        $transactionId = $message->getTransactionId();

        if (in_array($messageClass, [MessageClass::RESPONSE, MessageClass::ERROR]) && isset($this->transactionIds[$transactionId])) {
            $transaction = $this->transactionIds[$transactionId];
            $transaction->responseReceived($message, $address);
        } elseif ($messageClass === MessageClass::REQUEST) {
            $this->receiver->onRequestReceived($message, $address, $this, $data);
        }
    }

    /**
     * Checks if the received data is formatted as a ChannelData message.
     *
     * This method analyzes the first byte of the data to determine if it follows the ChannelData message format.
     * It returns `true` if the format matches and `false` otherwise.
     *
     * @param string $data The received data.
     * @return bool Whether the data is formatted as a ChannelData message.
     * @link https://datatracker.ietf.org/doc/html/rfc5766#section-2.5 (RFC 5766 - ChannelData message format)
     */
    private function isChannelData(string $data): bool
    {
        return (ord($data[0]) & 0xC0) == 0x40;
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
        if ($this->refreshPeriodicTimer) {
            Loop::cancelTimer($this->refreshPeriodicTimer);
            $this->refreshPeriodicTimer = null;
        }

        $messageAttr = [
            MessageAttribute::LIFETIME->name => 0
        ];
        $message = Message::new(MessageClass::REQUEST, MessageMethod::REFRESH, $messageAttr);
        $this->requestWithRetry($message)->then(function () {
            $this->logger?->info("TURN allocation deleted", ["RelayedAddress" => $this->relayedAddress]);
            $this->close();
        })->catch(function () {
            $this->logger?->error("Could not TURN allocation deleted", ["RelayedAddress" => $this->relayedAddress]);
            $this->close();
        });

    }

    /**
     * Refreshes the TURN allocation.
     *
     * This method sends a request to refresh the lifetime of the current TURN allocation.
     * It updates the internal state and logs the successful refresh with the new expiry time.
     * In case of errors, it logs the failure with
     *
     * @return void
     * @throws RandomException
     */
    private function refresh(): void
    {
        $messageAttr = [
            MessageAttribute::LIFETIME->name => $this->lifetime
        ];
        $message = Message::new(MessageClass::REQUEST, MessageMethod::REFRESH, $messageAttr);

        $this->requestWithRetry($message)->then(
            function ($response): void {
                $message = $response[0];
                if ($message instanceof Message) {
                    $timeToExpiry = $message->attributes()->get(MessageAttribute::LIFETIME);
                    $this->logger->info("TURN allocation refreshed", ["RelatedAddress" => $this->relayedAddress, "ExpiresInSeconds" => $timeToExpiry]);
                }
            },
            function (Throwable $e): void {
                $this->logger->error("TURN allocation refreshed failed", ["RelatedAddress" => $this->relayedAddress, "ErrorMessage" => $e->getMessage()]);
            }
        );
    }

    /**
     * Sends a request with retry logic for authentication errors.
     *
     * This method sends the given message and handles potential authentication failures. If an authentication error occurs, it updates the long-term credentials and retries the request with the updated credentials.
     *
     * @param MessageInterface $message The message to be sent.
     * @return PromiseInterface A promise that resolves to the response or rejects with an error.
     */
    private function requestWithRetry(MessageInterface $message): PromiseInterface
    {
        $deferred = new Deferred();
        $this->addAuthenticatedAttributes($message);
        $this->request($message, null, $this->integrityKey)->then(
            function ($response) use ($deferred): void {
                $deferred->resolve($response);
            },
            function (TransactionExceptionInterface $e) use ($message, $deferred): void {
                $this->handleRetryRequestError($e, $message, $deferred);
            }
        );

        return $deferred->promise();
    }

    /**
     * If an authentication error occurs, it updates the long-term credentials and retries the request with the updated credentials.
     *
     * @param TransactionExceptionInterface $error
     * @param MessageInterface $message
     * @param Deferred $deferred
     * @return void
     * @throws RandomException
     */
    private function handleRetryRequestError(TransactionExceptionInterface $error, MessageInterface $message, Deferred $deferred): void
    {
        $errorCode = $error->getStunMessage()?->attributes()->get(MessageAttribute::ERROR_CODE)[0];

        if ($this->configuration->getTurnUsername() !== null &&
            $this->configuration->getTurnPassword() !== null &&
            $error->getStunMessage()?->attributes()->has(MessageAttribute::NONCE) &&
            ($errorCode === 401 || ($errorCode === 438 && $this->realm !== null))) {
            // Update long-term credentials
            $this->nonce = $error->getStunMessage()->attributes()->get(MessageAttribute::NONCE);
            if ($errorCode == 401) {
                $this->realm = $error->getStunMessage()->attributes()->get(MessageAttribute::REALM);
            }

            // Retry request with authentication
            $message->setTransactionId(random_bytes(12));
            $this->makeIntegrityKey();
            $this->addAuthenticatedAttributes($message);

            $this->request($message, null, $this->integrityKey)->then(function ($response) use ($deferred): void {
                $deferred->resolve($response);
            }, function (TransactionExceptionInterface $e) use ($deferred): void {
                $this->logger?->error("Failed to request with retry: {$e->getMessage()}");

                $deferred->reject(new TransactionException("Failed to request with retry: {$e->getMessage()}", $e->getCode(), $e));
            });
        } else {
            $this->logger?->error("Error processing request: {$error->getMessage()}");
            $deferred->reject($error);
        }
    }

    /**
     * @param MessageInterface $message
     * @return void
     */
    private function addAuthenticatedAttributes(MessageInterface $message): void
    {
        if ($this->integrityKey) {
            $messageAttr = [
                MessageAttribute::USERNAME->name => $this->configuration->getTurnUsername(),
                MessageAttribute::NONCE->name => $this->nonce,
                MessageAttribute::REALM->name => $this->realm
            ];
            $message->attributes()->merge($messageAttr);
        }
    }

    /**
     * Creates an integrity key for authentication.
     *
     * This method generates an MD5 hash of the username, realm, and password to create the integrity key.
     *
     * @return void The generated integrity key.
     */
    private function makeIntegrityKey(): void
    {
        $this->integrityKey = md5(implode(":", [$this->configuration->getTurnUsername(), $this->realm, $this->configuration->getTurnPassword()]), true);
    }

    /**
     * Sends data to a specific address.
     *
     * This method handles sending data by using channels. It first checks if a channel is already bound for the peer. If not, it binds a new channel and updates the internal state. If the channel needs refreshing, it rebinds the channel. Finally, it sends the data using the established channel.
     *
     * @param string $data The data to be sent.
     * @param string $addr The address of the recipient.
     * @return PromiseInterface
     * @throws RandomException
     */
    public function sendData(string $data, string $addr): PromiseInterface
    {
        $deferred = new Deferred();
        $this->sendQueue[] = [$data, $addr, $deferred];

        if (!$this->isProcessing) {
            $this->isProcessing = true;
            $this->processQueue();
        }

        return $deferred->promise();
    }


    /**
     * @return void
     * @throws RandomException
     */
    private function processQueue(): void
    {
        if (empty($this->sendQueue)) {
            $this->isProcessing = false;
            return;
        }

        [$data, $addr, $deferred] = array_shift($this->sendQueue);
        $now = time();
        $channel = $this->peerToChannel[$addr] ?? null;

        if ($channel === null) {
            $this->peerConnectWaiters[$addr] = [];
            $channel = $this->channelNumber++;

            $this->channelBind($channel, $addr)->then(function () use ($channel, $addr, $now, $data, $deferred) {
                $this->channelRefreshAt[$channel] = $now + $this->channelRefreshTime;
                $this->channelToPeer[$channel] = $addr;
                $this->peerToChannel[$addr] = $channel;

                foreach ($this->peerConnectWaiters[$addr] as $waiter) {
                    $waiter->resolve(null);
                }
                unset($this->peerConnectWaiters[$addr]);

                $this->sendPacket($channel, $data);
                $deferred->resolve(null);

                $this->processQueue();
            });
        } elseif ($now > ($this->channelRefreshAt[$channel] ?? 0)) {
            $this->channelBind($channel, $addr)->then(function () use ($channel, $now, $data, $deferred) {
                $this->channelRefreshAt[$channel] = $now + $this->channelRefreshTime;

                $this->sendPacket($channel, $data);
                $deferred->resolve(null);

                $this->processQueue();
            });
        } else {
            $this->sendPacket($channel, $data);
            $deferred->resolve(null);

            $this->processQueue();
        }
    }

    /**
     * @param int $channel
     * @param string $data
     * @return void
     */
    private function sendPacket(int $channel, string $data): void
    {
        $header = pack("nn", $channel, strlen($data));
        $this->send($header . $data);
    }
}
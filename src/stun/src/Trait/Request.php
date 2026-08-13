<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\STUN\Trait;

use React\Promise\PromiseInterface;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\STUN\Message\MessageInterface;
use Webrtc\STUN\Transaction;

/**
 * Trait Request
 *
 * Provides functionality for sending STUN/TURN messages and handling transactions
 * Includes methods for message transmission and request/response handling
 */
trait Request
{
    /** @var array<string, Transaction> Map of transaction IDs to Transaction objects */
    public array $transactionIds = [];

    /**
     * Sends a STUN/TURN message to a specified address
     *
     * @param MessageInterface $message The message to send
     * @param string|null $address The target address (null uses default remote address)
     * @return void
     */
    public function sendMessage(MessageInterface $message, ?string $address): void
    {
        $this->logger?->debug("Send a STUN/TURN Message", ["Message" => $message->humanReadable(), "ToAddress" => $address ?? $this->remoteAddress]);
        $this->send((string)$message, $address);
    }

    /**
     * Initiates a STUN/TURN request transaction
     *
     * @param MessageInterface $message The request message to send
     * @param string|null $address The target address (optional)
     * @param string|null $integrityKey The key for message integrity (optional)
     * @param int $retransmissions Number of retransmission attempts (default: 0)
     * @return PromiseInterface Promise that resolves when transaction completes
     * @throws InvalidArgumentException If the message transaction is already pending
     */
    public function request(
        MessageInterface $message,
        ?string $address = null,
        ?string $integrityKey = null,
        int $retransmissions = 0
    ): PromiseInterface {
        if (isset($this->transactionIds[$message->getTransactionId()])) {
            throw new InvalidArgumentException("The message is in pending");
        }

        if (isset($integrityKey)) {
            $message->addMessageIntegrity($integrityKey);
        }

        $transaction = new Transaction($message, $address, $this, $retransmissions);
        $this->transactionIds[$message->getTransactionId()] = $transaction;

        return $transaction->execute();
    }
}
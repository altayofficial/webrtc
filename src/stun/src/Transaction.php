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

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Webrtc\STUN\Enum\MessageClass;
use Webrtc\STUN\Exception\TransactionFailedException;
use Webrtc\STUN\Exception\TransactionTimeoutException;
use Webrtc\STUN\Message\Message;
use Webrtc\STUN\Message\MessageInterface;

/**
 * Class representing a STUN transaction.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc5389#section-7.2 Sending the Request or Indication
 */
class Transaction implements TransactionInterface
{
    /**
     * @var int
     */
    private const RETRY_MAX = 5;
    /**
     * @var float
     */
    private const RETRY_RTO = 0.5;
    private ?string $address;
    private Deferred $deferred;
    private MessageInterface $message;
    private float $timeoutDelay;
    private BaseProtocolInterface $transport;
    private int $tries = 0;
    private int $triesMax;
    private LoopInterface $loop;
    private bool $isResolvedOrReject = false;

    /**
     * Transaction constructor.
     *
     * @param Message $message The STUN request message.
     * @param ?string $address The destination address as [host, port].
     * @param BaseProtocolInterface $transport The protocol object.
     * @param int|null $retransmissions Number of retransmissions, default is RETRY_MAX.
     */
    public function __construct(MessageInterface $message, ?string $address, BaseProtocolInterface $transport, ?int $retransmissions = null)
    {
        $this->address = $address;
        $this->deferred = new Deferred();
        $this->message = $message;
        $this->timeoutDelay = self::RETRY_RTO;
        $this->transport = $transport;
        $this->triesMax = 1 + ($retransmissions ?? self::RETRY_MAX);
        $this->loop = Loop::get();
    }

    /**
     * Handle the received response.
     *
     * @param MessageInterface $message The response message.
     * @param ?string $address The source address as [host, port].
     */
    public function responseReceived(MessageInterface $message, ?string $address): void
    {
        if (!$this->isResolvedOrReject) {
            $this->isResolvedOrReject = true;
            unset($this->transport->transactionIds[$message->getTransactionId()]);
            if ($message->getMessageClass() === MessageClass::RESPONSE) {
                $this->deferred->resolve([$message, $address]);
            } else {
                $this->deferred->reject(new TransactionFailedException($message));
            }
        }
    }

    /**
     * Run the transaction.
     *
     * @return PromiseInterface The promise resolving the transaction result.
     */
    public function execute(): PromiseInterface
    {
        $this->trySend();
        return $this->deferred->promise();
    }

    /**
     * Retry the transaction.
     */
    private function trySend(): void
    {
        if ($this->tries >= $this->triesMax) {
            $this->deferred->reject(new TransactionTimeoutException);
            return;
        }

        $this->transport->sendMessage($this->message, $this->address);
        $this->loop->addTimer($this->timeoutDelay, function (): void {
            if (!$this->isResolvedOrReject) {
                $this->trySend();
            }
        });

        $this->timeoutDelay *= 2;
        $this->tries += 1;
    }

    /**
     * @return Deferred
     */
    public function getDeferred(): Deferred
    {
        return $this->deferred;
    }
}
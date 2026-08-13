<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\SCTP;

use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Webrtc\Exception\RuntimeException;
use Webrtc\SCTP\Chunk\Chunk;
use Webrtc\SCTP\Enum\State;

/**
 * SCTP Timer management class.
 *
 * This class handles retransmission timers for SCTP chunks, implementing the
 * retransmission timeout (RTO) mechanism as defined in RFC 4960.
 *
 * Features:
 * - Manages retransmission timers for reliable chunk delivery
 * - Implements exponential backoff for retransmissions
 * - Tracks retransmission attempts and enforces maximum retry limits
 * - Integrates with ReactPHP event loop for timer management
 * - Provides logging capabilities for debugging timer operations
 */
class SctpTimer
{
    /** @var Chunk|null The chunk being tracked by this timer */
    private ?Chunk $chunk = null;

    /** @var LoopInterface The event loop instance for timer management */
    private LoopInterface $loop;

    /** @var TimerInterface|null The active timer instance */
    private ?TimerInterface $task = null;

    /** @var int Count of failed transmission attempts */
    private int $failures = 0;

    /**
     * Constructor.
     *
     * @param RTCSctpTransport $transport The SCTP transport instance this timer belongs to
     * @param int $maxTries Maximum number of retransmission attempts before giving up
     * @param LoggerInterface|null $logger Optional logger for debugging messages
     */
    public function __construct(
        private readonly RTCSctpTransport $transport,
        private readonly int              $maxTries,
        private readonly ?LoggerInterface $logger = null
    ) {
        $this->loop = Loop::get();
    }

    /**
     * Starts the timer for a chunk.
     *
     * @param Chunk $chunk The chunk to track with this timer
     * @throws RuntimeException If the timer is already running
     */
    public function start(Chunk $chunk): void
    {
        if ($this->task) {
            throw new RuntimeException('Task already started');
        }
        $this->chunk = $chunk;
        $this->log("it started -> chunk: " . get_class($this->chunk));
        $this->task = $this->loop->addTimer($this->transport->getRto(), fn() => $this->expired());
    }

    /**
     * Cancels the active timer.
     *
     * Clears the timer and resets the tracked chunk. Safe to call even if no timer is active.
     */
    public function cancel(): void
    {
        if ($this->task) {
            $this->log("it canceled -> chunk: " . get_class($this->chunk));
            $this->loop->cancelTimer($this->task);
            $this->task = null;
            $this->chunk = null;
        }
    }

    /**
     * Handles timer expiration event.
     *
     * This method is called when the timer expires. It either:
     * - Triggers chunk retransmission if under max retry limit, or
     * - Closes the transport if max retries are exceeded
     */
    public function expired(): void
    {
        $this->task = null;
        $this->log("it expired -> chunk: " . get_class($this->chunk));
        if ($this->failures >= $this->maxTries) {
            $this->transport->setState(State::CLOSED);
        } else {
            $this->loop->futureTick(fn() => $this->transport->sendChunk($this->chunk));
            $this->task = $this->loop->addTimer($this->transport->getRto(), fn() => $this->expired());
        }
        $this->failures++;
    }

    /**
     * Logs a debug message if logger is configured.
     *
     * @param string $message The message to log
     */
    private function log(string $message): void
    {
        $this->logger?->debug(sprintf('SCTP timer: %s', $message));
    }

    /**
     * Gets the current failure counts.
     *
     * @return int Number of transmission failures for the current chunk
     */
    public function getFailures(): int
    {
        return $this->failures;
    }

    /**
     * Sets the failure count.
     *
     * @param int $failures New failure count value
     */
    public function setFailures(int $failures): void
    {
        $this->failures = $failures;
    }

    /**
     * Gets the active timer instance.
     *
     * @return TimerInterface|null The active timer or null if not running
     */
    public function getTask(): ?TimerInterface
    {
        return $this->task;
    }

    /**
     * Sets the timer instance.
     *
     * Note: Generally prefer using start()/cancel() methods rather than
     * manually managing the timer instance.
     *
     * @param TimerInterface|null $task The timer instance to set
     */
    public function setTask(?TimerInterface $task): void
    {
        $this->task = $task;
    }
}
<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\DataChannel;

use Evenement\EventEmitter;
use Psr\Log\LoggerInterface;
use Webrtc\DataChannel\Enum\State;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\Exception\RuntimeException;

/**
 * Represents a bidirectional peer-to-peer data channel for WebRTC communications.
 *
 * The RTCDataChannel interface enables direct communication between peers with:
 * - Configurable reliability (ordered/unordered, retransmission policies)
 * - Flow control via buffered amounted monitoring
 * - Event-driven interface for state changes
 * - Support for both in-band and out-of-band negotiation
 *
 * Events:
 * - "open": Emitted when the data channel transitions to the open state
 * - "close": Emitted when the data channel transitions to the closed state
 * - "bufferedamountlow": Emitted when buffered data falls below the threshold
 *
 * @package Webrtc\DataChannel
 * @implements RTCDataChannelInterface
 */
class RTCDataChannel extends EventEmitter implements RTCDataChannelInterface
{
    private int $bufferedAmount = 0;
    private int $bufferedAmountLowThreshold = 0;
    private ?int $id;
    private RTCDataChannelParameters $parameters;
    private State $readyState = State::Connecting;
    private RTCSctpTransportInterface $transport;
    private bool $sendOpen;
    private ?LoggerInterface $logger = null;

    /**
     * Creates a new RTCDataChannel instance.
     *
     * The constructor handles both in-band and out-of-band negotiated channels:
     * - For in-band: Automatically initiates the opening procedure
     * - For out-of-band: Verifies ID validity and registers with transport
     *
     * @param RTCSctpTransportInterface $transport The SCTP transport layer for data transmission
     * @param RTCDataChannelParameters $parameters Configuration parameters for the channel
     * @param bool $sendOpen Whether to immediately send open request (for in-band)
     * @throws InvalidArgumentException If negotiated channel has invalid ID
     */
    public function __construct(
        RTCSctpTransportInterface         $transport,
        RTCDataChannelParameters $parameters,
        bool                     $sendOpen = true
    )
    {
        $this->transport = $transport;
        $this->parameters = $parameters;
        $this->id = $parameters->id;
        $this->sendOpen = $sendOpen;

        if ($this->parameters->negotiated && ($this->id === null || $this->id < 0 || $this->id > 65534)) {
            throw new InvalidArgumentException(
                "ID must be in range 0-65534 if data channel is negotiated out-of-band"
            );
        }
        if (!$this->parameters->negotiated) {
            if ($this->sendOpen) {
                $this->sendOpen = false;
                $this->transport->dataChannelOpen($this);
            }
        } else {
            $this->transport->dataChannelAddNegotiated($this);
        }
    }

    /**
     * Gets the current amount of buffered outgoing data in bytes.
     *
     * This represents data that has been queued but not yet transmitted.
     * Useful for implementing application-level flow control.
     *
     * @return int Bytes currently buffered (0 indicates empty buffer)
     */
    public function getBufferedAmount(): int
    {
        return $this->bufferedAmount;
    }

    /**
     * Gets the low threshold for buffered amount notifications.
     *
     * When the buffered amount crosses below this value after being above it,
     * a "bufferedamountlow" event will be emitted.
     *
     * @return int Current threshold value in bytes
     */
    public function getBufferedAmountLowThreshold(): int
    {
        return $this->bufferedAmountLowThreshold;
    }

    /**
     * Sets the low threshold for buffered amount notifications.
     *
     * @param int $value New threshold in bytes (0-4294967295)
     * @throws InvalidArgumentException If value is outside valid range
     */
    public function setBufferedAmountLowThreshold(int $value): void
    {
        if ($value < 0 || $value > 4294967295) {
            throw new InvalidArgumentException("bufferedAmountLowThreshold must be in range 0 - 4294967295");
        }
        $this->bufferedAmountLowThreshold = $value;
    }

    /**
     * Checks if the channel was negotiated out-of-band.
     *
     * Out-of-band negotiated channels skip the in-band opening procedure
     * and must have pre-arranged IDs.
     *
     * @return bool True if negotiated out-of-band, false for in-band
     */
    public function isNegotiated(): bool
    {
        return $this->parameters->negotiated;
    }

    /**
     * Gets the channel's unique identifier.
     *
     * The ID is assigned either:
     * - Automatically for in-band negotiated channels
     * - Explicitly for out-of-band negotiated channels
     *
     * @return int|null The channel ID (null if not yet assigned)
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Gets the channel's label.
     *
     * The label provides a human-readable identifier for the channel's purpose.
     *
     * @return string The channel label
     */
    public function getLabel(): string
    {
        return $this->parameters->label;
    }

    /**
     * Checks if the channel guarantees message ordering.
     *
     * When ordered=true, messages are delivered in the order they were sent.
     * When ordered=false, messages may be delivered out of order.
     *
     * @return bool True if ordered delivery is enabled
     */
    public function isOrdered(): bool
    {
        return $this->parameters->ordered;
    }

    /**
     * Gets the maximum packet lifetime (in milliseconds).
     *
     * This specifies how long the implementation will attempt to transmit a message
     * before giving up (for unreliable channels).
     *
     * @return int|null Lifetime in ms or null if using default
     */
    public function getMaxPacketLifeTime(): ?int
    {
        return $this->parameters->maxPacketLifeTime;
    }

    /**
     * Gets the maximum number of retransmission attempts.
     *
     * This specifies how many times a message will be retransmitted before giving up
     * (for unreliable channels).
     *
     * @return int|null Maximum retransmits or null if using default
     */
    public function getMaxRetransmits(): ?int
    {
        return $this->parameters->maxRetransmits;
    }

    /**
     * Gets the application-specific subprotocol.
     *
     * The protocol string identifies the format/meaning of messages exchanged
     * over the channel (similar to WebSocket subprotocols).
     *
     * @return string The protocol identifier
     */
    public function getProtocol(): string
    {
        return $this->parameters->protocol;
    }

    /**
     * Gets the current connection state.
     *
     * Possible states:
     * - Connecting: Channel is being established
     * - Open: Ready for data transfer
     * - Closing: Channel is being closed
     * - Closed: Channel is fully closed
     *
     * @return State Current state enum value
     */
    public function getReadyState(): State
    {
        return $this->readyState;
    }

    /**
     * Gets the underlying SCTP transport.
     *
     * @return RTCSctpTransportInterface The transport instance
     */
    public function getTransport(): RTCSctpTransportInterface
    {
        return $this->transport;
    }

    /**
     * Initiates channel closure.
     *
     * This begins an orderly shutdown of the data channel.
     * The "close" event will be emitted when the shutdown is complete.
     */
    public function close(): void
    {
        $this->transport->dataChannelClose($this);
    }

    /**
     * Sends data through the channel.
     *
     * @param string $data The binary or text data to send
     * @throws RuntimeException If a channel is not in open state
     */
    public function send(string $data): void
    {
        if ($this->readyState != State::Open) {
            throw new RuntimeException("Data channel is not open");
        }

        $this->transport->dataChannelSend($this, $data);
    }

    /**
     * Updates the buffered amount and checks threshold crossing.
     *
     * The transport layer uses this internal method to:
     * 1. Track queued data
     * 2. Trigger "bufferedamountlow" events when appropriate
     *
     * @param int $amount The number of bytes to add
     */
    public function addBufferedAmount(int $amount): void
    {
        $crossesThreshold = (
            $this->bufferedAmount > $this->bufferedAmountLowThreshold &&
            $this->bufferedAmount + $amount <= $this->bufferedAmountLowThreshold
        );

        $this->bufferedAmount += $amount;

        if ($crossesThreshold) {
            $this->emit("bufferedamountlow");
        }
    }

    /**
     * Assigns the channel ID.
     *
     * Note: This should only be called by the transport layer during setup.
     *
     * @param int $id The channel identifier to assign
     */
    public function setId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * Updates the channel's ready state.
     *
     * This internal method handles state transitions and emits corresponding events:
     * - "open" when transitioning to Open state
     * - "close" when transitioning to Closed state
     *
     * @param State $state The new state to transition to
     */
    public function setReadyState(State $state): void
    {
        if ($state != $this->readyState) {
            $this->log(sprintf(" Change Datachannel(%s) state from %s to %s", $this->parameters->label, $this->readyState->name, $state->name));
            $this->readyState = $state;

            if ($state == State::Open) {
                $this->emit("open");
            } elseif ($state == State::Closed) {
                $this->emit("close");
                $this->removeAllListeners();
            }
        }
    }

    /**
     * @param string $msg
     * @return void
     */
    private function log(string $msg): void
    {
        $this->logger?->debug(sprintf("RTCDataChannel(%s) ", $msg));
    }

    /**
     * @return bool
     */
    public function getOrdered(): bool
    {
        return $this->parameters->ordered;
    }

    /**
     * Attaches a logger for debug output.
     *
     * @param LoggerInterface $logger The PSR-3 compatible logger instance
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }
}

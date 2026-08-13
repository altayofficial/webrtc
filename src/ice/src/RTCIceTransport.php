<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\ICE;

use Evenement\EventEmitter;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use Throwable;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\ICE\Enum\IceRole;
use Webrtc\ICE\Enum\IceTransportState;
use Webrtc\Mixin\EventForwarder;

/**
 * Class RTCIceTransport
 *
 * Handles ICE transport functionality including connection state,
 * sending data, and reacting to events emitted by the ICE connection.
 */
class RTCIceTransport extends EventEmitter implements RTCIceTransportInterface
{
    use EventForwarder;

    /**
     * @var IceTransportState Current transport state.
     */
    private IceTransportState $state = IceTransportState::new;

    /**
     * @var RTCIceConnectionInterface ICE connection instance.
     */
    private RTCIceConnectionInterface $iceConnection;

    /**
     * @var bool Whether the ICE role has been set.
     */
    private bool $roleSet = false;

    /**
     * RTCIceTransport constructor.
     *
     * @param RTCIceGathererInterface $iceGatherer The ICE gatherer instance.
     * @param LoggerInterface|null $logger Optional PSR-3 logger.
     */
    public function __construct(
        private readonly RTCIceGathererInterface $iceGatherer,
        private readonly ?LoggerInterface $logger = null
    ) {
        $this->iceConnection = $iceGatherer->getIceConnection();
        $this->forwardEvents($this->iceConnection, ["data"]);
        $this->forwardEvents2Methods($this->iceConnection, ["onClose" => 'failure']);
    }

    /**
     * Get the ICE gatherer instance.
     *
     * @return RTCIceGathererInterface
     */
    public function getIceGatherer(): RTCIceGathererInterface
    {
        return $this->iceGatherer;
    }

    /**
     * Get the ICE role.
     *
     * @return IceRole
     */
    public function getRole(): IceRole
    {
        return $this->iceConnection->getIceRole();
    }

    /**
     * Get the current ICE transport state.
     *
     * @return IceTransportState
     */
    public function getState(): IceTransportState
    {
        return $this->state;
    }

    /**
     * Add a remote ICE candidate.
     *
     * @param RTCIceCandidate $candidate
     * @return void
     */
    public function addRemoteCandidate(RTCIceCandidate $candidate): void
    {
        if (!$this->iceConnection->isRemoteCandidatesEnd()) {
            $this->iceConnection->addRemoteCandidate($candidate);
        }
    }

    /**
     * Get the list of all remote candidates.
     *
     * @return array
     */
    public function getRemoteCandidates(): array
    {
        return $this->iceConnection->getRemoteCandidates();
    }

    /**
     * Start the ICE transport with the given remote ICE parameters.
     *
     * @param RTCIceParameters $remoteIceParameters
     * @return PromiseInterface
     * @throws InvalidArgumentException If the transport is already closed.
     */
    public function start(RTCIceParameters $remoteIceParameters): PromiseInterface
    {
        if ($this->state === IceTransportState::closed) {
            throw new InvalidArgumentException("RTCIceTransport is closed");
        }

        $this->setState(IceTransportState::checking);

        $this->iceConnection->setRemoteIsLite($remoteIceParameters->iceLite);
        $this->iceConnection->setRemoteUsername($remoteIceParameters->usernameFragment);
        $this->iceConnection->setRemotePassword($remoteIceParameters->password);

        return $this->iceConnection->connect()
            ->then(fn() => $this->setState(IceTransportState::complete))
            ->catch(fn() => $this->setState(IceTransportState::failed));
    }

    /**
     * Gracefully shut down the ICE connection.
     *
     * @return void
     */
    public function stop(): void
    {
        if ($this->state === IceTransportState::closed) {
            return;
        }

        $this->setState(IceTransportState::closed);

        try {
            $this->iceConnection->close();
        } catch (Throwable) {
            // Silently catch errors to ensure graceful shutdown.
        }
    }

    /**
     * Forcefully stop the ICE connection without a graceful shutdown.
     *
     * @return void
     */
    public function stopIceConnection(): void
    {
        if ($this->state !== IceTransportState::closed) {
            $this->setState(IceTransportState::closed);
            $this->iceConnection->close();
        }
    }

    /**
     * Set the transport state and emit a statechange event.
     * Also removes listeners on shutdown to aid garbage collection.
     *
     * @param IceTransportState $state
     * @return void
     */
    private function setState(IceTransportState $state): void
    {
        if ($state !== $this->state) {
            $this->logger?->debug(sprintf(
                "Ice transport state has been changed from %s to %s",
                $this->state->name,
                $state->name
            ));

            $this->state = $state;
            $this->emit("statechange", [$state]);

            if ($state === IceTransportState::closed) {
                $this->iceGatherer->removeAllListeners();
                $this->removeAllListeners();
            }
        }
    }

    /**
     * Get the underlying ICE connection.
     *
     * @return RTCIceConnectionInterface
     */
    public function getIceConnection(): RTCIceConnectionInterface
    {
        return $this->iceConnection;
    }

    /**
     * Send data over the ICE connection.
     *
     * @param string $bytes
     * @return void
     */
    public function send(string $bytes): void
    {
        $this->iceConnection->sendData($bytes);
    }

    /**
     * Notify the connection that all remote candidates have been sent.
     *
     * @return void
     */
    public function endRemoteCandidate(): void
    {
        $this->iceConnection->endOfRemoteCandidate();
    }

    /**
     * Check if the ICE role has been set.
     *
     * @return bool
     */
    public function isRoleSet(): bool
    {
        return $this->roleSet;
    }

    /**
     * Mark the ICE role as a set.
     *
     * @param bool $roleSet
     * @return void
     */
    public function setRoleSet(bool $roleSet): void
    {
        $this->roleSet = $roleSet;
    }

    /**
     * Transition to the "failed" state if the connection was previously "complete".
     *
     * @return void
     */
    private function failure(): void
    {
        if ($this->state === IceTransportState::complete) {
            $this->setState(IceTransportState::failed);
        }
    }
}

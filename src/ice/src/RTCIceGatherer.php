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
use Random\RandomException;
use Throwable;
use Webrtc\ICE\Enum\IceGatheringState;

/**
 * The RTCIceGatherer is responsible for gathering ICE candidates on the local machine.
 *
 * This class initializes an ICE connection using a list of ICE servers and a specified role.
 * It controls the gathering process and emits events when the ICE gathering state changes.
 * It follows the structure outlined in the WebRTC specification.
 *
 * Events:
 * - `statechange`: Emitted when the ICE gathering state transitions.
 */
class RTCIceGatherer extends EventEmitter implements RTCIceGathererInterface
{
    private RTCIceConnectionInterface $iceConnection;
    private IceGatheringState $state = IceGatheringState::new;

    /**
     * Constructs a new RTCIceGatherer instance.
     *
     * Initializes ICE connection with the given ICE servers and role. Optionally accepts a logger.
     *
     * @param array $iceServes List of ICE server configurations.
     * @param ?RTCICESetting $setting The ICE settings.
     * @param LoggerInterface|null $logger Optional logger for debugging.
     *
     * @throws RandomException
     */
    public function __construct(private array $iceServes, ?RTCICESetting $setting = null, ?LoggerInterface $logger = null)
    {
        $protocolParser = new IceProtocolParser($iceServes);

        if (!$setting) {
            $setting = new RTCICESetting();
        }

        $this->iceConnection = new RTCIceConnection($protocolParser->getIceConnectionConfiguration(), $setting->getIceRole());
        $this->iceConnection->setIcePortRange($setting->getIcePortRange());
        $this->iceConnection->setTransportPolicy($setting->getTransportPolicy());
        $this->iceConnection->setRemoteIsLite($setting->isIceLite());
        $this->iceConnection->setNat1to1($setting->getNat1to1());

        if ($logger) {
            $this->iceConnection->setLogger($logger);
        }
    }

    /**
     * Returns the list of configured ICE servers.
     *
     * @return array The ICE server configuration.
     */
    public function getIceServes(): array
    {
        return $this->iceServes;
    }

    /**
     * Initiates the ICE candidate gathering process.
     *
     * Changes state from 'new' to 'gathering', then to 'complete' after gathering is finished.
     * Emits a 'statechange' event on state transitions.
     *
     * @return void
     * @throws Throwable
     *
     * @throws RandomException
     */
    public function gather(): void
    {
        if ($this->state === IceGatheringState::new) {
            $this->setState(IceGatheringState::gathering);
            $this->iceConnection->gatherCandidates();
            $this->setState(IceGatheringState::complete);
        }
    }

    /**
     * Returns the current ICE gathering state.
     *
     * @return IceGatheringState The state of ICE candidate gathering.
     */
    public function getState(): IceGatheringState
    {
        return $this->state;
    }

    /**
     * Sets the ICE gathering state and emits a `statechange` event.
     *
     * @param IceGatheringState $state The new ICE gathering state.
     *
     * @return void
     */
    public function setState(IceGatheringState $state): void
    {
        $this->state = $state;
        $this->emit("statechange", [$state]);
    }

    /**
     * Returns the list of local ICE candidates that have been gathered.
     *
     * @return array The gathered local candidates.
     */
    public function getLocalCandidates(): array
    {
        return $this->iceConnection->getLocalCandidates();
    }

    /**
     * Returns the local ICE parameters (username fragment and password).
     *
     * @return RTCIceParameters The local ICE credentials.
     */
    public function getLocalParameters(): RTCIceParameters
    {
        return new RTCIceParameters($this->iceConnection->getLocalUsername(), $this->iceConnection->getLocalPassword());
    }

    /**
     * Returns the ICE connection used internally for gathering and signaling.
     *
     * @return RTCIceConnectionInterface The ICE connection object.
     */
    public function getIceConnection(): RTCIceConnectionInterface
    {
        return $this->iceConnection;
    }

    /**
     * Closes the internal ICE connection and releases resources.
     *
     * @return void
     * @throws RandomException
     * @throws Throwable
     */
    public function close(): void
    {
        $this->iceConnection->close();
    }

    /**
     * Manually replaces the internal ICE connection.
     *
     * @param RTCIceConnectionInterface $iceConnection The new ICE connection instance.
     *
     * @return void
     */
    public function setIceConnection(RTCIceConnectionInterface $iceConnection): void
    {
        $this->iceConnection = $iceConnection;
    }
}

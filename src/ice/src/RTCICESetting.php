<?php

namespace Webrtc\ICE;

use Webrtc\Exception\InvalidArgumentException;
use Webrtc\ICE\Enum\IceRole;
use Webrtc\ICE\Enum\TransportPolicyType;

class RTCICESetting
{
    private ?array $icePortRange = null;
    private TransportPolicyType $transportPolicy = TransportPolicyType::ALL;
    private ?array $nat1to1 = null;

    /** @var string[]|null Local addresses to gather host candidates on, or null for every one of them */
    private ?array $interfaces = null;
    private bool $iceLite = false;
    // it will be changed for only testing purpose
    private IceRole $iceRole = IceRole::Controlling;

    public function getIcePortRange(): ?array
    {
        return $this->icePortRange;
    }

    public function setIcePortRange(int $minPort, int $maxPort): void
    {
        if ($maxPort- $minPort < 100) {
            throw new InvalidArgumentException("maxPort - minPort must be greater than 100");
        }

        if ($minPort < 1024 || $maxPort > 65535 || $minPort > $maxPort) {
            throw new InvalidArgumentException("Invalid port range [$minPort, $maxPort]");
        }

        $this->icePortRange = [$minPort, $maxPort];
    }

    public function getTransportPolicy(): TransportPolicyType
    {
        return $this->transportPolicy;
    }

    public function setTransportPolicy(TransportPolicyType $transportPolicy): void
    {
        $this->transportPolicy = $transportPolicy;
    }

    public function getNat1to1(): ?array
    {
        return $this->nat1to1;
    }

    /**
     * The local addresses host candidates may be gathered on.
     *
     * Every address costs a socket per connection, and a machine usually has several the peer can
     * never reach - a container bridge, a VPN, a second NIC on another segment. Naming the ones that
     * matter keeps the descriptor count, the size of the SDP and the number of connectivity checks
     * down to what is actually useful.
     *
     * @return string[]|null
     */
    public function getInterfaces(): ?array
    {
        return $this->interfaces;
    }

    /**
     * @param string[]|null $interfaces null gathers on every local address
     */
    public function setInterfaces(?array $interfaces): void
    {
        $this->interfaces = $interfaces === null || $interfaces === [] ? null : array_values($interfaces);
    }

    public function setNat1to1(?array $nat1to1): void
    {
        $this->nat1to1 = $nat1to1;
    }

    public function isIceLite(): bool
    {
        return $this->iceLite;
    }

    public function setIceLite(bool $iceLite): void
    {
        $this->iceLite = $iceLite;
    }

    public function getIceRole(): IceRole
    {
        return $this->iceRole;
    }

    public function setIceRole(IceRole $iceRole): void
    {
        $this->iceRole = $iceRole;
    }

}
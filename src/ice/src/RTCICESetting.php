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
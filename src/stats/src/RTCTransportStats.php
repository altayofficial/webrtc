<?php

/**
 * This file is part of the PHP WebRTC package.
 *
 * (c) Amin Yazdanpanah <https://www.aminyazdanpanah.com/#contact>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webrtc\Stats;

use Webrtc\Stats\enum\TLSState;
use Webrtc\ICE\Enum\IceRole;
use Webrtc\Stats\enum\StatType;

class RTCTransportStats extends RTCStats
{
    public function __construct(
        public string $id,
        public IceRole $role = IceRole::Controlling,
        public int $bytesSent = 0,
        public int $bytesReceived = 0,
        public int $packetsSent = 0,
        public int $packetsReceived = 0,
        public TLSState $state = TLSState::NEW,
    )
    {
        parent::__construct($id, StatType::Transport);
    }

    public function addBytesSent(int $bytesSent): void
    {
        $this->bytesSent += $bytesSent;
    }

    public function addBytesReceived(int $bytesReceived): void
    {
        $this->bytesReceived += $bytesReceived;
    }

    public function addPacketsSent(int $packetsSent = 1): void
    {
        $this->packetsSent += $packetsSent;
    }

    public function addPacketsReceived(int $packetsReceived = 1): void
    {
        $this->packetsReceived += $packetsReceived;
    }

    public function getState(): TLSState
    {
        return $this->state;
    }

    public function setState(TLSState $state): void
    {
        $this->state = $state;
    }

    public function getRole(): IceRole
    {
        return $this->role;
    }

    public function setRole(IceRole $role): void
    {
        $this->role = $role;
    }

    public function handleReceived(string $data): void
    {
        $this->addBytesReceived(strlen($data));
        $this->addPacketsReceived();
    }

    public function handleSent(string $data): void
    {
        $this->addBytesSent(strlen($data));
        $this->addPacketsSent();
    }
}
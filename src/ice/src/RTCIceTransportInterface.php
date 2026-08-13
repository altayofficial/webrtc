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

use Evenement\EventEmitterInterface;
use React\Promise\PromiseInterface;
use Webrtc\Exception\InvalidArgumentException;
use Webrtc\ICE\Enum\IceRole;

interface RTCIceTransportInterface extends EventEmitterInterface
{
    public function send(string $bytes);

    public function getRole(): IceRole;

    public function addRemoteCandidate(RTCIceCandidate $candidate): void;

    public function getIceGatherer(): RTCIceGathererInterface;

    public function isRoleSet(): bool;

    public function setRoleSet(bool $roleSet): void;

    public function getIceConnection(): RTCIceConnectionInterface;

    public function start(RTCIceParameters $remoteIceParameters): PromiseInterface;

    public function stop(): void;
}
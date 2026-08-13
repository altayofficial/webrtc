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

use React\Promise\PromiseInterface;
use Throwable;
use Webrtc\ICE\Enum\IceRole;
use Webrtc\STUN\IceConnectionProtocolInterface;
use Webrtc\STUN\Message\MessageInterface;

interface RTCIceConnectionInterface
{
    public function getIceRole(): IceRole;
    public function isUseIPv4(): bool;

    public function setUseIPv4(bool $useIPv4): void;

    public function isUseIPv6(): bool;

    public function setUseIPv6(bool $useIPv6): void;

    public function getLocalUsername(): string;

    public function setLocalUsername(string $localUsername): void;

    public function getLocalPassword(): string;

    public function setLocalPassword(string $localPassword): void;

    public function getLocalCandidates(): array;

    public function setLocalCandidates(array $localCandidates): void;

    public function getRemoteCandidates(): array;

    public function setRemoteCandidates(array $remoteCandidates): void;

    public function getRemoteUsername(): ?string;

    public function setRemoteUsername(?string $remoteUsername): void;

    public function getRemotePassword(): ?string;

    public function setRemotePassword(?string $remotePassword): void;

    public function getComponentIds(): array;

    public function setComponentIds(array $componentIds): void;

    public function isRemoteIsLite(): bool;

    public function setRemoteIsLite(bool $remoteIsLite): void;

    public function gatherCandidates(): void;

    public function addRemoteCandidate(RTCIceCandidate $remoteCandidate): void;

    public function endOfRemoteCandidate(): void;

    public function connect(): PromiseInterface;

    public function unfreezeChecks(): void;

    public function checkIncoming(MessageInterface $message, string $address, IceConnectionProtocolInterface $protocol): void;

    public function completeCheckAction(RTCIceCandidatePair $pair): void;

    public function periodicConsentCheck(): void;

    public function close(): void;

    public function sendData(string $data, int $componentId = 1): void;

    public function onDataReceived(string $data, int $componentId): void;

    public function onRequestReceived(MessageInterface $message, string $address, IceConnectionProtocolInterface $protocol, string $data): void;

    public function respondError(MessageInterface $orgMessage, string $address, IceConnectionProtocolInterface $protocol, array $errorCode): void;

    public function onClose(): void;

    public function onError(Throwable $e): void;

    public function setIceRole(IceRole $iceRole): void;

    public function isRemoteCandidatesEnd(): bool;
}